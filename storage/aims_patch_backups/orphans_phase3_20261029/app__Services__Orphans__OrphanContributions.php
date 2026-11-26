<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One-off donations: a light donor identity (a registered sponsor or a name and contact) and an earmark. The
 * department records the pledge; only Accounts mark it collected, with the voucher of the accounting software.
 * A donation never creates a recurring sponsorship and never credits a child's savings by itself.
 */
final class OrphanContributions
{
    public const STATUS_LABELS = ['pledged' => 'تعهد بانتظار القبض', 'collected' => 'مقبوض لدى الحسابات', 'cancelled' => 'ملغى', 'refunded' => 'مُرد للمتبرع'];

    public const EARMARKS = ['general' => 'عام لبرامج القسم', 'child' => 'لطفل محدد', 'household' => 'لأسرة محددة', 'campaign' => 'لحملة إغاثية'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphanRegistry $registry, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): int
    {
        $this->access->assert($actor, 'sponsorships');
        $data = validator($input, [
            'sponsor_id' => ['nullable', 'integer', Rule::exists('sponsors', 'id')], 'donor_name' => ['nullable', 'required_without:sponsor_id', 'string', 'min:3', 'max:200'],
            'donor_contact' => ['nullable', 'string', 'max:200'], 'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'earmark_type' => ['required', Rule::in(array_keys(self::EARMARKS))], 'earmark_reference' => ['nullable', 'required_unless:earmark_type,general', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['donor_name' => 'اسم المتبرع', 'amount' => 'المبلغ', 'earmark_reference' => 'رقم الملف أو الحملة'])->validate();
        if ((float) $data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        $earmarkId = $this->earmark($data['earmark_type'], (string) ($data['earmark_reference'] ?? ''));

        return DB::transaction(function () use ($actor, $data, $earmarkId): int {
            $id = DB::table('orphan_contributions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('contribution_ref_seq', 'CON-'),
                'sponsor_id' => $data['sponsor_id'] ?? null, 'donor_name' => $data['donor_name'] ?? null,
                'donor_contact' => ! empty($data['donor_contact']) ? Crypt::encryptString($data['donor_contact']) : null,
                'amount' => number_format((float) $data['amount'], 2, '.', ''), 'currency' => strtoupper($data['currency']), 'earmark_type' => $data['earmark_type'],
                'earmark_id' => $earmarkId, 'status' => 'pledged', 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('orphans.contribution.pledged', 'orphan_contributions', $id, null, ['earmark' => $data['earmark_type'], 'currency' => strtoupper($data['currency'])],
                $actor->id, request()?->ip());

            return $id;
        });
    }

    /** A pledge that will not be paid is cancelled by whoever recorded it or the head (kept for the record). */
    public function cancel(User $actor, int $id, string $reason): void
    {
        $this->access->assert($actor, 'sponsorships');
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'السبب'])->validate();
        DB::transaction(function () use ($actor, $id, $reason): void {
            $row = DB::table('orphan_contributions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->created_by === (int) $actor->id || $this->access->isHead($actor), 403, 'يلغي التعهد من سجّله أو رئيس القسم.');
            if ($row->status !== 'pledged') {
                throw ValidationException::withMessages(['reason' => 'يُلغى التعهد الذي لم يُقبض فقط.']);
            }
            DB::table('orphan_contributions')->where('id', $id)->update(['status' => 'cancelled', 'notes' => trim(($row->notes ? $row->notes."\n" : '').'إلغاء: '.$reason), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.contribution.cancelled', 'orphan_contributions', $id, ['status' => 'pledged'], ['status' => 'cancelled'], $actor->id, request()?->ip());
        });
    }

    /** The earmarked record's label for screens (no child name for users without file access). */
    public function earmarkLabel(object $row): string
    {
        return match ($row->earmark_type) {
            'child' => 'طفل '.(DB::table('orphan_children')->where('id', $row->earmark_id)->value('file_no') ?? '—'),
            'household' => 'أسرة '.(DB::table('orphan_households')->where('id', $row->earmark_id)->value('reference_no') ?? '—'),
            'campaign' => 'حملة '.(DB::table('relief_campaigns')->where('id', $row->earmark_id)->value('reference_no') ?? '—'),
            default => self::EARMARKS['general'],
        };
    }

    private function earmark(string $type, string $reference): ?int
    {
        $reference = trim($reference);
        $id = match ($type) {
            'child' => DB::table('orphan_children')->where('file_no', $reference)->where('status', 'active')->value('id'),
            'household' => DB::table('orphan_households')->where('reference_no', $reference)->whereIn('status', ['registered', 'active'])->value('id'),
            'campaign' => DB::table('relief_campaigns')->where('reference_no', $reference)->whereIn('status', ['approved', 'active'])->value('id'),
            default => null,
        };
        if ($type !== 'general' && ! $id) {
            throw ValidationException::withMessages(['earmark_reference' => 'لا يوجد ملف نشط أو حملة معتمدة بهذا الرقم.']);
        }

        return $id ? (int) $id : null;
    }
}
