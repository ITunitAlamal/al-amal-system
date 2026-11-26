<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
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

    public function __construct(private readonly ApprovalDelegations $delegations) {}

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
            if ($delegate !== null && $delegate !== $requesterId) {
                return [$delegate];
            }
        }

        $excluded = $this->requesterMayAct($step) ? 0 : $requesterId;
        $ids = match ($step->assignment_type) {
            'department_head' => $this->headFor((int) $instance->department_id, $excluded),
            'department_head_of' => $this->headFor((int) ($config['department_id'] ?? 0), $excluded),
            'role' => $this->roleHolders($step, $instance),
            'user' => $this->activeUsers([(int) ($config['user_id'] ?? 0)]),
            'pick' => $pickedUserId !== null && in_array($pickedUserId, $this->roleHolders($step, $instance), true)
                ? [$pickedUserId] : [],
            'actor_of_step' => $this->activeUsers(array_filter([$this->actorOfStep($instance, (string) ($config['step_code'] ?? ''))])),
            default => [],
        };
        $ids = array_values(array_diff($ids, [$excluded]));

        // Nobody eligible (e.g. the requester heads the department, or nobody holds the role yet): use the alternates.
        if ($ids === [] && $step->assignment_type !== 'pick') {
            $ids = $this->fallbackHolders($config, $instance, $excluded);
        }

        return $ids;
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
            'department_head_of' => $this->headFor((int) ($config['department_id'] ?? 0), 0),
            'role', 'pick' => $this->roleHolders($anyScope, $probe),
            'user' => $this->activeUsers([(int) ($config['user_id'] ?? 0)]),
            default => [],
        };

        return $ids !== [] || ($step->assignment_type !== 'pick' && $this->fallbackHolders($config, $probe, 0) !== []);
    }

    public function requesterMayAct(object $step): bool
    {
        return ! empty($this->config($step)['requester_may_act']);
    }

    /**
     * Whether someone other than the requester heads the department or one of its parents (the requester's actual superior).
     */
    public function hasSuperiorHead(int $departmentId, int $requesterId): bool
    {
        return $this->headFor($departmentId, $requesterId) !== [];
    }

    /**
     * People the actor can choose from when the next step is a "pick" step.
     *
     * @return list<array{id: int, name: string}>
     */
    public function pickOptions(object $step, object $instance): array
    {
        $ids = array_values(array_diff($this->roleHolders($step, $instance), [$this->requesterMayAct($step) ? 0 : (int) $instance->requested_by]));

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
     * @return list<int>
     */
    private function fallbackHolders(array $config, object $instance, int $excluded): array
    {
        if (! empty($config['fallback_role_code'])) {
            $fallback = (object) ['assigned_role_code' => $config['fallback_role_code'], 'assignment_config' => ['department_scope' => 'any']];
            $ids = array_values(array_diff($this->roleHolders($fallback, $instance), [$excluded]));
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
     * The head of a department, or of the nearest parent department when the head
     * is the requester (a person never approves their own request).
     *
     * @return list<int>
     */
    private function headFor(int $departmentId, int $requesterId): array
    {
        $visited = [];
        while ($departmentId > 0 && ! isset($visited[$departmentId])) {
            $visited[$departmentId] = true;
            $head = $this->validHead($departmentId);
            if ($head !== null && $head !== $requesterId) {
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

        return DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', $step->assigned_role_code)
            ->where('u.is_active', true)
            ->when($departmentId !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->where('a.department_id', $departmentId)->orWhereNull('a.department_id')
            ))
            ->distinct()
            ->orderBy('u.id')
            ->pluck('u.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
