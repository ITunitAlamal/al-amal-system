<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceGuard
{
    /**
     * Business decisions (review, approval, money execution). The system administrator's technical access never
     * covers them (SRS §2.4 and §3.1.4.1, ED requirements doc §2): they need the role permission and assignment.
     */
    public const BUSINESS_DECISIONS = [
        'finance.payment.review', 'finance.payment.approve', 'finance.payment.execute', 'finance.payment.record',
        'finance.receipt.review', 'finance.receipt.approve', 'finance.receipt.execute',
        'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
        'finance.budget.approve', 'finance.payroll.approve', 'finance.fund.approve',
        'finance.transfer.approve', 'finance.transfer.execute', 'finance.reversal.approve', 'finance.stock.count.approve',
        // Recording collections and settlement postings from the accounting software is a financial record the
        // administrator's technical role must never make (no fictitious postings).
        'finance.rentals.record', 'finance.sponsorships.record',
        // Salary data and payslip publication (2026-11-01): never through the technical role.
        'finance.payroll.record', 'finance.payroll.publish', 'finance.payroll.authorize',
    ];

    /**
     * What the system administrator's technical role holds in Accounts (owner decision 2026-11-01): read-only
     * oversight, reports, setup and definitions, and assigning staff. Creating purchase requests, suppliers or
     * purchase orders, receiving goods, recording invoices, stock movements and counts are operations of the units'
     * staff and are never reached through the technical role.
     */
    public const TECHNICAL_ADMIN = [
        'finance.view', 'finance.monitor', 'finance.reports.view', 'finance.report.export', 'finance.setup',
        'finance.staff.assign', 'finance.warehouse.manage', 'finance.procurement.policy',
    ];

    /** @return list<string> */
    public function uiPermissions(?User $user): array
    {
        $permissions = [...FinancePermissionCatalog::codes(), 'finance.setup'];

        return array_values(array_filter($permissions, fn (string $permission): bool => $this->allows($user, $permission)));
    }

    /**
     * Per-user decisions of the system administrator (`finance_user_permissions`): 'grant' adds one permission to
     * the user's finance roles, 'deny' withdraws it even when a role carries it. Read on every request, so a
     * revocation takes effect immediately, including for a tab that is still open.
     *
     * @var array<int, array<string, string>>
     */
    private array $overrides = [];

    /** @var array<int, list<string>> */
    private array $specializations = [];

    /** @var array<int, list<string>> */
    private array $rolePermissions = [];

    /** @return array<string, string> permission code => 'grant'|'deny' */
    public function overridesFor(User $user): array
    {
        if (! isset($this->overrides[$user->id])) {
            // A dated grant starts and stops on its own; a prior withdrawal stays in force until a future grant starts.
            $query = Schema::hasTable('finance_user_permissions')
                ? DB::table('finance_user_permissions as p')->where('p.user_id', $user->id)
                    ->where(fn ($q) => $q->whereNull('p.valid_until')->orWhere('p.valid_until', '>=', today()->toDateString()))
                : null;
            $columns = ['p.effect', 'p.permission_code'];
            if ($query && Schema::hasTable('finance_access_grants')) {
                $query->leftJoin('finance_access_grants as g', 'g.id', '=', 'p.grant_id')
                    ->where(fn ($q) => $q->where('p.effect', 'deny')->orWhereNull('p.grant_id')
                        ->orWhere(fn ($grant) => $grant->where('g.state', 'applied')
                            ->where(fn ($date) => $date->where('g.valid_from', '<=', today()->toDateString())->orWhere('g.previous_effect', 'deny'))));
                $columns = [...$columns, 'g.valid_from as grant_valid_from', 'g.previous_effect'];
            }
            $this->overrides[$user->id] = $query?->get($columns)->mapWithKeys(function (object $row): array {
                $priorDenial = $row->effect === 'grant' && ($row->grant_valid_from ?? null) > today()->toDateString() && ($row->previous_effect ?? null) === 'deny';

                return [$row->permission_code => $priorDenial ? 'deny' : $row->effect];
            })->all() ?? [];
        }

        return $this->overrides[$user->id];
    }

    /**
     * Bookkeeping kept in the association's accounting software while Accounts run the documentary cycle only
     * (owner decision 2026-10-23). In that mode these permissions are refused to everyone; their data stays.
     */
    public const ACCOUNTING_PERMISSIONS = [
        'finance.treasury.manage', 'finance.budget.manage', 'finance.budget.transfer', 'finance.budget.approve',
        'finance.payment.draft', 'finance.payment.review', 'finance.payment.approve', 'finance.payment.execute',
        'finance.receipt.draft', 'finance.receipt.review', 'finance.receipt.approve', 'finance.receipt.execute',
        'finance.receivable.manage', 'finance.payroll.manage', 'finance.payroll.approve', 'finance.cheque.manage',
        'finance.transfer.execute', 'finance.transfer.approve', 'finance.reconcile', 'finance.fund.manage', 'finance.fund.approve',
        'finance.advance.manage', 'finance.reversal.request', 'finance.reversal.approve',
    ];

    private ?object $settings = null;

    /** Accounts run the documentary cycle only; the books are kept in the external accounting software. */
    public function documentaryMode(): bool
    {
        return ($this->settings()->accounting_mode ?? 'full') === 'documentary';
    }

    public function externalSystemName(): string
    {
        return (string) ($this->settings()->external_system_name ?? 'البرنامج المحاسبي');
    }

    private function settings(): object
    {
        return $this->settings ??= (Schema::hasTable('finance_module_settings')
            ? DB::table('finance_module_settings')->where('id', 1)->first() : null) ?? (object) [];
    }

    /**
     * What the appointed head of «الحسابات» holds by the structure (`departments.head_user_id`): consolidated
     * monitoring, reports, and assigning the department's staff. Every edit, review, approval or execution right
     * still needs its own explicit permission and assignment (owner decision 2026-11-01).
     */
    public const HEAD_PERMISSIONS = ['finance.view', 'finance.monitor', 'finance.reports.view', 'finance.report.export', 'finance.staff.assign'];

    /** @var array<int, bool> */
    private array $heads = [];

    public function isAppointedHead(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->heads[$user->id] ??= $this->holdsStructuralSeat($user, 'id', $this->departmentId(), 'department_head');
    }

    /**
     * head_user_id of the department plus an effective assignment of the matching role in that department, as
     * User::headedDepartmentId and OrphanAccess::isDesignatedExecutive require. head_user_id alone is not enough.
     */
    private function holdsStructuralSeat(User $user, string $column, int|string|null $value, string $role): bool
    {
        if ($value === null) {
            return false;
        }

        return DB::table('departments as d')->where("d.$column", $value)->where('d.head_user_id', $user->id)->whereNull('d.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.department_id', 'd.id')->whereColumn('a.user_id', 'd.head_user_id')->where('r.code', $role)
                ->whereRaw(EffectiveAssignments::sql('a')))
            ->exists();
    }

    /**
     * The executive director's step in the purchase cycle, held by the appointed head of «الإدارة التنفيذية» (EXEC)
     * by the structure (owner decision 2026-11-02). It cannot be a grant: grants are decided by the executive
     * director, who never decides his own. It moves with the appointment.
     */
    public const EXECUTIVE_PERMISSIONS = ['finance.procurement.director_approve', 'finance.payroll.authorize'];

    /** @var array<int, bool> */
    private array $executives = [];

    public function isExecutiveDirector(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->executives[$user->id] ??= $this->holdsStructuralSeat($user, 'code', 'EXEC', 'executive_director');
    }

    /**
     * The one definition of the executive director in Accounts, as a list: the active users isExecutiveDirector()
     * accepts (appointed head of EXEC with an effective executive_director assignment there). A user who keeps the
     * role after a new appointment, or holds it as acting without the seat, is not one.
     *
     * @return list<int>
     */
    public function executiveDirectorIds(): array
    {
        $candidates = DB::table('departments')->where('code', 'EXEC')->whereNull('deleted_at')->whereNotNull('head_user_id')->pluck('head_user_id');

        return User::whereIn('id', $candidates)->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $this->isExecutiveDirector($user))->map(fn (User $user): int => (int) $user->id)->values()->all();
    }

    private ?int $departmentId = null;

    /** The Accounts department (code FIN, formerly ACC). */
    public function departmentId(): ?int
    {
        return $this->departmentId ??= (int) (DB::table('departments')->where('code', 'FIN')->value('id')
            ?? DB::table('departments')->where('code', 'ACC')->value('id')) ?: null;
    }

    /** An active finance assignment today: a dated one counts only between its start and end dates. */
    public function activeAssignments(int $userId, ?string $specialization = null)
    {
        $today = today()->toDateString();

        return DB::table('finance_staff_assignments')->where('user_id', $userId)->where('is_active', true)
            ->when($specialization, fn ($q) => $q->where('specialization', $specialization))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
    }

    public function allows(?User $user, string $permission): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        if (isset(FinancePermissionCatalog::LEGACY_TRANSACTION_PERMISSIONS[$permission])) {
            return false;
        }
        if (in_array($permission, self::ACCOUNTING_PERMISSIONS, true) && $this->documentaryMode()) {
            return false;
        }
        // The Board treasurer's approval is a business decision the technical role never makes, whatever its rows say.
        if ($permission === 'finance.procurement.board_approve' && $user->isSystemAdmin()) {
            return false;
        }
        $override = $this->overridesFor($user)[$permission] ?? null;
        if ($override === 'deny') {
            return false;
        }
        if (in_array($permission, self::HEAD_PERMISSIONS, true) && $this->isAppointedHead($user)) {
            return true;
        }
        if (in_array($permission, self::EXECUTIVE_PERMISSIONS, true) && $this->isExecutiveDirector($user)) {
            return true;
        }
        // An active system administrator holds the technical list only. Operations and business decisions are
        // never granted to that account (FinanceAccessGrants refuses them), so they end at the check below.
        if ($user->isSystemAdmin() && in_array($permission, self::TECHNICAL_ADMIN, true)) {
            return true;
        }
        // All other users require an explicit role permission or a per-user grant, plus an active finance assignment.
        // A job title or department membership alone never grants anything here.
        $hasRolePermission = $override === 'grant'
            || $this->hasActiveRolePermission($user, $permission);
        if (! $hasRolePermission) {
            return false;
        }
        if ($permission === 'finance.procurement.board_approve') {
            return DB::table('finance_board_approvers')->where('user_id', $user->id)->where('is_active', true)->exists();
        }
        if (in_array($permission, ['finance.procurement.director_approve', 'finance.payment.approve', 'finance.receipt.approve', 'finance.budget.approve', 'finance.payroll.approve', 'finance.fund.approve', 'finance.transfer.approve', 'finance.reversal.approve'], true)) {
            return in_array('director_approver', $this->specializationsFor($user), true);
        }

        return $this->specializationsFor($user) !== [];
    }

    /** @return list<string> */
    private function specializationsFor(User $user): array
    {
        return $this->specializations[$user->id] ??= $this->activeAssignments((int) $user->id)->pluck('specialization')->all();
    }

    /** A template's permission is available only while that same specialization is active today. */
    private function hasActiveRolePermission(User $user, string $permission): bool
    {
        if (! isset($this->rolePermissions[$user->id])) {
            $roles = array_column(FinancePermissionCatalog::TEMPLATES, 1);
            $activeRoles = array_values(array_map(fn (string $specialization): string => FinancePermissionCatalog::TEMPLATES[$specialization][1],
                array_values(array_intersect($this->specializationsFor($user), array_keys(FinancePermissionCatalog::TEMPLATES)))));
            // Only appointments in force today (starts_on/ends_on), for template and other roles alike.
            $this->rolePermissions[$user->id] = $user->roleAssignments()->effective()
                ->whereHas('role', fn ($q) => $q->where(fn ($role) => $role->whereNotIn('code', $roles)->orWhereIn('code', $activeRoles)))
                ->with('role.permissions')->get()->flatMap(fn ($assignment) => $assignment->role->permissions->pluck('code'))->unique()->values()->all();
        }

        return in_array($permission, $this->rolePermissions[$user->id], true);
    }

    /** A closed mapping: receipt and payment permissions never imply one another. */
    public static function transactionPermission(string $kind, string $action): string
    {
        abort_unless(in_array($kind, ['payment', 'receipt'], true) && in_array($action, ['draft', 'review', 'approve', 'execute'], true), 403);

        return 'finance.'.$kind.'.'.$action;
    }

    public function allowsTransaction(?User $user, string $kind, string $action): bool
    {
        return $this->allows($user, self::transactionPermission($kind, $action));
    }

    /** Access to the internal vouchers of one kind, including the head's consolidated monitoring. */
    public function canViewTransactions(?User $user, string $kind): bool
    {
        self::transactionPermission($kind, 'draft');

        return $this->allows($user, 'finance.monitor')
            || collect(['draft', 'review', 'approve', 'execute'])->contains(fn (string $action): bool => $this->allowsTransaction($user, $kind, $action));
    }

    public function assert(?User $user, string $permission): void
    {
        if ($this->allows($user, $permission)) {
            return;
        }
        // Refused operations and exports are audited; refused page reads are not (the screens probe them).
        $request = request();
        if ($user && ($request->isMethod('post') || $request->routeIs('finance.report.*'))) {
            $this->audit('access', $permission, (int) $user->id, 'denied', ['route' => $request->route()?->getName(), 'path' => $request->path()]);
        }
        abort(403, 'لا تملك صلاحية هذه العملية');
    }

    public function audit(string $entity, int|string $id, int $actor, string $action, array $detail = []): void
    {
        DB::table('finance_audit_events')->insert([
            'entity_type' => $entity, 'entity_id' => (string) $id, 'actor_id' => $actor,
            'action' => $action, 'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
