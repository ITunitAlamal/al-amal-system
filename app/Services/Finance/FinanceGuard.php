<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceGuard
{
    /**
     * Business decisions (review, approval, money execution). The system administrator's technical access never
     * covers them (SRS §2.4 and §3.1.4.1, ED requirements doc §2): they need the role permission and assignment.
     */
    public const BUSINESS_DECISIONS = [
        'finance.transaction.review', 'finance.transaction.approve', 'finance.transaction.execute',
        'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
        'finance.budget.approve', 'finance.payroll.approve', 'finance.fund.approve',
        'finance.transfer.approve', 'finance.transfer.execute', 'finance.reversal.approve', 'finance.stock.count.approve',
        // Recording collections and settlement postings from the accounting software is a financial record the
        // administrator's technical role must never make (no fictitious postings).
        'finance.rentals.record',
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

    /** @return array<string, string> permission code => 'grant'|'deny' */
    public function overridesFor(User $user): array
    {
        if (! isset($this->overrides[$user->id])) {
            // A dated grant stops by itself the day after its end date; a withdrawal ('deny') has no end.
            $this->overrides[$user->id] = Schema::hasTable('finance_user_permissions')
                ? DB::table('finance_user_permissions')->where('user_id', $user->id)
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()->toDateString()))
                    ->pluck('effect', 'permission_code')->all()
                : [];
        }

        return $this->overrides[$user->id];
    }

    /**
     * Bookkeeping kept in the association's accounting software while Accounts run the documentary cycle only
     * (owner decision 2026-10-23). In that mode these permissions are refused to everyone; their data stays.
     */
    public const ACCOUNTING_PERMISSIONS = [
        'finance.treasury.manage', 'finance.budget.manage', 'finance.budget.transfer', 'finance.budget.approve',
        'finance.transaction.draft', 'finance.transaction.review', 'finance.transaction.approve', 'finance.transaction.execute',
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

        return $this->heads[$user->id] ??= DB::table('departments')->where('id', $this->departmentId())
            ->where('head_user_id', $user->id)->whereNull('deleted_at')->exists();
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

        return $this->executives[$user->id] ??= DB::table('departments')->where('code', 'EXEC')
            ->where('head_user_id', $user->id)->whereNull('deleted_at')->exists();
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
        if (in_array($permission, self::ACCOUNTING_PERMISSIONS, true) && $this->documentaryMode()) {
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
            || $user->roleAssignments()->whereHas('role.permissions', fn ($q) => $q->where('code', $permission))->exists();
        if (! $hasRolePermission) {
            return false;
        }
        if ($permission === 'finance.procurement.board_approve') {
            return DB::table('finance_board_approvers')->where('user_id', $user->id)->where('is_active', true)->exists();
        }
        if (in_array($permission, ['finance.procurement.director_approve', 'finance.transaction.approve', 'finance.budget.approve', 'finance.payroll.approve', 'finance.fund.approve', 'finance.transfer.approve', 'finance.reversal.approve'], true)) {
            return $this->activeAssignments((int) $user->id, 'director_approver')->exists();
        }

        return $this->activeAssignments((int) $user->id)->exists();
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
