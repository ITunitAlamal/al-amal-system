<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Exceptional access to one confidential or restricted record (owner decision 3, 2026-11-27): an official,
 * specific, time-bound grant the executive director issues from the record's page — one record, one person, a
 * written reason and an end date. It opens the record for reading only (CorrespondenceAccess::visible), ends by
 * itself, can be revoked at any time, and lapses when its issuer no longer holds the office. Issuing, revoking and
 * every use are audited.
 */
final class ConfidentialGrants
{
    public function __construct(
        private readonly CorrespondenceAccess $access,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function installed(): bool
    {
        return Schema::hasTable('correspondence_confidential_grants');
    }

    /** Whether the user may issue and revoke grants on this record: the executive director who sees it himself. */
    public function canManage(User $user, object $letter): bool
    {
        return $this->installed() && $user->is_active && $this->access->isConfidential($letter)
            && $this->access->role($user, 'executive_director')
            && $this->access->visible($user, false)->where('c.id', $letter->id)->exists();
    }

    /** @return list<array<string, mixed>> the record's grants, newest first, for the executive director's dialog */
    public function listFor(object $letter): array
    {
        return DB::table('correspondence_confidential_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->join('users as i', 'i.id', '=', 'g.issued_by')->where('g.correspondence_id', $letter->id)
            ->orderByDesc('g.id')->limit(50)->get(['g.id', 'g.reason', 'g.ends_at', 'g.revoked_at', 'g.revoke_reason', 'g.created_at', 'u.name as user_name', 'i.name as issuer_name'])
            ->map(fn (object $row): array => [...(array) $row, 'state' => $row->revoked_at ? 'ملغى' : (Carbon::parse($row->ends_at)->isPast() ? 'منتهٍ' : 'ساري'),
                'can_revoke' => ! $row->revoked_at && ! Carbon::parse($row->ends_at)->isPast()])->all();
    }

    /** @return list<array{id: int, name: string}> active accounts the director may choose from */
    public function candidates(User $director): array
    {
        return DB::table('users')->where('is_active', true)->where('id', '<>', $director->id)->orderBy('name')->limit(500)
            ->get(['id', 'name'])->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name])->all();
    }

    /** @param  array<string, mixed>  $input */
    public function issue(User $director, string $publicId, array $input, ?string $ip): void
    {
        abort_unless($this->installed(), 503, 'لم يُثبَّت سجل الاطلاع الاستثنائي بعد.');
        $data = Validator::make($input, [
            'user_id' => ['required', 'integer'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['user_id' => 'الموظف', 'ends_on' => 'تاريخ الانتهاء', 'reason' => 'سبب الاطلاع'])->validate();

        DB::transaction(function () use ($director, $publicId, $data, $ip): void {
            $letter = DB::table('official_correspondences')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($letter && $this->canManage($director, $letter), 403, 'يمنح الاطلاع الاستثنائي على المراسلة السرية أو المقيدة المديرُ التنفيذي المطّلع عليها فقط.');
            $grantee = User::query()->whereKey((int) $data['user_id'])->where('is_active', true)->first();
            if (! $grantee || (int) $grantee->id === (int) $director->id) {
                throw ValidationException::withMessages(['user_id' => 'اختر حسابًا نشطًا غير حسابك.']);
            }
            if ($this->access->visible($grantee, false)->where('c.id', $letter->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'هذا الموظف يطّلع على المراسلة أصلًا ضمن تداولها المعتمد.']);
            }
            if ($this->access->grantFor($grantee, (int) $letter->id) !== null) {
                throw ValidationException::withMessages(['user_id' => 'لهذا الموظف اطلاع استثنائي ساري على المراسلة نفسها.']);
            }
            $ends = Carbon::parse($data['ends_on'])->endOfDay();
            $id = (int) DB::table('correspondence_confidential_grants')->insertGetId([
                'correspondence_id' => $letter->id, 'user_id' => $grantee->id, 'issued_by' => $director->id,
                'reason' => trim((string) $data['reason']), 'ends_at' => $ends, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('correspondence.confidential_access.granted', 'correspondence_confidential_grants', $id, null, [
                'correspondence_id' => (int) $letter->id, 'user_id' => (int) $grantee->id, 'ends_at' => (string) $ends,
                'reason' => trim((string) $data['reason']), 'technical_account' => $grantee->isSystemAdmin(),
            ], (int) $director->id, $ip);
            $this->notifications->notify((int) $grantee->id, 'correspondence.assigned', [
                'title' => 'مُنحت اطلاعًا استثنائيًا على مراسلة رسمية', 'body' => $letter->reference.' — حتى نهاية '.$ends->toDateString(),
                'url' => route('correspondence.show', $letter->public_id, false), 'dedupe' => 'correspondence:grant:'.$id,
            ]);
        });
    }

    public function revoke(User $director, int $grant, string $reason, ?string $ip): void
    {
        abort_unless($this->installed(), 503, 'لم يُثبَّت سجل الاطلاع الاستثنائي بعد.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['revoke_reason' => 'اكتب سبب الإلغاء (5 أحرف على الأقل).']);
        }
        DB::transaction(function () use ($director, $grant, $reason, $ip): void {
            $row = DB::table('correspondence_confidential_grants')->where('id', $grant)->lockForUpdate()->first();
            abort_unless($row && $row->revoked_at === null, 404);
            $letter = DB::table('official_correspondences')->where('id', $row->correspondence_id)->first();
            abort_unless($letter && $this->canManage($director, $letter), 403, 'يلغي الاطلاع الاستثنائي المديرُ التنفيذي.');
            DB::table('correspondence_confidential_grants')->where('id', $grant)->update([
                'revoked_at' => now(), 'revoked_by' => $director->id, 'revoke_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->audit->recordRow('correspondence.confidential_access.revoked', 'correspondence_confidential_grants', $grant,
                ['revoked_at' => null], ['revoked_at' => (string) now(), 'reason' => $reason, 'correspondence_id' => (int) $row->correspondence_id], (int) $director->id, $ip);
        });
    }

    /** Audits a read made through a grant (open, print, attachment download). */
    public function recordUse(User $user, object $letter, int $grant, string $use, ?string $ip, array $detail = []): void
    {
        $this->audit->recordRow('correspondence.confidential_access.used', 'correspondence_confidential_grants', $grant, null,
            ['correspondence_id' => (int) $letter->id, 'use' => $use, ...$detail], (int) $user->id, $ip);
    }
}
