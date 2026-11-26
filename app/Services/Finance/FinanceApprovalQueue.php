<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance items waiting for a decision from this user, per the finance permissions they actually hold.
 * One source for the approvals page and the «مساحتي» summary. A user never sees items they created.
 */
final class FinanceApprovalQueue
{
    /**
     * permission => list of [kind, label, table, state, url pattern, amount column]
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>>
     */
    private const QUEUES = [
        'finance.procurement.director_approve' => [['purchase', 'اعتماد طلب شراء', 'finance_purchase_requests', 'pending_director', '/finance/purchases/{id}/director', 'total_amount']],
        'finance.transaction.approve' => [['transaction', 'اعتماد سند مالي', 'finance_transactions', 'pending_director', '/finance/transactions/{id}/approve', 'amount']],
        'finance.budget.approve' => [
            ['budget_transfer', 'اعتماد مناقلة', 'finance_budget_transfers', 'pending', '/finance/budgets/transfers/{id}/approve', 'amount'],
            ['budget_exception', 'اعتماد استثناء', 'finance_budget_exceptions', 'pending', '/finance/budgets/exceptions/{id}/approve', 'amount'],
        ],
        'finance.stock.count.approve' => [['inventory', 'اعتماد جرد', 'finance_inventory_counts', 'pending', '/finance/stock-counts/{id}/approve', 'amount']],
        'finance.payroll.approve' => [['payroll', 'اعتماد مسير رواتب', 'finance_payroll_batches', 'pending_approval', '/finance/payroll/{id}/approve', 'total']],
        // The run recorded from the external software, reviewed, awaiting the executive director (2026-11-02).
        'finance.payroll.authorize' => [['payroll_run', 'اعتماد مسير الرواتب بعد مراجعته', 'finance_payroll_batches', 'pending_executive', '/finance/payroll-runs/{id}/authorize', 'total']],
        'finance.fund.approve' => [['fund', 'اعتماد حركة تخصيص', 'finance_restricted_movements', 'pending', '/finance/fund-movements/{id}/approve', 'amount']],
        'finance.transfer.approve' => [['transfer', 'اعتماد تحويل', 'finance_internal_transfers', 'pending_approval', '/finance/transfers/{id}/approve', 'amount']],
    ];

    private const BOARD = ['purchase', 'موافقة أمين الصندوق على طلب شراء', 'finance_purchase_requests', 'pending_board_treasurer', '/finance/purchases/{id}/board', 'total_amount'];

    public function __construct(private readonly FinanceGuard $guard) {}

    public function isBoardApprover(User $user): bool
    {
        return $this->guard->allows($user, 'finance.procurement.board_approve')
            && DB::table('finance_board_approvers')->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    /** Whether the user approves anything in finance at all. */
    public function isApprover(User $user): bool
    {
        if ($this->isBoardApprover($user)) {
            return true;
        }
        foreach (array_keys(self::QUEUES) as $permission) {
            if ($this->guard->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{kind: string, id: int, label: string, amount: mixed, url: string}>
     */
    public function entries(User $user, int $perQueue = 100): array
    {
        $queue = [];
        foreach ($this->sources($user) as [$kind, $label, $table, $state, $path, $money]) {
            $rows = DB::table($table)->where('state', $state)->orderBy('id')->limit($perQueue)->get();
            foreach ($rows as $row) {
                if (isset($row->created_by) && (int) $row->created_by === (int) $user->id) {
                    continue;
                }
                $queue[] = ['kind' => $kind, 'id' => (int) $row->id, 'label' => $label, 'amount' => $row->{$money} ?? null, 'url' => str_replace('{id}', (string) $row->id, $path)];
            }
        }

        return $queue;
    }

    /** Counts per label, for summaries. @return array<string, int> */
    public function counts(User $user): array
    {
        $counts = [];
        foreach ($this->sources($user) as [, $label, $table, $state]) {
            $count = DB::table($table)->where('state', $state)
                ->when(Schema::hasColumn($table, 'created_by'), fn ($q) => $q->where(fn ($inner) => $inner->whereNull('created_by')->orWhere('created_by', '!=', $user->id)))
                ->count();
            if ($count > 0) {
                $counts[$label] = ($counts[$label] ?? 0) + $count;
            }
        }

        return $counts;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function sources(User $user): array
    {
        $sources = $this->isBoardApprover($user) ? [self::BOARD] : [];
        foreach (self::QUEUES as $permission => $queues) {
            if ($this->guard->allows($user, $permission)) {
                array_push($sources, ...$queues);
            }
        }

        return $sources;
    }
}
