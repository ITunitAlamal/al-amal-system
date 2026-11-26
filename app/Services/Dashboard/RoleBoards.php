<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Dashboard\Boards\DepartmentHeadBoard;
use App\Services\Dashboard\Boards\ExecutiveBoard;
use App\Services\Dashboard\Boards\FinanceUnitBoards;
use App\Services\Dashboard\Boards\OrphansBoards;
use App\Services\Dashboard\Boards\ServiceBoards;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Rentals\RentalsDashboard;
use App\Services\Workflow\ApprovalDelegations;
use Illuminate\Support\Facades\Log;

/**
 * The role dashboards of «مساحتي»: one board per real role the user holds, decided on the server from the same access
 * rules the modules use. A board that fails to build is returned as an error state (the others still show);
 * everyone without a role board gets the employee board.
 */
final class RoleBoards
{
    public function __construct(private readonly ExecutiveBoard $executive, private readonly DepartmentHeadBoard $heads,
        private readonly OrphansBoards $orphans, private readonly ServiceBoards $services) {}

    /** @return list<array{key: string, label: string}> what will be built, cheap (for tab labels before loading) */
    public function plan(User $user): array
    {
        $plan = [];
        if (app(ApprovalDelegations::class)->isExecutive($user)) {
            $plan[] = ['executive', null];
        }
        foreach ($this->orphans->kinds($user) as $kind) {
            $plan[] = ['orphans', $kind];
        }
        if ($user->canManageEmployees()) {
            $plan[] = ['hr', null];
        }
        $queue = app(FinanceApprovalQueue::class);
        if ($queue->isApprover($user)) {
            $plan[] = ['finance', null];
        }
        // One board per Accounts unit the user works in (or the head's consolidated monitoring).
        foreach (app(FinanceUnitBoards::class)->units($user) as $unit) {
            $plan[] = ['finance', $unit];
        }
        if (RentalsDashboard::canView($user) && ! app(ApprovalDelegations::class)->isExecutive($user)) {
            $plan[] = ['rentals', null];
        }
        foreach (DepartmentHeadBoard::departments($user, array_column($plan, 0)) as $department) {
            $plan[] = ['department', $department];
        }
        if ($user->isSystemAdmin()) {
            // The administrator's own board opens first; business boards follow.
            array_unshift($plan, ['sysadmin', null]);
        }
        if ($plan === []) {
            $plan[] = ['employee', null];
        }

        return $plan;
    }

    /** The orphans department's own boards (its «الرئيسية»). @return list<array<string, mixed>> */
    public function orphans(User $user): array
    {
        return $this->build($user, array_map(fn (string $kind): array => ['orphans', $kind], $this->orphans->kinds($user)));
    }

    /** @return list<array<string, mixed>> */
    public function for(User $user): array
    {
        return $this->build($user, $this->plan($user));
    }

    private function build(User $user, array $plan): array
    {
        $boards = [];
        foreach ($plan as [$kind, $arg]) {
            try {
                $boards[] = match ($kind) {
                    'executive' => $this->executive->build($user),
                    'orphans' => $this->orphans->build($user, $arg),
                    'hr' => $this->services->hr($user),
                    'finance' => $arg ? app(FinanceUnitBoards::class)->build($user, $arg) : $this->services->finance($user),
                    'rentals' => $this->services->rentals($user),
                    'department' => $this->heads->build($user, $arg),
                    'sysadmin' => $this->services->systemAdmin($user),
                    default => $this->services->employee($user),
                } + ['updated_at' => now()->toIso8601String()];
            } catch (\Throwable $e) {
                Log::warning('Role board failed', ['board' => $kind, 'user' => $user->id, 'error' => $e->getMessage()]);
                $boards[] = ['key' => $kind.($arg && is_string($arg) ? '-'.$arg : ''), 'title' => 'لوحة غير متاحة الآن', 'error' => 'تعذر تحميل هذه اللوحة. أعد المحاولة بعد قليل؛ بقية الصفحة تعمل.'];
            }
        }

        return $boards;
    }
}
