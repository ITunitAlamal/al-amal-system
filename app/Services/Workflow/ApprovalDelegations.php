<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\Authority;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative delegations issued by the executive director (centralization, owner decisions of 2026-10-22).
 *
 * A workflow step opts in with "delegation_scope" in its assignment_config. While a delegation for that scope
 * is in force, the step goes to the delegate instead of its normal holders. A delegation is in force only while:
 * it is active, today is inside its period, the delegate is active, and its issuer still holds the executive
 * director role. Only the executive director issues or revokes one; the system admin cannot activate it.
 *
 * When a delegation is revoked or its period ends, the open requests it holds go back to the step's normal
 * holders, with a timeline event, so nothing waits on someone who no longer has the authority. When the step has
 * no other holder, OpenStepRerouter records that it waits with nobody and alerts the administration.
 */
final class ApprovalDelegations
{
    /** @var array<string, string> scope code => Arabic label */
    public const SCOPES = [
        'leave.final_approval' => 'الاعتماد النهائي لإجازات الموظفين بعد توصية رئيس القسم',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    /**
     * The person who holds the delegated authority for the scope today, or null (it stays with the normal holders).
     */
    public function activeDelegate(string $scope): ?int
    {
        $today = now()->toDateString();
        $delegateId = DB::table('approval_delegations as d')
            ->join('users as delegate', 'delegate.id', '=', 'd.delegate_user_id')
            ->where('d.scope_code', $scope)
            ->where('d.status', 'active')
            ->where('d.starts_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('d.ends_on')->orWhere('d.ends_on', '>=', $today))
            ->where('delegate.is_active', true)
            // Its issuer is still the executive director in office (Organization\Authority).
            ->whereIn('d.delegator_user_id', app(Authority::class)->executiveIds())
            ->value('d.delegate_user_id');

        return $delegateId === null ? null : (int) $delegateId;
    }

    /** The executive director in office (Organization\Authority). */
    public function isExecutive(User $user): bool
    {
        return app(Authority::class)->isExecutive($user);
    }

    /**
     * @return Collection<int, object>
     */
    public function list(?int $delegateUserId = null): Collection
    {
        return DB::table('approval_delegations as d')
            ->join('users as delegator', 'delegator.id', '=', 'd.delegator_user_id')
            ->join('users as delegate', 'delegate.id', '=', 'd.delegate_user_id')
            ->leftJoin('users as revoker', 'revoker.id', '=', 'd.revoked_by')
            ->when($delegateUserId !== null, fn ($query) => $query->where('d.delegate_user_id', $delegateUserId))
            ->orderByDesc('d.id')
            ->limit(200)
            ->get(['d.*', 'delegator.name as delegator_name', 'delegate.name as delegate_name', 'revoker.name as revoker_name']);
    }

    /**
     * @param  array{scope_code: string, delegate_user_id: int, starts_on: string, ends_on?: ?string, decision_reference: string, note?: ?string}  $data
     */
    public function issue(User $issuer, array $data, ?string $ipAddress = null): int
    {
        abort_unless($this->isExecutive($issuer), 403, 'إصدار التفويض الإداري من صلاحية المدير التنفيذي فقط.');
        if (! array_key_exists($data['scope_code'], self::SCOPES)) {
            throw ValidationException::withMessages(['scope_code' => 'نطاق التفويض غير معروف.']);
        }
        if ((int) $data['delegate_user_id'] === (int) $issuer->id) {
            throw ValidationException::withMessages(['delegate_user_id' => 'لا يجوز التفويض لنفسك.']);
        }
        if (! DB::table('users')->where('id', $data['delegate_user_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['delegate_user_id' => 'المفوَّض يجب أن يكون مستخدمًا نشطًا.']);
        }

        $this->closeLapsed($data['scope_code']);

        $id = DB::transaction(function () use ($issuer, $data, $ipAddress): int {
            $current = DB::table('approval_delegations')->where('scope_code', $data['scope_code'])->where('status', 'active')->lockForUpdate()->first();
            if ($current) {
                throw ValidationException::withMessages(['scope_code' => 'يوجد تفويض ساري لهذا النطاق؛ ألغِه أولًا ثم أصدر التفويض الجديد.']);
            }
            $row = [
                'scope_code' => $data['scope_code'],
                'delegator_user_id' => $issuer->id,
                'delegate_user_id' => (int) $data['delegate_user_id'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'decision_reference' => $data['decision_reference'],
                'note' => $data['note'] ?? null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = DB::table('approval_delegations')->insertGetId($row);
            $this->audit->recordRow('approval_delegation.issued', 'approval_delegations', $id, null, $row, $issuer->id, $ipAddress);
            $this->notifications->notify((int) $data['delegate_user_id'], 'approval_delegation_issued', [
                'title' => 'فوّضك المدير التنفيذي: '.self::SCOPES[$data['scope_code']],
                'body' => 'من '.$data['starts_on'].(($data['ends_on'] ?? null) ? ' إلى '.$data['ends_on'] : ' حتى إلغائه').' · مرجع القرار: '.$data['decision_reference'],
                'url' => route('admin.approval-delegations.index', [], false),
                'dedupe' => 'delegation:'.$id.':issued',
            ]);

            return $id;
        });

        return $id;
    }

    /**
     * @return int number of open requests returned to their normal holders
     */
    public function revoke(User $actor, int $delegationId, string $reason, ?string $ipAddress = null): int
    {
        abort_unless($this->isExecutive($actor), 403, 'إلغاء التفويض الإداري من صلاحية المدير التنفيذي فقط.');

        return DB::transaction(function () use ($actor, $delegationId, $reason, $ipAddress): int {
            $delegation = DB::table('approval_delegations')->where('id', $delegationId)->lockForUpdate()->first();
            abort_unless($delegation, 404);
            if ($delegation->status !== 'active') {
                throw ValidationException::withMessages(['delegation' => 'التفويض ملغى من قبل.']);
            }

            return $this->close($delegation, $actor->id, $reason, $ipAddress);
        });
    }

    /**
     * Closes delegations that are no longer in force (period ended, delegate deactivated, another executive
     * director in office, or, while the seat is vacant, the issuer lost his role or account) and returns their
     * open requests. Run by the scheduled workflow check.
     *
     * @return int number of delegations closed
     */
    public function closeLapsed(?string $scope = null): int
    {
        $today = now()->toDateString();
        $executiveIds = app(Authority::class)->executiveIds();
        $lapsed = DB::table('approval_delegations as d')
            ->join('users as delegate', 'delegate.id', '=', 'd.delegate_user_id')
            ->where('d.status', 'active')
            ->when($scope !== null, fn ($query) => $query->where('d.scope_code', $scope))
            ->where(fn ($query) => $query
                ->where('d.ends_on', '<', $today)
                ->orWhere('delegate.is_active', false)
                // Another executive director is in office (handover): the outgoing one's delegations lapse.
                ->when($executiveIds !== [], fn ($issuer) => $issuer->orWhereNotIn('d.delegator_user_id', $executiveIds))
                // The seat is vacant: activeDelegate() already suspends the delegate, so lapse permanently only
                // when the issuer lost his own executive director role or his account, not on a seat gap alone.
                ->when($executiveIds === [], fn ($issuer) => $issuer->orWhereNotExists(fn ($role) => $role->selectRaw('1')
                    ->from('department_user_roles as a')
                    ->join('roles as r', 'r.id', '=', 'a.role_id')
                    ->join('users as u', 'u.id', '=', 'a.user_id')
                    ->whereColumn('a.user_id', 'd.delegator_user_id')
                    ->where('r.code', 'executive_director')
                    ->whereRaw(EffectiveAssignments::sql('a'))
                    ->where('u.is_active', true))))
            ->pluck('d.id');

        foreach ($lapsed as $id) {
            DB::transaction(function () use ($id): void {
                $delegation = DB::table('approval_delegations')->where('id', $id)->lockForUpdate()->first();
                if ($delegation && $delegation->status === 'active') {
                    $this->close($delegation, null, 'انتهى سريان التفويض', null);
                }
            });
        }

        return $lapsed->count();
    }

    /**
     * Whether the user acts on the instance's current step only through a delegation that is no longer in force.
     */
    public function actsOnLapsedDelegation(object $instance, object $step, int $userId): bool
    {
        $scope = app(AssigneeResolver::class)->config($step)['delegation_scope'] ?? null;
        if (! $scope || $this->activeDelegate((string) $scope) === $userId) {
            return false;
        }

        return ! in_array($userId, app(AssigneeResolver::class)->candidates($step, $instance, null, false), true);
    }

    private function close(object $delegation, ?int $actorId, string $reason, ?string $ipAddress): int
    {
        DB::table('approval_delegations')->where('id', $delegation->id)->update([
            'status' => 'revoked', 'revoked_by' => $actorId, 'revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now(),
        ]);
        $returned = $this->returnOpenRequests($delegation, $actorId, $reason);
        $this->audit->recordRow('approval_delegation.revoked', 'approval_delegations', (int) $delegation->id,
            ['status' => 'active'], ['status' => 'revoked', 'reason' => $reason, 'returned_requests' => $returned], $actorId, $ipAddress);
        $this->notifications->notify((int) $delegation->delegate_user_id, 'approval_delegation_revoked', [
            'title' => 'انتهى تفويضك: '.(self::SCOPES[$delegation->scope_code] ?? $delegation->scope_code),
            'body' => $reason.($returned ? ' · أُعيدت '.$returned.' معاملة مفتوحة إلى صاحب الصلاحية الأصلي.' : ''),
            'url' => route('admin.approval-delegations.index', [], false),
            'dedupe' => 'delegation:'.$delegation->id.':revoked',
        ]);

        return $returned;
    }

    /**
     * Open requests waiting at a step of this scope with the delegate go back to the step's normal holders.
     */
    private function returnOpenRequests(object $delegation, ?int $actorId, string $reason): int
    {
        $assignees = app(AssigneeResolver::class);
        $instances = DB::table('workflow_instances as i')
            ->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('workflow_participants as p', 'p.workflow_instance_id', '=', 'i.id')
            ->where('i.status', 'in_progress')
            ->where('p.user_id', $delegation->delegate_user_id)
            ->where('p.participation_type', 'assignee')
            ->where('p.is_active', true)
            ->whereRaw("(s.assignment_config::jsonb ->> 'delegation_scope') = ?", [$delegation->scope_code])
            ->lockForUpdate()
            ->get(['i.*']);

        $now = now();
        $returned = 0;
        $unheld = [];
        foreach ($instances as $instance) {
            $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
            $holders = $assignees->candidates($step, $instance, null, false);
            if ($holders === []) {
                $unheld[] = (int) $instance->id; // nobody else holds the step: recorded and alerted below

                continue;
            }
            $returned++;
            DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)->where('user_id', $delegation->delegate_user_id)
                ->where('participation_type', 'assignee')->update(['is_active' => false, 'revoked_at' => $now, 'revoked_by' => $actorId, 'updated_at' => $now]);
            foreach ($holders as $userId) {
                $existing = DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)->where('user_id', $userId)->where('participation_type', 'assignee')->first();
                $existing
                    ? DB::table('workflow_participants')->where('id', $existing->id)->update(['is_active' => true, 'visibility_scope' => 'operational', 'revoked_at' => null, 'revoked_by' => null, 'updated_at' => $now])
                    : DB::table('workflow_participants')->insert([
                        'workflow_instance_id' => $instance->id, 'user_id' => $userId, 'participation_type' => 'assignee',
                        'visibility_scope' => 'operational', 'is_active' => true, 'added_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
            }
            $revision = (int) $instance->revision + 1;
            DB::table('workflow_instances')->where('id', $instance->id)->update([
                'current_assignee_id' => count($holders) === 1 ? $holders[0] : null, 'revision' => $revision, 'updated_at' => $now,
            ]);
            DB::table('workflow_events')->insert([
                'workflow_instance_id' => $instance->id, 'workflow_transition_id' => null,
                'from_step_id' => $instance->current_step_id, 'to_step_id' => $instance->current_step_id,
                'actor_id' => $actorId, 'assigned_to_id' => count($holders) === 1 ? $holders[0] : null,
                'action' => 'delegation_returned', 'comment' => $reason,
                'metadata' => json_encode(['delegation_id' => $delegation->id, 'from_user_id' => $delegation->delegate_user_id, 'to_user_ids' => $holders], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now, 'created_at' => $now,
            ]);
            $this->notifications->notifyMany($holders, 'workflow_task_assigned', [
                'title' => 'أُعيدت إليك معاملة بعد انتهاء التفويض: '.$step->name,
                'body' => (string) ($instance->reference_no ?? ''),
                'url' => route('workflows.show', ['publicId' => $instance->public_id], false),
                'dedupe' => 'wf:'.$instance->id.':r'.$revision.':delegation',
            ]);
        }
        // The delegate no longer qualifies and nobody else holds the step: the rerouter takes the step from the
        // delegate, records that it waits with no holder and alerts the administration (never auto-approved).
        if ($unheld !== []) {
            app(OpenStepRerouter::class)->reroute($unheld, $reason, $actorId);
        }

        return $returned;
    }
}
