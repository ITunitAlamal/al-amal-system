<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Finance\FinanceGuard;
use App\Services\Organization\EffectiveAssignments;
use App\Services\WorkflowVisibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in the rentals file:
 * - view and prepare: rentals.view / rentals.manage (role «مسؤول ملف الإيجارات», or heading the assets department);
 * - decide (applications, contracts, terminations, settlements): the executive director, through the workflow;
 * - record collections and settlement postings from the external accounting software: Accounts (finance.rentals.record).
 * People taking part in a rental request (an accountant reviewing a settlement, a head inspecting a unit) read
 * that record through the workflow's own visibility rule, never the whole file.
 */
final class RentalsAccess
{
    public const ASSETS_DEPARTMENT = 'ASSETS';

    public function __construct(private readonly FinanceGuard $finance, private readonly WorkflowVisibilityService $visibility) {}

    public function canView(User $user): bool
    {
        return $user->hasPermissionInAnyScope('rentals.view');
    }

    public function canManage(User $user): bool
    {
        return $user->hasPermissionInAnyScope('rentals.manage');
    }

    public function canDecide(User $user): bool
    {
        return $user->holdsDecisionPermission('rentals.decide');
    }

    public function canRecordFinance(User $user): bool
    {
        return $this->finance->allows($user, 'finance.rentals.record');
    }

    /** Whether the user may open one rental record: the whole file, or the request they take part in. */
    public function canSeeRecord(User $user, ?int $instanceId): bool
    {
        return $this->canView($user) || ($instanceId !== null && $this->visibility->accessLevel($user, $instanceId) !== null);
    }

    /** The department rental requests are filed under: the assets department, else the requester's own. */
    public function departmentId(User $requester): int
    {
        return (int) (DB::table('departments')->where('code', self::ASSETS_DEPARTMENT)->where('is_active', true)->value('id') ?: $requester->department_id);
    }

    /**
     * People who prepare the file and receive its alerts: the appointed assets head and the rentals officers.
     * The system administrator's technical access is left out.
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (User $user): bool => ! $user->isSystemAdmin() && $this->canManage($user))->values();
    }

    /**
     * The executive director(s) in office (an effective executive_director assignment on an active account), told of
     * contracts entering the renewal window or ending, for information only (SRS 3.8.7.1: «والإدارة التنفيذية»).
     *
     * @return list<int>
     */
    public function executiveIds(): array
    {
        return DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', 'executive_director')->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))
            ->distinct()->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
    }
}
