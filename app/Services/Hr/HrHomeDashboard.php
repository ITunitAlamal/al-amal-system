<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Finance\DepartmentBudgets;
use App\Services\Progress\TransactionTracker;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The home page of the head of the unified department (HR, general services and maintenance): the one dashboard of
 * the head, at «مساحتي» (/ui-preview). In reading order:
 *  ① «بانتظار إجرائي» — the head's tasks, the same list and number as «مهامي» (TransactionTracker::tasksOf);
 *  ② «جاهز للإسناد» — a marked part of ①: the department's steps the head may hand to an employee;
 *  ③ «متابعة أعمال القسم» — the department's work that does not wait for the head (DepartmentFollowUp);
 *  ④ «حضور اليوم وإجازاته» — one line of figures (with the staff files waiting for review) and the first cases to
 *    review, then the department's budget;
 *  ⑤ the head's own «طلباتي»; ⑥ the department's links.
 * The full lists live on «متابعة القسم» (/hr-services), «مهامي» and «الحضور والانصراف»; charts and head counts on
 * «مؤشرات الأداء». The heavier blocks load after the page, each in its own group.
 */
class HrHomeDashboard
{
    public function __construct(
        private readonly DepartmentHome $home,
        private readonly HrServicesDashboard $dashboard,
        private readonly TransactionTracker $tracker,
        private readonly DepartmentBudgets $budgets,
    ) {}

    public function props(Request $request): array
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->isHrDepartmentHead(), 403);

        return [
            'today' => now()->toDateString(),
            'hrActions' => Inertia::defer(fn (): array => $this->actions($user), 'hr-actions'),
            'hrFollowUp' => Inertia::defer(fn (): ?array => $this->home->followUp($user), 'hr-follow-up'),
            'hrSummary' => Inertia::defer(fn (): array => $this->summary($user), 'hr-summary'),
            'departmentBudgets' => Inertia::defer(fn (): array => $this->budgets->summary($user, now()->format('Y-m')), 'department-budgets'),
            'myRequests' => Inertia::defer(fn (): array => $this->tracker->overview($user, 5), 'hr-requests'),
            'links' => $this->dashboard->links($user),
        ];
    }

    /**
     * ① and ②: what waits for the head, with its parts (ready to assign, field work to document, past a real deadline).
     *
     * @return array<string, mixed>
     */
    public function actions(User $user): array
    {
        return $this->home->waiting($user, $this->tracker->tasksOf($user));
    }

    /**
     * ④: today's attendance in one line, the first cases that need a look, and the leave requests waiting for the
     * head's action (the list «مهامي» opens with ?type=leave, counted from the same tasks).
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        return [
            ...$this->dashboard->today($user, now()->toDateString()),
            'leave_tasks' => $this->tracker->tasksOf($user)->where('type', TransactionTracker::LEAVE_TYPE)->count(),
            'leave_tasks_url' => route('tasks.index', ['type' => TransactionTracker::LEAVE_TYPE], false),
            // «ملفات موظفين تحتاج مراجعة»: names, photos or current documents waiting for review, one per employee
            // (imported names have no task of their own in «مهامي»).
            'staff_files' => $this->dashboard->staffFilesForReview()->count(),
            'staff_files_url' => $user->canManageEmployees() ? route('admin.employee-profiles.index', ['state' => 'review'], false) : null,
            // New files and changes HR staff prepared, waiting for the head's approval (EmployeeFileChanges).
            'file_changes' => EmployeeFileChanges::available() ? app(EmployeeFileChanges::class)->pendingCount() : null,
            'file_changes_url' => EmployeeFileChanges::available() ? EmployeeFileChanges::url() : null,
            // Documents expired or near expiry and files missing a mandatory document (EmployeeDocumentAlerts).
            'documents' => $this->dashboard->documentsCard($user),
            'updatedAt' => now()->toIso8601String(),
        ];
    }
}
