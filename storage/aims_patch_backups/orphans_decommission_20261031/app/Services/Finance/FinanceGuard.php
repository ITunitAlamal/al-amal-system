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
        // Same for sponsorship/donation collections, payouts and payout-beneficiary approval.
        'finance.sponsorships.record',
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
            $this->overrides[$user->id] = Schema::hasTable('finance_user_permissions')
                ? DB::table('finance_user_permissions')->where('user_id', $user->id)->pluck('effect', 'permission_code')->all()
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
        // An active system administrator manages Finance setup and records, but never decides a transaction
        // through the technical role; a business decision goes through the role permission check below.
        if ($user->isSystemAdmin() && ! in_array($permission, self::BUSINESS_DECISIONS, true)) {
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
            return DB::table('finance_staff_assignments')->where('user_id', $user->id)->where('specialization', 'director_approver')->where('is_active', true)->exists();
        }

        return DB::table('finance_staff_assignments')->where('user_id', $user->id)->where('is_active', true)->exists();
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
