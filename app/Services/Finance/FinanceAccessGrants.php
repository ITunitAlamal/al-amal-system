<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\OrganizationStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance role templates and per-user permissions granted by the head of Accounts (within the department) or by the
 * system administrator (owner decisions 2026-11-01):
 * - nobody grants to themselves; the head grants only the department's templates and permissions to the active staff
 *   of «الحسابات» and its units, never executive or board decisions;
 * - a grant that adds a sensitive permission (payments, payroll and salaries, banks, master settings) waits for the
 *   executive director, whoever proposed it; the system administrator's technical role never approves it;
 * - a grant that would join an incompatible pair (FinancePermissionCatalog::CONFLICTS) is refused, unless it is a
 *   dated exception (90 days at most) with a reason, decided by the executive director;
 * - grants may carry start and end dates; FinanceGuard ignores an expired grant and `finance:expire-access` ends it;
 * - withdrawing access is immediate. Every step is audited (finance_audit_events → «سجل النظام») and notified.
 */
final class FinanceAccessGrants
{
    public const EXCEPTION_MAX_DAYS = 90;

    public const ADMIN_REFUSED = 'لا تُمنح العمليات المالية ولا قرارات الاعتماد لحساب مدير النظام التقني.';

    public const STATES = [
        'pending_approval' => 'بانتظار قرار المدير التنفيذي', 'applied' => 'نافذ', 'rejected' => 'مرفوض',
        'cancelled' => 'ملغى', 'ended' => 'منتهٍ', 'expired' => 'انتهت مدته',
    ];

    public function __construct(
        private readonly FinanceGuard $guard,
        private readonly NotificationCenter $notifications,
    ) {}

    /** 'system_admin', 'head' (appointed head of Accounts), or null when the user assigns nothing. */
    public function actorRole(?User $actor): ?string
    {
        return match (true) {
            ! $actor || ! $actor->is_active => null,
            $actor->isSystemAdmin() => 'system_admin',
            $this->guard->isAppointedHead($actor) && $this->guard->allows($actor, 'finance.staff.assign') => 'head',
            default => null,
        };
    }

    /** Department ids of «الحسابات» and its units. @return list<int> */
    public function departmentTree(): array
    {
        $root = $this->guard->departmentId();

        return $root ? app(OrganizationStructure::class)->descendants($root) : [];
    }

    /** Staff the actor may assign: active members of the Accounts tree, the actor excluded. */
    public function eligibleStaff(User $actor): Collection
    {
        return DB::table('users as u')->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->whereIn('u.department_id', $this->departmentTree())->where('u.is_active', true)->where('u.id', '!=', $actor->id)
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.department_id', 'd.name as department']);
    }

    /** Why the actor may not assign this person, or null. */
    public function targetError(User $actor, User $target): ?string
    {
        $role = $this->actorRole($actor);

        return match (true) {
            $role === null => 'لا تملك صلاحية إسناد اختصاصات الحسابات.',
            (int) $actor->id === (int) $target->id => 'لا يُسنِد أحد لنفسه اختصاصًا أو صلاحية مالية.',
            ! $target->is_active => 'الحساب غير فعّال.',
            $role === 'head' && ! in_array((int) $target->department_id, $this->departmentTree(), true) => 'يُسنِد رئيس القسم لموظفي «الحسابات» ووحداتها فقط؛ نقل الموظف إلى القسم يمر بالموارد البشرية.',
            default => null,
        };
    }

    /**
     * Finance permissions this user holds by role or grant, whatever the accounting mode (a mode switch must not
     * reveal a hidden conflict). @return list<string>
     */
    public function held(User $user): array
    {
        $roles = DB::table('department_user_roles as a')->join('permission_role as pr', 'pr.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')->where('a.user_id', $user->id)->where('p.code', 'like', 'finance.%')
            ->distinct()->pluck('p.code')->all();
        $overrides = $this->guard->overridesFor($user);
        $granted = array_keys(array_filter($overrides, fn (string $effect): bool => $effect === 'grant'));
        $denied = array_keys(array_filter($overrides, fn (string $effect): bool => $effect === 'deny'));

        return array_values(array_diff(array_unique([...$roles, ...$granted]), $denied));
    }

    /** Permissions a template's role carries. @return list<string> */
    public function templatePermissions(string $template): array
    {
        $role = FinancePermissionCatalog::TEMPLATES[$template][1] ?? null;

        return $role ? DB::table('permission_role as pr')->join('roles as r', 'r.id', '=', 'pr.role_id')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('r.code', $role)->pluck('p.code')->all() : [];
    }

    /**
     * What a grant would change, before anything is written.
     *
     * @return array{adds: list<string>, sensitive: bool, conflicts: list<array{0: string, 1: string}>, needsApproval: bool, error: ?string}
     */
    public function preview(User $actor, User $target, string $kind, string $code): array
    {
        $role = $this->actorRole($actor);
        $error = $this->targetError($actor, $target);
        if ($kind === 'specialization') {
            $adds = $this->templatePermissions($code);
            $error ??= match (true) {
                ! isset(FinancePermissionCatalog::TEMPLATES[$code]) => 'قالب غير معروف.',
                $role === 'head' && ! in_array($code, FinancePermissionCatalog::HEAD_TEMPLATES, true) => 'هذا القالب يُسنده مدير النظام بقرار إداري، لا رئيس القسم.',
                $this->guard->activeAssignments((int) $target->id, $code)->exists() => 'الاختصاص مُسند وساري المفعول.',
                $target->isSystemAdmin() && array_diff($adds, FinanceGuard::TECHNICAL_ADMIN) !== [] => self::ADMIN_REFUSED,
                default => null,
            };
            $sensitive = in_array($code, FinancePermissionCatalog::SENSITIVE_TEMPLATES, true) || array_intersect($adds, FinancePermissionCatalog::SENSITIVE) !== [];
        } else {
            $adds = [$code];
            $error ??= match (true) {
                ! in_array($code, FinancePermissionCatalog::codes(), true) => 'صلاحية غير معروفة.',
                $role === 'head' && in_array($code, FinancePermissionCatalog::HEAD_EXCLUDED, true) => 'هذه الصلاحية لا يمنحها رئيس القسم.',
                $target->isSystemAdmin() && ! in_array($code, FinanceGuard::TECHNICAL_ADMIN, true) => self::ADMIN_REFUSED,
                default => null,
            };
            $sensitive = in_array($code, FinancePermissionCatalog::SENSITIVE, true) || $code === 'finance.staff.assign';
        }
        $held = $this->held($target);
        $before = FinancePermissionCatalog::conflictsIn($held);
        $after = FinancePermissionCatalog::conflictsIn(array_values(array_unique([...$held, ...$adds])));
        $conflicts = array_values(array_filter($after, fn (array $pair): bool => ! in_array($pair, $before, true)));

        return ['adds' => array_values(array_diff($adds, $held)), 'sensitive' => $sensitive, 'conflicts' => $conflicts,
            'needsApproval' => $sensitive || $conflicts !== [], 'error' => $error];
    }

    /**
     * Records a grant: applied now, or waiting for the executive director when sensitive or an exception.
     *
     * @param  array{kind: string, code: string, is_primary?: bool, valid_from?: ?string, valid_until?: ?string, reason: string, exception?: bool}  $data
     */
    public function request(User $actor, User $target, array $data): object
    {
        $preview = $this->preview($actor, $target, $data['kind'], $data['code']);
        $from = Carbon::parse($data['valid_from'] ?? today())->startOfDay();
        $until = empty($data['valid_until']) ? null : Carbon::parse($data['valid_until'])->startOfDay();
        $exception = (bool) ($data['exception'] ?? false);
        $refuse = function (string $field, string $message) use ($actor, $target, $data): never {
            $this->guard->audit('access_grant', $target->id, (int) $actor->id, 'denied', ['kind' => $data['kind'], 'code' => $data['code'], 'reason' => $message]);
            throw ValidationException::withMessages([$field => $message]);
        };
        if ($preview['error']) {
            $refuse('code', $preview['error']);
        }
        if ($from->lt(today())) {
            $refuse('valid_from', 'تاريخ البدء لا يسبق اليوم.');
        }
        if ($until && $until->lt($from)) {
            $refuse('valid_until', 'تاريخ الانتهاء يسبق تاريخ البدء.');
        }
        if ($preview['conflicts'] !== [] && ! $exception) {
            $pairs = implode('، ', array_map(fn (array $p): string => '«'.FinancePermissionCatalog::label($p[0]).'» مع «'.FinancePermissionCatalog::label($p[1]).'»', $preview['conflicts']));
            $refuse('code', "يتعارض مع فصل المهام: {$pairs}. يمكن طلبه استثناءً مؤقتًا بتاريخ انتهاء وسبب، ويقرره المدير التنفيذي.");
        }
        if ($exception && (! $until || $from->diffInDays($until) > self::EXCEPTION_MAX_DAYS)) {
            $refuse('valid_until', 'الاستثناء من فصل المهام مؤقت: حدد تاريخ انتهاء خلال '.self::EXCEPTION_MAX_DAYS.' يومًا.');
        }
        if (DB::table('finance_access_grants')->where('user_id', $target->id)->where('kind', $data['kind'])->where('code', $data['code'])->where('state', 'pending_approval')->exists()) {
            throw ValidationException::withMessages(['code' => 'يوجد طلب مماثل بانتظار قرار المدير التنفيذي.']);
        }

        return DB::transaction(function () use ($actor, $target, $data, $preview, $from, $until, $exception): object {
            DB::table('users')->where('id', $target->id)->lockForUpdate()->first();
            $id = DB::table('finance_access_grants')->insertGetId([
                'public_id' => (string) Str::uuid(), 'user_id' => $target->id, 'kind' => $data['kind'], 'code' => $data['code'],
                'is_primary' => (bool) ($data['is_primary'] ?? false), 'valid_from' => $from->toDateString(), 'valid_until' => $until?->toDateString(),
                'reason' => trim($data['reason']), 'sensitive' => $preview['sensitive'], 'is_exception' => $exception && $preview['conflicts'] !== [],
                'conflicts' => $preview['conflicts'] === [] ? null : json_encode($preview['conflicts']),
                'state' => $preview['needsApproval'] ? 'pending_approval' : 'applied', 'requested_as' => $this->actorRole($actor),
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $grant = DB::table('finance_access_grants')->find($id);
            $this->guard->audit('access_grant', $id, (int) $actor->id, $grant->state === 'applied' ? 'requested_applied' : 'requested',
                ['user_id' => $target->id, 'kind' => $grant->kind, 'code' => $grant->code, 'sensitive' => $grant->sensitive, 'exception' => $grant->is_exception, 'valid_until' => $grant->valid_until]);
            if ($grant->state === 'applied') {
                $this->apply($grant, (int) $actor->id);
            } else {
                $this->notifications->notifyMany($this->executives(), 'finance_access_grant_pending', [
                    'title' => 'طلب صلاحية مالية بانتظار قرارك', 'body' => $this->describe($grant).' — '.$target->name,
                    'url' => route('finance.access-grants.show', $grant->public_id, false), 'dedupe' => 'fin-grant:'.$grant->public_id,
                ]);
            }

            return DB::table('finance_access_grants')->find($id);
        });
    }

    /**
     * The executive director who decides sensitive grants: the same person FinanceGuard::isExecutiveDirector accepts
     * for purchases and payroll (the structural seat of EXEC), so the two can never diverge. @return list<int>
     */
    public function executives(): array
    {
        return $this->guard->executiveDirectorIds();
    }

    public function canDecide(User $user, object $grant): bool
    {
        return $user->is_active && in_array((int) $user->id, $this->executives(), true)
            && (int) $grant->requested_by !== (int) $user->id && (int) $grant->user_id !== (int) $user->id;
    }

    public function decide(User $decider, string $publicId, bool $approve, ?string $note): object
    {
        return DB::transaction(function () use ($decider, $publicId, $approve, $note): object {
            $grant = DB::table('finance_access_grants')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($grant, 404);
            if (! $this->canDecide($decider, $grant)) {
                abort(403, 'القرار للمدير التنفيذي، ولا يقرر أحد طلبًا قدّمه أو يخصه.');
            }
            if ($grant->state !== 'pending_approval') {
                throw ValidationException::withMessages(['grant' => 'تم البت في الطلب من قبل.']);
            }
            $target = User::findOrFail($grant->user_id);
            if ($approve) {
                if (! $target->is_active) {
                    throw ValidationException::withMessages(['grant' => 'الحساب لم يعد فعّالًا؛ ارفض الطلب.']);
                }
                // The situation may have changed since the request: a new conflict needs a new exception request.
                $now = FinancePermissionCatalog::conflictsIn(array_values(array_unique([...$this->held($target),
                    ...($grant->kind === 'specialization' ? $this->templatePermissions($grant->code) : [$grant->code])])));
                $before = FinancePermissionCatalog::conflictsIn($this->held($target));
                $allowed = json_decode((string) $grant->conflicts, true) ?: [];
                if (array_filter($now, fn (array $p): bool => ! in_array($p, $before, true) && ! in_array($p, $allowed, true)) !== []) {
                    throw ValidationException::withMessages(['grant' => 'تغيّرت صلاحيات الموظف ونشأ تعارض جديد؛ ارفض الطلب ليُقدَّم من جديد.']);
                }
            } elseif (trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'اكتب سبب الرفض.']);
            }
            DB::table('finance_access_grants')->where('id', $grant->id)->update([
                'state' => $approve ? 'applied' : 'rejected', 'decided_by' => $decider->id, 'decided_at' => now(),
                'decision_note' => $note ? trim($note) : null, 'updated_at' => now(),
            ]);
            $grant = DB::table('finance_access_grants')->find($grant->id);
            $this->guard->audit('access_grant', $grant->id, (int) $decider->id, $approve ? 'approved' : 'rejected', ['user_id' => $grant->user_id, 'code' => $grant->code]);
            if ($approve) {
                $this->apply($grant, (int) $decider->id);
            }
            $this->notifications->notify((int) $grant->requested_by, 'finance_access_grant_decided', [
                'title' => $approve ? 'اعتُمد طلب الصلاحية المالية' : 'رُفض طلب الصلاحية المالية', 'body' => $this->describe($grant).' — '.$target->name,
                'url' => route('finance.access-grants.show', $grant->public_id, false), 'dedupe' => 'fin-grant-decided:'.$grant->public_id,
            ]);

            return $grant;
        });
    }

    /** Writes an approved grant into the access tables FinanceGuard reads. */
    private function apply(object $grant, int $by): void
    {
        if ($grant->kind === 'specialization') {
            if ($grant->is_primary) {
                DB::table('finance_staff_assignments')->where('user_id', $grant->user_id)->update(['is_primary' => false, 'updated_at' => now()]);
            }
            DB::table('finance_staff_assignments')->updateOrInsert(['user_id' => $grant->user_id, 'specialization' => $grant->code], [
                'is_primary' => $grant->is_primary, 'is_active' => true, 'assigned_by' => $grant->requested_by,
                'valid_from' => $grant->valid_from, 'valid_until' => $grant->valid_until, 'grant_id' => $grant->id,
                'updated_at' => now(), 'created_at' => now(),
            ]);
            $role = DB::table('roles')->where('code', FinancePermissionCatalog::TEMPLATES[$grant->code][1])->value('id');
            if (! $role) {
                throw ValidationException::withMessages(['code' => 'دور القالب غير مثبت.']);
            }
            $scope = $this->guard->departmentId();
            // The template role follows the assignment's period (as close() removes it with the assignment), so a grant
            // that starts later neither opens permissions nor makes its holder a workflow assignee before it starts.
            $period = ['starts_on' => $grant->valid_from, 'ends_on' => $grant->valid_until, 'updated_at' => now()];
            $existing = DB::table('department_user_roles')->where('user_id', $grant->user_id)->where('role_id', $role)->where('department_id', $scope)->value('id');
            $existing
                ? DB::table('department_user_roles')->where('id', $existing)->update($period)
                : DB::table('department_user_roles')->insert(['user_id' => $grant->user_id, 'role_id' => $role, 'department_id' => $scope, ...$period, 'created_at' => now()]);
        } else {
            $previous = DB::table('finance_user_permissions')->where('user_id', $grant->user_id)->where('permission_code', $grant->code)->first();
            // A future grant must not restore an inherited role permission while an earlier denial still applies.
            DB::table('finance_access_grants')->where('id', $grant->id)->update(['previous_effect' => $previous?->effect]);
            DB::table('finance_user_permissions')->updateOrInsert(['user_id' => $grant->user_id, 'permission_code' => $grant->code], [
                'effect' => 'grant', 'reason' => $grant->reason, 'decided_by' => $by, 'valid_until' => $grant->valid_until, 'grant_id' => $grant->id,
                'updated_at' => now(), 'created_at' => $previous->created_at ?? now(),
            ]);
        }
        DB::table('finance_access_grants')->where('id', $grant->id)->update(['applied_at' => now(), 'updated_at' => now()]);
        $this->guard->audit('access_grant', $grant->id, $by, 'applied', ['user_id' => $grant->user_id, 'kind' => $grant->kind, 'code' => $grant->code, 'valid_until' => $grant->valid_until]);
        $this->notifications->notify((int) $grant->user_id, 'finance_access_changed', [
            'title' => 'أُسند إليك في الحسابات: '.$this->describe($grant), 'body' => $grant->valid_until ? 'حتى '.$grant->valid_until : null,
            'url' => '/finance', 'dedupe' => 'fin-grant-applied:'.$grant->public_id,
        ]);
    }

    /** Ends an assignment now (head within the department, or the administrator); the history stays. */
    public function endSpecialization(User $actor, User $target, string $specialization, string $reason): void
    {
        if ($error = $this->targetError($actor, $target)) {
            throw ValidationException::withMessages(['user_id' => $error]);
        }
        if ($this->actorRole($actor) === 'head' && ! in_array($specialization, FinancePermissionCatalog::HEAD_TEMPLATES, true)) {
            throw ValidationException::withMessages(['code' => 'هذا الاختصاص يُنهيه مدير النظام.']);
        }
        DB::transaction(function () use ($actor, $target, $specialization, $reason): void {
            $assignment = DB::table('finance_staff_assignments')->where('user_id', $target->id)->where('specialization', $specialization)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($assignment, 404);
            $this->close($assignment, (int) $actor->id, 'ended', $reason);
        });
    }

    /** Withdraws a permission now: removes a grant, or denies a permission the person's role carries. */
    public function withdrawPermission(User $actor, User $target, string $code, string $reason): void
    {
        if ($error = $this->targetError($actor, $target)) {
            throw ValidationException::withMessages(['user_id' => $error]);
        }
        abort_unless(in_array($code, FinancePermissionCatalog::codes(), true), 404);
        DB::transaction(function () use ($actor, $target, $code, $reason): void {
            $previous = DB::table('finance_user_permissions')->where('user_id', $target->id)->where('permission_code', $code)->lockForUpdate()->first();
            DB::table('finance_user_permissions')->updateOrInsert(['user_id' => $target->id, 'permission_code' => $code], [
                'effect' => 'deny', 'reason' => trim($reason), 'decided_by' => $actor->id, 'valid_until' => null, 'grant_id' => null,
                'updated_at' => now(), 'created_at' => $previous->created_at ?? now(),
            ]);
            if ($previous?->grant_id) {
                DB::table('finance_access_grants')->where('id', $previous->grant_id)->where('state', 'applied')
                    ->update(['state' => 'ended', 'ended_by' => $actor->id, 'ended_at' => now(), 'end_reason' => trim($reason), 'updated_at' => now()]);
            }
            $this->guard->audit('finance_user_permission', $target->id, (int) $actor->id, 'deny', ['permission' => $code, 'previous' => $previous->effect ?? 'inherit', 'reason' => trim($reason)]);
            $this->notifications->notify((int) $target->id, 'finance_access_changed', [
                'title' => 'سُحبت منك صلاحية في الحسابات: '.FinancePermissionCatalog::label($code), 'url' => '/notifications',
                'dedupe' => 'fin-withdraw:'.$target->id.':'.$code.':'.now()->timestamp,
            ]);
        });
    }

    /** Ends dated assignments and grants after their end date. Run by `finance:expire-access`. */
    public function expire(): int
    {
        $today = today()->toDateString();
        $count = 0;
        foreach (DB::table('finance_staff_assignments')->where('is_active', true)->whereNotNull('valid_until')->where('valid_until', '<', $today)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id): int {
                $assignment = DB::table('finance_staff_assignments')->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
                if (! $assignment) {
                    return 0;
                }
                $this->close($assignment, null, 'expired', 'انتهت المدة المحددة');

                return 1;
            });
        }
        foreach (DB::table('finance_user_permissions')->where('effect', 'grant')->whereNotNull('valid_until')->where('valid_until', '<', $today)->get() as $row) {
            $count += DB::transaction(function () use ($row): int {
                $deleted = DB::table('finance_user_permissions')->where('id', $row->id)->where('effect', 'grant')->delete();
                if ($deleted && $row->grant_id) {
                    DB::table('finance_access_grants')->where('id', $row->grant_id)->where('state', 'applied')->update(['state' => 'expired', 'ended_at' => now(), 'updated_at' => now()]);
                }
                if ($deleted) {
                    $this->guard->audit('finance_user_permission', $row->user_id, (int) ($row->decided_by ?? 1), 'expired', ['permission' => $row->permission_code, 'valid_until' => $row->valid_until]);
                }

                return $deleted;
            });
        }

        return $count;
    }

    /** Ends one assignment and removes the role it added in «الحسابات». */
    private function close(object $assignment, ?int $by, string $state, string $reason): void
    {
        DB::table('finance_staff_assignments')->where('id', $assignment->id)->update(['is_active' => false, 'is_primary' => false, 'updated_at' => now()]);
        $role = DB::table('roles')->where('code', FinancePermissionCatalog::TEMPLATES[$assignment->specialization][1] ?? '')->value('id');
        $removed = $role ? DB::table('department_user_roles')->where('user_id', $assignment->user_id)->where('role_id', $role)->where('department_id', $this->guard->departmentId())->delete() : 0;
        if ($assignment->grant_id ?? null) {
            DB::table('finance_access_grants')->where('id', $assignment->grant_id)->where('state', 'applied')
                ->update(['state' => $state, 'ended_by' => $by, 'ended_at' => now(), 'end_reason' => $reason, 'updated_at' => now()]);
        }
        // finance_audit_events needs an actor: an automatic expiry is recorded under the person who granted it.
        $this->guard->audit('finance_assignment', $assignment->user_id, $by ?? (int) $assignment->assigned_by, $state === 'expired' ? 'expired' : 'end',
            ['specialization' => $assignment->specialization, 'reason' => $reason, 'removed_roles' => $removed]);
        $this->notifications->notify((int) $assignment->user_id, 'finance_access_changed', [
            'title' => 'انتهى اختصاصك في الحسابات: '.FinancePermissionCatalog::templateLabel($assignment->specialization), 'body' => $reason,
            'url' => '/notifications', 'dedupe' => 'fin-end:'.$assignment->id.':'.now()->timestamp,
        ]);
    }

    public function describe(object $grant): string
    {
        $what = $grant->kind === 'specialization' ? 'قالب «'.FinancePermissionCatalog::templateLabel($grant->code).'»' : 'صلاحية «'.FinancePermissionCatalog::label($grant->code).'»';

        return $what.($grant->is_exception ? ' (استثناء مؤقت من فصل المهام)' : '');
    }

    /** One grant for display. @return array<string, mixed> */
    public function present(object $grant): array
    {
        $names = DB::table('users')->whereIn('id', array_filter([$grant->user_id, $grant->requested_by, $grant->decided_by, $grant->ended_by]))->pluck('name', 'id');

        return [
            'id' => $grant->public_id, 'what' => $this->describe($grant), 'kind' => $grant->kind, 'code' => $grant->code,
            'user' => $names[$grant->user_id] ?? '—', 'userId' => (int) $grant->user_id,
            'requestedBy' => $names[$grant->requested_by] ?? '—', 'requestedAs' => $grant->requested_as === 'head' ? 'رئيس القسم' : 'مدير النظام',
            'decidedBy' => $grant->decided_by ? ($names[$grant->decided_by] ?? '—') : null, 'decidedAt' => $grant->decided_at, 'decisionNote' => $grant->decision_note,
            'validFrom' => $grant->valid_from, 'validUntil' => $grant->valid_until, 'reason' => $grant->reason,
            'sensitive' => (bool) $grant->sensitive, 'exception' => (bool) $grant->is_exception,
            'conflicts' => array_map(fn (array $p): array => array_map(FinancePermissionCatalog::label(...), $p), json_decode((string) $grant->conflicts, true) ?: []),
            'permissions' => array_map(FinancePermissionCatalog::label(...), $grant->kind === 'specialization' ? $this->templatePermissions($grant->code) : [$grant->code]),
            'state' => $grant->state, 'stateLabel' => self::STATES[$grant->state] ?? $grant->state, 'createdAt' => $grant->created_at,
            'endedAt' => $grant->ended_at, 'endReason' => $grant->end_reason,
        ];
    }
}
