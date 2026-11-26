<?php

namespace App\Services\Orphans;

use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «وسم الطوارئ» (requirement Q32, option B): staff working a family file mark it as an emergency and the department
 * head is alerted. That is all it does: no workflow starts, nothing is approved and no payment or assistance is
 * created; urgent help still goes through the normal approvals. One open flag per family at a time; a flag is never
 * deleted, it is cleared by the head (or the executive director) with a note, so the history stays.
 */
final class OrphanEmergencies
{
    public function __construct(
        private readonly OrphanAccess $access,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('orphan_emergency_flags');
    }

    public function canRaise(?User $user): bool
    {
        return $this->access->allows($user, 'records') || $this->access->allows($user, 'visits');
    }

    public function canClear(?User $user): bool
    {
        return $this->access->isHead($user) || $this->access->isExecutive($user);
    }

    public function raise(User $actor, OrphanHousehold $household, ?string $childPublicId, string $reason): int
    {
        abort_unless($this->canRaise($actor), 403, 'وسم الطوارئ لموظفي القسم المكلفين بالملفات أو الزيارات.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الطوارئ بما يكفي ليفهمه رئيس القسم (10 أحرف على الأقل).']);
        }
        $childId = null;
        if ($childPublicId) {
            $childId = DB::table('orphan_children')->where('public_id', $childPublicId)->where('household_id', $household->id)->value('id');
            if (! $childId) {
                throw ValidationException::withMessages(['child_public_id' => 'الطفل المختار ليس من هذه الأسرة.']);
            }
        }

        return DB::transaction(function () use ($actor, $household, $childId, $reason): int {
            DB::table('orphan_households')->where('id', $household->id)->lockForUpdate()->first();
            if (DB::table('orphan_emergency_flags')->where('household_id', $household->id)->whereNull('cleared_at')->exists()) {
                throw ValidationException::withMessages(['reason' => 'يوجد وسم طوارئ قائم لهذا الملف؛ أضف المستجد إليه بعد رفعه أو اطلب من رئيس القسم مراجعته.']);
            }
            $heads = array_values(array_diff($this->access->headUserIds(), [(int) $actor->id]));
            $id = DB::table('orphan_emergency_flags')->insertGetId(['household_id' => $household->id, 'orphan_child_id' => $childId, 'reason' => $reason,
                'raised_by' => $actor->id, 'raised_at' => now(), 'alerted_user_ids' => json_encode($heads), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.emergency.raised', 'orphan_emergency_flags', $id, null, ['household' => $household->reference_no, 'child' => $childId, 'alerted' => $heads],
                $actor->id, request()?->ip());
            $this->notifications->notifyMany($heads, 'orphans_emergency_flagged', ['title' => 'حالة طوارئ في ملف الأسرة '.$household->reference_no,
                'body' => mb_substr($reason, 0, 200), 'url' => '/orphans/households/'.$household->public_id, 'dedupe' => 'orphan_emergency:'.$id]);

            return $id;
        });
    }

    public function clear(User $actor, int $flagId, string $note): void
    {
        abort_unless($this->canClear($actor), 403, 'رفع وسم الطوارئ لرئيس القسم أو المدير التنفيذي.');
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'اذكر ما انتهت إليه الحالة.']);
        }
        DB::transaction(function () use ($actor, $flagId, $note): void {
            $flag = DB::table('orphan_emergency_flags')->where('id', $flagId)->lockForUpdate()->first();
            abort_unless($flag, 404);
            if ($flag->cleared_at !== null) {
                throw ValidationException::withMessages(['note' => 'رُفع هذا الوسم مسبقًا.']);
            }
            DB::table('orphan_emergency_flags')->where('id', $flagId)->update(['cleared_by' => $actor->id, 'cleared_at' => now(), 'clear_note' => $note, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.emergency.cleared', 'orphan_emergency_flags', $flagId, ['cleared' => false], ['cleared' => true, 'note' => $note], $actor->id, request()?->ip());
            $household = DB::table('orphan_households')->where('id', $flag->household_id)->first(['reference_no', 'public_id']);
            $this->notifications->notifyMany(array_diff([...$this->access->headUserIds(), (int) $flag->raised_by], [(int) $actor->id]), 'orphans_emergency_flagged',
                ['title' => 'رُفع وسم الطوارئ عن ملف الأسرة '.$household->reference_no, 'body' => mb_substr($note, 0, 200),
                    'url' => '/orphans/households/'.$household->public_id, 'dedupe' => 'orphan_emergency:'.$flagId.':cleared']);
        });
    }

    /**
     * Flags of one family, the open one first.
     *
     * @return list<array<string, mixed>>
     */
    public function forHousehold(int $householdId): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('orphan_emergency_flags as f')->leftJoin('users as r', 'r.id', '=', 'f.raised_by')->leftJoin('users as c', 'c.id', '=', 'f.cleared_by')
            ->leftJoin('orphan_children as k', 'k.id', '=', 'f.orphan_child_id')->where('f.household_id', $householdId)
            ->orderByRaw('f.cleared_at IS NOT NULL')->orderByDesc('f.raised_at')->limit(20)
            ->get(['f.id', 'f.reason', 'f.raised_at', 'f.alerted_user_ids', 'f.cleared_at', 'f.clear_note', 'r.name as raised_by', 'c.name as cleared_by', 'k.public_id as child'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'reason' => $row->reason, 'raised_at' => (string) $row->raised_at, 'raised_by' => $row->raised_by,
                'alerted' => count(json_decode((string) $row->alerted_user_ids, true) ?: []), 'child' => $row->child, 'open' => $row->cleared_at === null,
                'cleared_at' => $row->cleared_at ? (string) $row->cleared_at : null, 'cleared_by' => $row->cleared_by, 'clear_note' => $row->clear_note])->all();
    }

    /** @return list<array<string, mixed>> open flags across the department, oldest first */
    public function open(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('orphan_emergency_flags as f')->join('orphan_households as h', 'h.id', '=', 'f.household_id')->leftJoin('users as r', 'r.id', '=', 'f.raised_by')
            ->whereNull('f.cleared_at')->orderBy('f.raised_at')->limit(100)
            ->get(['f.id', 'f.reason', 'f.raised_at', 'h.public_id', 'h.reference_no', 'r.name as raised_by'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'reason' => mb_substr((string) $row->reason, 0, 160), 'raised_at' => (string) $row->raised_at,
                'household' => $row->reference_no, 'household_public_id' => $row->public_id, 'raised_by' => $row->raised_by])->all();
    }
}
