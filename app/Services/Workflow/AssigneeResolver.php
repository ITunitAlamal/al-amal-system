<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinancePermissionCatalog;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;

/**
 * Decides who may act on a workflow step, from the step's configuration only.
 *
 * No department or role is named in code. The requester is never a candidate for a review or approval step
 * (separation of duties, SRS §2.4.2). A purely operational step (classification, stores, execution) may opt in
 * with "requester_may_act", so an HR head's own maintenance request is still coordinated by HR instead of
 * falling to the executive director.
 *
 * When nobody holds the step, the configured alternates are tried in order: "fallback_role_code", then the head
 * of "fallback_department_id".
 *
 * A step owned by a department is supervised by that department's head, who may hand it to an employee
 * (StepAssignments). Flags read by the pages only: "assignable" (worth offering for assignment) and "field_work"
 * (waiting for work done outside the system to be documented).
 */
final class AssigneeResolver
{
    public const TYPES = [
        'requester' => 'مقدم الطلب',
        'department_head' => 'رئيس قسم مقدم الطلب',
        'department_head_of' => 'رئيس قسم محدد',
        'role' => 'حاملو دور محدد',
        'user' => 'مستخدم محدد',
        'pick' => 'يختاره منفذ المرحلة السابقة من حاملي دور',
        'actor_of_step' => 'من نفّذ مرحلة سابقة في الطلب نفسه',
    ];

    public const DEPARTMENT_SCOPES = [
        'origin' => 'ضمن قسم مقدم الطلب أو على مستوى المؤسسة',
        'specific' => 'ضمن قسم محدد أو على مستوى المؤسسة',
        'any' => 'في أي قسم',
    ];

    /** The Board treasurer's role: held through the appointment in Accounts setup (finance_board_approvers). */
    private const BOARD_TREASURER_ROLE = 'finance_board_treasurer';

    /** @var array<string, list<int>> interested parties per record, read once per resolver */
    private array $interested = [];

    public function __construct(
        private readonly ApprovalDelegations $delegations,
        private readonly WorkflowSubjects $subjects,
    ) {}

    /**
     * @param  bool  $useDelegation  false gives the step's normal holders, ignoring a delegation in force
     * @return list<int> user ids allowed to act; empty means nobody is eligible
     */
    public function candidates(object $step, object $instance, ?int $pickedUserId = null, bool $useDelegation = true): array
    {
        $config = $this->config($step);
        $requesterId = (int) $instance->requested_by;

        if ($step->assignment_type === 'requester') {
            return [$requesterId];
        }

        // A delegation issued by the executive director for this step's scope moves the step to the delegate.
        if ($useDelegation && ! empty($config['delegation_scope'])) {
            $delegate = $this->delegations->activeDelegate((string) $config['delegation_scope']);
            if ($delegate !== null && $delegate !== $requesterId && ! in_array($delegate, $this->interestedUserIds($instance), true)) {
                return [$delegate];
            }
        }

        $excluded = $this->excludedIds($step, $instance);
        $ids = match ($step->assignment_type) {
            'department_head' => $this->headFor((int) $instance->department_id, $excluded),
            'department_head_of' => $this->headFor((int) ($config['department_id'] ?? 0), $excluded),
            'role' => $this->roleHolders($step, $instance),
            'user' => $this->activeUsers([(int) ($config['user_id'] ?? 0)]),
            'pick' => $this->pickedHolder($step, $instance, $pickedUserId),
            'actor_of_step' => $this->activeUsers(array_filter([$this->actorOfStep($instance, (string) ($config['step_code'] ?? ''))])),
            default => [],
        };
        $ids = array_values(array_diff($ids, $excluded));

        // Nobody eligible (e.g. the requester heads the department, or nobody holds the role yet): use the alternates.
        if ($ids === [] && $step->assignment_type !== 'pick') {
            $ids = $this->fallbackHolders($config, $instance, $excluded);
        }

        return $ids;
    }

    /**
     * Whether the user still holds the authority behind their place on the step, checked at action time
     * (TRANSFORM §3.4): an active holder under the step's rules today, the delegate of a delegation still in
     * force, an escalation holder of an overdue step, the head who supervises the step, or the employee that same
     * head assigned it to while still qualified for it. A participant row alone is not enough: a lost role, an
     * ended appointment, a new head, a transfer or a lapsed delegation ends it.
     */
    public function stillQualifies(object $step, object $instance, User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->id : $user;
        $active = $user instanceof User ? (bool) $user->is_active
            : DB::table('users')->where('id', $userId)->where('is_active', true)->exists();
        if (! $active || ($step->step_type ?? null) === 'end') {
            return false;
        }
        if ($step->assignment_type === 'requester') {
            return $userId === (int) $instance->requested_by;
        }
        if (in_array($userId, $this->excludedIds($step, $instance), true)) {
            return false;
        }

        $config = $this->config($step);
        if (! empty($config['delegation_scope']) && $this->delegations->activeDelegate((string) $config['delegation_scope']) === $userId) {
            return true;
        }
        // A picked holder stays while they hold the step's role; anyone else must be a normal holder today.
        $holders = $step->assignment_type === 'pick' ? $this->roleHolders($step, $instance) : $this->candidates($step, $instance, null, false);
        if (in_array($userId, $holders, true) && ! $this->actorLostRole($step, $instance, $userId)) {
            return true;
        }
        $escalation = $config['escalate_to_role_code'] ?? null;
        if ($escalation && ($instance->sla_breached_at ?? null) !== null && in_array($userId, $this->roleHolders(
            (object) ['assigned_role_code' => $escalation, 'assignment_config' => ['department_scope' => 'any']], $instance), true)) {
            return true;
        }

        $supervisorId = $this->supervisor($step, $instance);
        if ($supervisorId === null) {
            return false;
        }
        if ($supervisorId === $userId) {
            return true;
        }

        // Assigned by the head who supervises the step now, and still among the people that head may assign it to.
        return (int) ($instance->current_step_id ?? 0) === (int) ($step->id ?? -1)
            && $this->assignedBy($instance, $step, $userId) === $supervisorId
            && in_array($userId, array_column(app(StepAssignments::class)->candidates($instance), 'id'), true);
    }

    /**
     * Who may never hold the step for this request: the requester (unless the step lets the requester act) and the
     * people the record concerns (WorkflowSubjects::interestedUserIds), e.g. the employee whose pay is changed.
     *
     * @return list<int>
     */
    public function excludedIds(object $step, object $instance): array
    {
        $ids = $this->interestedUserIds($instance);
        if (! $this->requesterMayAct($step)) {
            $ids[] = (int) $instance->requested_by;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function interestedUserIds(object $instance): array
    {
        if (! isset($instance->subject_type, $instance->subject_id)) {
            return [];
        }
        $key = $instance->subject_type.':'.$instance->subject_id;

        return $this->interested[$key] ??= $this->subjects->interestedUserIds($instance);
    }

    /**
     * An "actor_of_step" holder who has since lost the role of the step they acted on (as in previousHolder()).
     */
    private function actorLostRole(object $step, object $instance, int $userId): bool
    {
        if ($step->assignment_type !== 'actor_of_step' || $userId !== $this->actorOfStep($instance, (string) ($this->config($step)['step_code'] ?? ''))) {
            return false;
        }
        $qualified = $this->qualifiedHolders($step, $instance);

        return $qualified !== null && ! in_array($userId, $qualified, true);
    }

    /**
     * Who handed the current pass of the step to the user («إسناد إلى موظف»), or null.
     */
    private function assignedBy(object $instance, object $step, int $userId): ?int
    {
        $arrived = DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->where('to_step_id', $step->id)
            ->where(fn ($query) => $query->whereNotNull('workflow_transition_id')->orWhere('action', 'reopen'))->max('id');
        $actorId = DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->where('to_step_id', $step->id)
            ->where('action', StepAssignments::ACTION_ASSIGNED)->where('assigned_to_id', $userId)
            ->when($arrived !== null, fn ($query) => $query->where('id', '>', $arrived))
            ->orderByDesc('id')->value('actor_id');

        return $actorId === null ? null : (int) $actorId;
    }

    /**
     * Whether the step can be assigned to anyone at all today, ignoring who the requester is.
     * Used to flag published steps that would stop every request reaching them.
     */
    public function hasEligibleHolder(object $step): bool
    {
        if (in_array($step->step_type, ['start', 'end'], true) || in_array($step->assignment_type, ['requester', 'actor_of_step', 'department_head'], true)) {
            return true;
        }
        $config = $this->config($step);
        $probe = (object) ['id' => 0, 'requested_by' => 0, 'department_id' => 0];
        // A role held "in the requester's department" counts when anyone holds it at all.
        $anyScope = ($config['department_scope'] ?? 'origin') === 'origin'
            ? (object) ['assigned_role_code' => $step->assigned_role_code, 'assignment_config' => ['department_scope' => 'any']]
            : $step;
        $ids = match ($step->assignment_type) {
            'department_head_of' => $this->headFor((int) ($config['department_id'] ?? 0), []),
            'role', 'pick' => $this->roleHolders($anyScope, $probe),
            'user' => $this->activeUsers([(int) ($config['user_id'] ?? 0)]),
            default => [],
        };

        return $ids !== [] || ($step->assignment_type !== 'pick' && $this->fallbackHolders($config, $probe, []) !== []);
    }

    public function requesterMayAct(object $step): bool
    {
        return ! empty($this->config($step)['requester_may_act']);
    }

    /**
     * The department that supervises the step: the step's own department for "department_head_of", otherwise
     * "supervisor_department_id" (a step held by someone else, such as a picked technician). The requester's own
     * steps and steps that follow an executive delegation are never supervised.
     */
    public function supervisorDepartmentId(object $step): ?int
    {
        $config = $this->config($step);
        if (in_array($step->step_type ?? null, ['start', 'end'], true) || $step->assignment_type === 'requester' || ! empty($config['delegation_scope'])) {
            return null;
        }
        $departmentId = (int) ($step->assignment_type === 'department_head_of' ? ($config['department_id'] ?? 0) : ($config['supervisor_department_id'] ?? 0));

        return $departmentId > 0 ? $departmentId : null;
    }

    /**
     * The head who supervises the step for this request, resolved exactly as the holder of a "department_head_of"
     * step: never the requester, unless the step lets the requester act, and never a person the record concerns.
     */
    public function supervisor(object $step, object $instance): ?int
    {
        $departmentId = $this->supervisorDepartmentId($step);

        return $departmentId === null ? null
            : ($this->headFor($departmentId, $this->excludedIds($step, $instance))[0] ?? null);
    }

    /**
     * The active user who last acted on this step of the request, when the step may go back to them.
     * Correspondence keeps its own referral rules: its steps always need an explicit choice.
     */
    public function previousHolder(object $step, object $instance): ?int
    {
        if (in_array($instance->subject_type ?? null, ['official_correspondence', 'correspondence_edition'], true)) {
            return null;
        }
        $actorId = $this->actorOfStep($instance, (string) ($step->code ?? ''));
        if ($actorId === null || in_array($actorId, $this->excludedIds($step, $instance), true)) {
            return null;
        }
        // Only while they still qualify for the step: a holder of its role, or the head who supervises it (who
        // may have taken the step back and acted). Someone who has since lost the role is chosen again explicitly.
        $qualified = $this->qualifiedHolders($step, $instance);
        if ($qualified !== null && ! in_array($actorId, $qualified, true) && $actorId !== $this->supervisor($step, $instance)) {
            return null;
        }

        return $this->activeUsers([$actorId])[0] ?? null;
    }

    /**
     * The people who qualify for a step that names a role: holders of that role for a "role" or "pick" step, and,
     * for an "actor_of_step" step, holders of the role named by the step it takes its holder from. Null when the
     * step is held by position (a department head, the requester, a named user) rather than by role.
     *
     * @return list<int>|null
     */
    public function qualifiedHolders(object $step, object $instance): ?array
    {
        $source = $step;
        if ($step->assignment_type === 'actor_of_step') {
            $code = (string) ($this->config($step)['step_code'] ?? '');
            $source = $code === '' || ! isset($step->workflow_version_id) ? null
                : DB::table('workflow_steps')->where('workflow_version_id', $step->workflow_version_id)->where('code', $code)->first();
        }
        if ($source === null || ! in_array($source->assignment_type, ['role', 'pick'], true) || ! $source->assigned_role_code) {
            return null;
        }

        return $this->roleHolders($source, $instance);
    }

    /**
     * Whether someone other than the requester heads the department or one of its parents (the requester's actual superior).
     */
    public function hasSuperiorHead(int $departmentId, int $requesterId): bool
    {
        return $this->headFor($departmentId, [$requesterId]) !== [];
    }

    /**
     * People the actor can choose from when the next step is a "pick" step.
     *
     * @return list<array{id: int, name: string}>
     */
    public function pickOptions(object $step, object $instance): array
    {
        $ids = array_values(array_diff($this->roleHolders($step, $instance), $this->excludedIds($step, $instance)));
        // Whoever handled the step before stays selectable when the request comes back to it.
        $ids[] = $this->previousHolder($step, $instance) ?? 0;

        return DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => $user->name])->all();
    }

    /**
     * Configuration problems that would make the step impossible to assign.
     *
     * @param  list<string>|null  $roleCodes  Role catalog supplied by a single read-only batch.
     * @return list<string>
     */
    public function problems(object $step, ?array $roleCodes = null): array
    {
        if ($step->step_type === 'end') {
            return [];
        }

        $config = $this->config($step);
        $name = $step->name;
        $roleExists = fn (string $code): bool => $roleCodes === null
            ? DB::table('roles')->where('code', $code)->exists()
            : in_array($code, $roleCodes, true);

        if (! array_key_exists((string) $step->assignment_type, self::TYPES)) {
            return ["المرحلة «{$name}» تحتاج إلى تحديد المسؤول عنها."];
        }

        $problems = [];
        if ($step->step_type === 'start' && $step->assignment_type !== 'requester') {
            $problems[] = "مرحلة البداية «{$name}» يجب أن يكون مسؤولها مقدم الطلب.";
        }
        if (in_array($step->assignment_type, ['role', 'pick'], true)) {
            if (! $step->assigned_role_code || ! $roleExists($step->assigned_role_code)) {
                $problems[] = "المرحلة «{$name}» تحتاج إلى دور موجود.";
            }
            if (($config['department_scope'] ?? 'origin') === 'specific' && ! $this->departmentIsActive((int) ($config['department_id'] ?? 0))) {
                $problems[] = "المرحلة «{$name}» تحتاج إلى قسم نشط لتحديد نطاق الدور.";
            }
        }
        if ($step->assignment_type === 'department_head_of') {
            $departmentId = (int) ($config['department_id'] ?? 0);
            if (! $this->departmentIsActive($departmentId)) {
                $problems[] = "المرحلة «{$name}» تحتاج إلى قسم نشط.";
            } elseif ($this->validHead($departmentId) === null) {
                $problems[] = "القسم المحدد في المرحلة «{$name}» ليس له رئيس نشط بدور رئيس قسم.";
            }
        }
        if ($step->assignment_type === 'user' && $this->activeUsers([(int) ($config['user_id'] ?? 0)]) === []) {
            $problems[] = "المرحلة «{$name}» تحتاج إلى مستخدم نشط.";
        }
        if ($step->assignment_type === 'actor_of_step') {
            $sourceCode = (string) ($config['step_code'] ?? '');
            $sourceExists = $sourceCode !== '' && $sourceCode !== $step->code && DB::table('workflow_steps')
                ->where('workflow_version_id', $step->workflow_version_id)->where('code', $sourceCode)->exists();
            if (! $sourceExists) {
                $problems[] = "المرحلة «{$name}» تحتاج إلى تحديد مرحلة سابقة يُؤخذ منفذها.";
            }
        }
        if (! empty($config['fallback_role_code']) && ! $roleExists($config['fallback_role_code'])) {
            $problems[] = "الدور البديل في المرحلة «{$name}» غير موجود.";
        }
        if (! empty($config['fallback_department_id']) && ! $this->departmentIsActive((int) $config['fallback_department_id'])) {
            $problems[] = "القسم البديل في المرحلة «{$name}» غير نشط.";
        }
        if (! empty($config['supervisor_department_id']) && ! $this->departmentIsActive((int) $config['supervisor_department_id'])) {
            $problems[] = "القسم المشرف على المرحلة «{$name}» غير نشط.";
        }
        $informed = $config['inform_role_codes'] ?? [];
        if (! is_array($informed) || ($informed !== [] && ($roleCodes === null
            ? DB::table('roles')->whereIn('code', $informed)->count() !== count(array_unique($informed))
            : array_diff($informed, $roleCodes) !== []))) {
            $problems[] = "أدوار الإبلاغ في المرحلة «{$name}» غير صحيحة.";
        }
        if (! empty($config['delegation_scope']) && ! array_key_exists((string) $config['delegation_scope'], ApprovalDelegations::SCOPES)) {
            $problems[] = "نطاق التفويض في المرحلة «{$name}» غير معروف.";
        }
        if (! empty($config['delegation_scope']) && $step->assignment_type === 'requester') {
            $problems[] = "مرحلة مقدم الطلب «{$name}» لا تقبل التفويض.";
        }

        return $problems;
    }

    /**
     * The configured alternates, in order: holders of "fallback_role_code" anywhere, then the head of "fallback_department_id".
     *
     * @param  array<string, mixed>  $config
     * @param  list<int>  $excluded
     * @return list<int>
     */
    private function fallbackHolders(array $config, object $instance, array $excluded): array
    {
        if (! empty($config['fallback_role_code'])) {
            $fallback = (object) ['assigned_role_code' => $config['fallback_role_code'], 'assignment_config' => ['department_scope' => 'any']];
            $ids = array_values(array_diff($this->roleHolders($fallback, $instance), $excluded));
            if ($ids !== []) {
                return $ids;
            }
        }
        if (! empty($config['fallback_department_id'])) {
            return $this->headFor((int) $config['fallback_department_id'], $excluded);
        }

        return [];
    }

    /**
     * A "pick" step goes to the role holder the actor chose. When the request comes back to a step it already
     * passed and nobody else is chosen, it returns to whoever acted on that step before.
     *
     * @return list<int>
     */
    private function pickedHolder(object $step, object $instance, ?int $pickedUserId): array
    {
        $previous = $this->previousHolder($step, $instance);
        if ($pickedUserId === null || $pickedUserId === $previous) {
            return $previous === null ? [] : [$previous];
        }

        return in_array($pickedUserId, $this->roleHolders($step, $instance), true)
            && ! in_array($pickedUserId, $this->excludedIds($step, $instance), true) ? [$pickedUserId] : [];
    }

    /**
     * The user who most recently acted on the given step of this request.
     */
    private function actorOfStep(object $instance, string $stepCode): ?int
    {
        if ($stepCode === '' || ! isset($instance->id)) {
            return null;
        }

        $actorId = DB::table('workflow_events as e')
            ->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
            ->where('e.workflow_instance_id', $instance->id)
            ->where('s.code', $stepCode)
            ->whereNotNull('e.workflow_transition_id')
            ->orderByDesc('e.id')
            ->value('e.actor_id');

        return $actorId === null ? null : (int) $actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(object $step): array
    {
        $config = $step->assignment_config ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        return is_array($config) ? $config : [];
    }

    /**
     * The head of a department, or of the nearest parent department when the head is excluded (the requester, or a
     * person the record concerns: nobody approves their own request or their own file).
     *
     * @param  list<int>  $excluded
     * @return list<int>
     */
    private function headFor(int $departmentId, array $excluded): array
    {
        $visited = [];
        while ($departmentId > 0 && ! isset($visited[$departmentId])) {
            $visited[$departmentId] = true;
            $head = $this->validHead($departmentId);
            if ($head !== null && ! in_array($head, $excluded, true)) {
                return [$head];
            }
            $departmentId = (int) DB::table('departments')->where('id', $departmentId)->value('parent_id');
        }

        return [];
    }

    private function validHead(int $departmentId): ?int
    {
        $headId = DB::table('departments as d')
            ->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->join('department_user_roles as a', fn ($join) => $join->on('a.user_id', '=', 'u.id')->on('a.department_id', '=', 'd.id'))
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('d.id', $departmentId)
            ->where('d.is_active', true)
            ->whereNull('d.deleted_at')
            ->where('u.is_active', true)
            ->where('r.code', 'department_head')
            ->whereRaw(EffectiveAssignments::sql('a'))
            ->value('u.id');

        return $headId === null ? null : (int) $headId;
    }

    /**
     * @return list<int>
     */
    private function roleHolders(object $step, object $instance): array
    {
        if (! $step->assigned_role_code) {
            return [];
        }

        $config = $this->config($step);
        $scope = $config['department_scope'] ?? 'origin';
        $departmentId = match ($scope) {
            'specific' => (int) ($config['department_id'] ?? 0),
            'origin' => (int) $instance->department_id,
            default => null,
        };

        if (in_array($instance->subject_type ?? null, ['official_correspondence', 'correspondence_edition'], true)) {
            $access = app(CorrespondenceAccess::class);
            $assignments = $access->assignments()->filter(fn ($row): bool => ($row->code === $step->assigned_role_code || ($step->assigned_role_code === 'department_head' && $row->code === 'acting_department_head'))
                && ($departmentId === null || (int) $row->department_id === $departmentId));
            $users = User::whereIn('id', $assignments->pluck('user_id'))->get()->keyBy('id');

            if (($instance->subject_type ?? null) === 'correspondence_edition' && $step->assigned_role_code === 'executive_director') {
                $edition = DB::table('correspondence_editions')->find($instance->subject_id);
                if ($edition?->origin_role === 'executive') {
                    $assignments = $assignments->where('user_id', $edition->proposed_by);
                }
            }

            return $assignments->filter(function ($row) use ($access, $users, $step): bool {
                $user = $users->get($row->user_id);
                $department = (int) ($row->department_id ?? $row->home_department);

                return $user && $access->member($user, $department)
                    && ($step->assigned_role_code !== 'department_head' || $access->head($user, $department));
            })->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        }

        return $this->withFinanceAuthority((string) $step->assigned_role_code, DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', $step->assigned_role_code)
            ->where('u.is_active', true)
            ->whereRaw(EffectiveAssignments::sql('a'))
            ->when($departmentId !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->where('a.department_id', $departmentId)->orWhereNull('a.department_id')
            ))
            ->distinct()
            ->orderBy('u.id')
            ->pluck('u.id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * A finance template role (FinancePermissionCatalog::TEMPLATES) holds a step only while the same specialization
     * is active today in «الحسابات», exactly as FinanceGuard grants the template's permissions; the Board treasurer's
     * role only with an active appointment (finance_board_approvers) and never on the technical administrator's
     * account. A role row given another way, or an assignment not yet started or already ended, holds nothing.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withFinanceAuthority(string $roleCode, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $specialization = $this->financeSpecialization($roleCode);
        if ($specialization !== null) {
            $guard = app(FinanceGuard::class);

            return array_values(array_filter($ids, fn (int $id): bool => $guard->activeAssignments($id, $specialization)->exists()));
        }
        if ($roleCode === self::BOARD_TREASURER_ROLE) {
            $appointed = DB::table('finance_board_approvers')->whereIn('user_id', $ids)->where('is_active', true)
                ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

            return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $appointed, true) && ! User::find($id)?->isSystemAdmin()));
        }

        return $ids;
    }

    private function financeSpecialization(?string $roleCode): ?string
    {
        foreach (FinancePermissionCatalog::TEMPLATES as $specialization => [, $code]) {
            if ($code === $roleCode) {
                return $specialization;
            }
        }

        return null;
    }

    /**
     * What must exist for the step to have a holder, in words an administrator can act on (roles and departments,
     * never names): shown when a request waits with nobody qualified, and when publishing is refused for it.
     */
    public function holderNeeded(object $step): string
    {
        $config = $this->config($step);
        $role = fn (?string $code): string => '«'.((string) DB::table('roles')->where('code', $code)->value('name') ?: (string) $code).'»';
        $department = fn (int $id): string => '«'.((string) DB::table('departments')->where('id', $id)->value('name') ?: '؟').'»';
        $code = $step->assigned_role_code ?? null;
        $holder = match ($step->assignment_type) {
            'role', 'pick' => 'من يحمل دور '.$role($code)
                .(($config['department_scope'] ?? 'origin') === 'specific' ? ' في '.$department((int) ($config['department_id'] ?? 0)) : '')
                .($this->financeSpecialization($code) !== null ? ' مع اختصاص ساري به في «الحسابات»' : '')
                .($code === self::BOARD_TREASURER_ROLE ? ' ومعيَّن أمينًا لصندوق مجلس الإدارة من إعدادات الحسابات' : ''),
            'department_head' => 'رئيس معيَّن يحمل دور «رئيس قسم» لقسم مقدم الطلب أو قسم أعلى منه',
            'department_head_of' => 'رئيس معيَّن يحمل دور «رئيس قسم» في '.$department((int) ($config['department_id'] ?? 0)),
            'user' => 'تفعيل المستخدم المحدد للمرحلة',
            'actor_of_step' => 'أن يبقى من نفّذ المرحلة السابقة المحددة مؤهلًا',
            'requester' => 'أن يبقى حساب مقدم الطلب نشطًا',
            default => 'تحديد المسؤول عن المرحلة',
        };
        $fallback = ! empty($config['fallback_role_code']) || ! empty($config['fallback_department_id']);

        return $holder.($fallback ? '، أو البديل المحدد للمرحلة' : '، أو تحديد دور بديل أو قسم بديل للمرحلة في «مسارات العمل»');
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function activeUsers(array $ids): array
    {
        return DB::table('users')->whereIn('id', $ids)->where('is_active', true)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function departmentIsActive(int $departmentId): bool
    {
        return $departmentId > 0 && DB::table('departments')
            ->where('id', $departmentId)->where('is_active', true)->whereNull('deleted_at')->exists();
    }
}
