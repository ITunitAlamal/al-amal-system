<?php

namespace App\Services\Orphans;

use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * «الإحالة اليدوية» (requirement Q33, option A): staff note that a family or child was referred to an outside party,
 * with a responsible staff member who follows it and records the outcome. A note with an owner, not a referral
 * engine: no workflow, no external integration, nothing is sent outside AIMS.
 */
final class OrphanReferrals
{
    private const COLUMNS = ['f.id', 'f.referred_to', 'f.reason', 'f.referred_on', 'f.status', 'f.outcome', 'f.closed_at', 'f.responsible_user_id', 'f.created_by',
        'r.name as responsible', 'c.name as closed_by', 'h.reference_no as household', 'h.public_id as household_public_id', 'k.public_id as child'];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('orphan_referrals');
    }

    public function canRecord(?User $user): bool
    {
        return $this->access->allows($user, 'records') || $this->access->allows($user, 'visits');
    }

    /**
     * Who may be made responsible: today's heads and department staff who can view the files.
     *
     * @return list<array{id: int, name: string}>
     */
    public function responsibleOptions(): array
    {
        $ids = $this->access->departmentUsersWith('view');

        return DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name])->all();
    }

    /** @param array<string, mixed> $input */
    public function record(User $actor, OrphanHousehold $household, array $input): int
    {
        abort_unless($this->canRecord($actor), 403, 'تسجيل الإحالة لموظفي القسم المكلفين بالملفات أو الزيارات.');
        $responsible = array_column($this->responsibleOptions(), 'id');
        if (! in_array((int) $actor->id, $responsible, true) && ! $actor->isSystemAdmin()) {
            $responsible[] = (int) $actor->id;
        }
        $data = Validator::make($input, [
            'referred_to' => ['required', 'string', 'min:3', 'max:200'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'referred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'responsible_user_id' => ['required', 'integer', Rule::in($responsible)],
            'child_public_id' => ['nullable', 'string', Rule::exists('orphan_children', 'public_id')->where('household_id', $household->id)],
        ], [], ['referred_to' => 'الجهة المحال إليها', 'reason' => 'سبب الإحالة', 'referred_on' => 'تاريخ الإحالة', 'responsible_user_id' => 'المسؤول عن المتابعة',
            'child_public_id' => 'الطفل'])->validate();
        $childId = ! empty($data['child_public_id']) ? (int) DB::table('orphan_children')->where('public_id', $data['child_public_id'])->value('id') : null;

        return DB::transaction(function () use ($actor, $household, $data, $childId): int {
            $id = DB::table('orphan_referrals')->insertGetId(['household_id' => $household->id, 'orphan_child_id' => $childId, 'referred_to' => trim($data['referred_to']),
                'reason' => trim($data['reason']), 'referred_on' => $data['referred_on'], 'responsible_user_id' => (int) $data['responsible_user_id'], 'status' => 'open',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.referral.recorded', 'orphan_referrals', $id, null, ['household' => $household->reference_no, 'referred_to' => trim($data['referred_to']),
                'responsible' => (int) $data['responsible_user_id']], $actor->id, request()?->ip());
            if ((int) $data['responsible_user_id'] !== (int) $actor->id) {
                $this->notifications->notify((int) $data['responsible_user_id'], 'orphans_referral_assigned', ['title' => 'إحالة مسندة إليك لمتابعتها — ملف '.$household->reference_no,
                    'body' => 'إلى: '.trim($data['referred_to']), 'url' => '/orphans/households/'.$household->public_id, 'dedupe' => 'orphan_referral:'.$id]);
            }

            return $id;
        });
    }

    public function close(User $actor, int $referralId, string $outcome): void
    {
        $outcome = trim($outcome);
        DB::transaction(function () use ($actor, $referralId, $outcome): void {
            $row = DB::table('orphan_referrals')->where('id', $referralId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array((int) $actor->id, [(int) $row->responsible_user_id, (int) $row->created_by], true) || $this->access->isHead($actor), 403,
                'يسجل النتيجة المسؤول عن المتابعة أو من سجل الإحالة أو رئيس القسم.');
            Validator::make(['outcome' => $outcome, 'status' => $row->status], ['outcome' => ['required', 'string', 'min:5', 'max:2000'], 'status' => ['in:open']],
                ['status.in' => 'أُغلقت هذه الإحالة مسبقًا.'], ['outcome' => 'نتيجة الإحالة'])->validate();
            DB::table('orphan_referrals')->where('id', $referralId)->update(['status' => 'closed', 'outcome' => $outcome, 'closed_by' => $actor->id, 'closed_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.referral.closed', 'orphan_referrals', $referralId, ['status' => 'open'], ['status' => 'closed', 'outcome' => $outcome], $actor->id, request()?->ip());
        });
    }

    /** @return list<array<string, mixed>> */
    public function forHousehold(int $householdId, User $viewer): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->query()->where('f.household_id', $householdId)->orderByRaw("f.status = 'closed'")->orderByDesc('f.referred_on')->limit(50)->get(self::COLUMNS)
            ->map(fn (object $row): array => $this->present($row, $viewer))->all();
    }

    /** @return list<array<string, mixed>> open referrals across the department, oldest first */
    public function open(User $viewer): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->query()->where('f.status', 'open')->orderBy('f.referred_on')->limit(100)->get(self::COLUMNS)->map(fn (object $row): array => $this->present($row, $viewer))->all();
    }

    private function query(): Builder
    {
        return DB::table('orphan_referrals as f')->join('orphan_households as h', 'h.id', '=', 'f.household_id')->leftJoin('users as r', 'r.id', '=', 'f.responsible_user_id')
            ->leftJoin('users as c', 'c.id', '=', 'f.closed_by')->leftJoin('orphan_children as k', 'k.id', '=', 'f.orphan_child_id');
    }

    /** @return array<string, mixed> */
    private function present(object $row, User $viewer): array
    {
        return ['id' => (int) $row->id, 'referred_to' => $row->referred_to, 'reason' => $row->reason, 'referred_on' => (string) $row->referred_on, 'status' => $row->status,
            'status_label' => $row->status === 'open' ? 'قيد المتابعة' : 'مغلقة', 'outcome' => $row->outcome, 'closed_at' => $row->closed_at ? (string) $row->closed_at : null,
            'responsible' => $row->responsible, 'closed_by' => $row->closed_by, 'household' => $row->household, 'household_public_id' => $row->household_public_id, 'child' => $row->child,
            'can_close' => $row->status === 'open' && (in_array((int) $viewer->id, [(int) $row->responsible_user_id, (int) $row->created_by], true) || $this->access->isHead($viewer))];
    }
}
