<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Departments\DepartmentWorkspace;
use App\Services\Kpi\KpiScope;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\WorkflowSubjects;
use App\Services\WorkflowVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures of the unified department «الموارد البشرية والخدمات العامة» (departments.code "HR" and its units):
 * employees, attendance, leave and the tasks of its people. Everything is read from the live tables; when a source
 * does not exist yet (employee contracts, custody) the card says so instead of showing a number. The department's
 * requests are listed by DepartmentFollowUp («متابعة القسم»).
 *
 * Audience: the appointed HR head, the system administrator and the executive director (the same audience as the
 * attendance screen). Confidential requests (complaints) never appear here.
 */
final class HrServicesDashboard
{
    /** Arabic labels of the daily attendance statuses written by AttendanceDailyService. */
    public const ATTENDANCE_STATUS = [
        'present' => 'حضور منتظم', 'late' => 'تأخير بعد مهلة السماح', 'early' => 'خروج مبكر',
        'missing_punches' => 'بلا بصمات', 'incomplete_punches' => 'بصمات ناقصة',
        'off' => 'يوم راحة', 'off_day_punch' => 'بصمة في يوم راحة', 'holiday_or_leave_work_review' => 'عمل في عطلة أو إجازة',
        'schedule_missing' => 'لا يوجد جدول دوام', 'unscheduled' => 'غير مجدول', 'exception' => 'استثناء معتمد',
        'invalid_schedule' => 'جدول دوام غير صالح', 'grace_unapproved' => 'مهلة سماح غير معتمدة',
        'official_holiday' => 'عطلة رسمية', 'emergency_holiday' => 'عطلة طارئة', 'approved_leave' => 'إجازة معتمدة',
        'in_progress' => 'قيد العمل', 'not_started' => 'لم يبدأ الدوام',
        'no_duty' => 'معفى من الدوام', 'exempt_punch' => 'معفى من البصمة', 'not_enrolled' => 'غير مسجل على جهاز البصمة',
    ];

    /**
     * Scheduled working days (same sample as the attendance indicator). A person not registered on any device stays in
     * the sample unverified, never counted as present; one exempt from punching works by an approved assignment.
     */
    private const EVALUATED = ['present', 'late', 'early', 'in_progress', 'missing_punches', 'incomplete_punches', 'exempt_punch', 'not_enrolled'];

    /** In progress: a verified entry today and the shift is still open; exempt from punching: approved offsite work. */
    private const ATTENDED = ['present', 'late', 'early', 'in_progress', 'exempt_punch'];

    /** Days a person should look at. Missing punches are a question for review, never proof of absence. */
    public const ATTENDANCE_REVIEW = ['late', 'early', 'missing_punches', 'incomplete_punches', 'off_day_punch',
        'holiday_or_leave_work_review', 'schedule_missing', 'invalid_schedule', 'grace_unapproved', 'unscheduled', 'not_enrolled'];

    public const LEAVE_STATUS = [
        'draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'manager_approved' => 'بانتظار الاعتماد النهائي',
        'pending_registration' => 'معتمدة — بانتظار التسجيل', 'returned' => 'مُعاد للتعديل', 'approved' => 'معتمدة ومسجلة',
        'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة',
    ];

    private const PENDING_LEAVE = ['submitted', 'manager_approved', 'pending_registration'];

    /** The unified department in the structure. */
    public const DEPARTMENT_CODE = 'HR';

    private const PER_PAGE = 10;

    public function __construct(private readonly WorkflowSubjects $subjects) {}

    public static function canView(User $user): bool
    {
        return $user->is_active && ($user->canManageEmployees() || app(ApprovalDelegations::class)->isExecutive($user));
    }

    /** The unified department's id, or null while the structure has no active department with that code. */
    public static function departmentId(): ?int
    {
        $id = DB::table('departments')->where('code', self::DEPARTMENT_CODE)->where('is_active', true)->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The unified department and its active units, at any depth.
     *
     * @return list<int>
     */
    public static function departmentIds(): array
    {
        $root = self::departmentId();
        $ids = $root === null ? [] : [$root];
        $frontier = $ids;
        while ($frontier !== []) {
            $children = DB::table('departments')->whereIn('parent_id', $frontier)->where('is_active', true)->whereNull('deleted_at')
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * Whether the user works in the unified department without heading it: their own department is the department
     * or one of its units, or they hold a role there today (the people StepAssignments offers a task to). The system
     * administrator and the executive director keep their own home pages.
     */
    public static function isMember(User $user): bool
    {
        if (! $user->is_active || $user->isHrDepartmentHead() || $user->isSystemAdmin() || app(ApprovalDelegations::class)->isExecutive($user)) {
            return false;
        }
        $ids = self::departmentIds();
        if ($ids === []) {
            return false;
        }

        return in_array((int) $user->department_id, $ids, true)
            || EffectiveAssignments::apply(DB::table('department_user_roles')->where('user_id', $user->id)->whereIn('department_id', $ids))->exists();
    }

    /**
     * Four main indicators and the secondary ones. A card has a value, or "unavailable" with the reason.
     *
     * @return array{main: list<array<string, mixed>>, secondary: list<array<string, mixed>>}
     */
    public function kpis(User $viewer, string $date): array
    {
        abort_unless(self::canView($viewer), 403);
        $employees = DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active');
        $active = (clone $employees)->count();
        $incomplete = (clone $employees)->where('onboarding_status', '<>', 'completed')->count();

        $day = DB::table('attendance_daily_results')->where('work_date', $date)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($day[$status] ?? 0));
        $evaluated = $sum(self::EVALUATED);
        $review = $sum(self::ATTENDANCE_REVIEW);

        $leaves = DB::table('employee_leave_requests')->whereIn('status', self::PENDING_LEAVE)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $onLeave = DB::table('employee_leave_requests')->whereIn('status', ['approved', 'pending_registration'])
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->count();

        // The same rows «متابعة القسم» lists under these two types, so the number and the list it opens agree.
        $services = app(DepartmentFollowUp::class)->counts($viewer, array_keys(DepartmentFollowUp::OWN_WORKFLOWS));

        $isToday = $date === now()->toDateString();
        $attendanceUrl = route('admin.attendance.index', [], false);

        return [
            'main' => [
                ['key' => 'employees', 'label' => 'إجمالي الموظفين الفعّالين', 'value' => $active,
                    'detail' => $incomplete ? 'منها ملفات غير مكتملة: '.$incomplete : 'كل الملفات الفعالة مكتملة',
                    'url' => $viewer->canManageEmployees() ? route('admin.employee-profiles.index', [], false) : null],
                ['key' => 'attendance', 'label' => $isToday ? 'الحضور اليوم' : 'الحضور في اليوم المحدد', 'value' => $evaluated ? $sum(self::ATTENDED) : null,
                    'detail' => $evaluated ? 'من أصل '.$evaluated.' يوم دوام مجدول'.($sum(['not_enrolled']) ? '، منها '.$sum(['not_enrolled']).' غير محققة (غير مسجل على جهاز البصمة) لا تُحسب حضورًا' : '')
                        : 'لم تُحتسب نتائج الحضور لهذا اليوم بعد (لا جداول دوام مسندة أو لم تُزامَن البصمات).',
                    'url' => $attendanceUrl],
                ['key' => 'leaves', 'label' => 'طلبات الإجازات المعلقة', 'value' => (int) $leaves->sum(),
                    'detail' => 'بانتظار تسجيل الموارد البشرية: '.(int) ($leaves['pending_registration'] ?? 0), 'url' => route('leaves.index', [], false)],
                ['key' => 'services', 'label' => 'طلبات الخدمات والصيانة المفتوحة', 'value' => $services['open'],
                    'detail' => 'المتأخرة: '.$services['overdue'].' · العاجلة والطارئة: '.$services['urgent'],
                    'url' => route('hr.dashboard', ['type' => implode(',', array_keys(DepartmentFollowUp::OWN_WORKFLOWS))], false)],
            ],
            'secondary' => [
                ['key' => 'review', 'label' => 'حالات حضور تحتاج مراجعة', 'value' => $day->isEmpty() ? null : $review,
                    'detail' => $day->isEmpty() ? 'لا توجد نتائج حضور لهذا اليوم.' : 'تأخير، خروج مبكر، بصمات ناقصة، جدول غير مكتمل، أو موظف غير مسجل على جهاز البصمة', 'url' => $attendanceUrl],
                ['key' => 'on_leave', 'label' => 'في إجازة في اليوم المحدد', 'value' => $onLeave, 'detail' => 'إجازات معتمدة تشمل هذا اليوم', 'url' => null],
                ['key' => 'contracts', 'label' => 'العقود القريبة من الانتهاء', 'value' => null,
                    'detail' => 'غير متاح: لا يوجد في النظام سجل لعقود الموظفين وتواريخ انتهائها بعد.', 'url' => null],
                $this->staffFilesCard($viewer),
                ...$this->documentCards($viewer),
            ],
        ];
    }

    /**
     * Staff documents that need attention (EmployeeDocumentAlerts): expired or near expiry, and files missing a
     * mandatory document, with the staff lists that show them. Null for a viewer outside the dashboard's audience.
     *
     * @return array{expired: int, expiring: ?int, days: ?int, missing: ?int, expiring_url: ?string, missing_url: ?string}|null
     */
    public function documentsCard(User $viewer): ?array
    {
        if (! self::canView($viewer)) {
            return null;
        }
        $manage = $viewer->canManageEmployees();

        return app(EmployeeDocumentAlerts::class)->counts() + [
            'expiring_url' => $manage ? route('admin.employee-profiles.index', ['state' => 'documents_expiring'], false) : null,
            'missing_url' => $manage ? route('admin.employee-profiles.index', ['state' => 'documents_missing'], false) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function documentCards(User $viewer): array
    {
        $documents = $this->documentsCard($viewer);

        return [
            ['key' => 'documents', 'label' => 'وثائق موظفين منتهية أو قريبة من الانتهاء',
                'value' => $documents['expired'] + (int) ($documents['expiring'] ?? 0),
                'detail' => 'منتهية: '.$documents['expired'].($documents['days'] === null
                    ? ' · لم تُحدد بعد مدة التنبيه قبل الانتهاء (إعدادات ملفات الموظفين)'
                    : ' · تنتهي خلال '.$documents['days'].' يومًا: '.(int) $documents['expiring']),
                'url' => $documents['expiring_url']],
            ['key' => 'missing_documents', 'label' => 'ملفات ينقصها وثائق إلزامية', 'value' => $documents['missing'],
                'detail' => $documents['missing'] === null ? 'غير متاح: لم يُحدَّد أي نوع وثيقة إلزاميًا في «ثوابت النظام».' : 'موظفون فعّالون بلا وثيقة حالية من نوع إلزامي',
                'url' => $documents['missing'] === null ? null : $documents['missing_url']],
        ];
    }

    /**
     * Open workflow tasks per member of the department (HR requirements §5 «فريق القسم»: المهام المسندة لكل موظف،
     * أوقات الإنجاز): how many each one holds, how many are past a real deadline, and since when the oldest waits.
     * Confidential workflows are left out, as on the task list.
     *
     * @return list<array{name: string, open: int, overdue: int, oldest: ?string}>
     */
    public function teamLoad(User $viewer): array
    {
        if (! self::canView($viewer)) {
            return [];
        }
        $people = DB::table('users')->whereIn('department_id', self::departmentIds() ?: [0])->where('is_active', true)->pluck('id');
        if ($people->isEmpty()) {
            return [];
        }

        return DB::table('workflow_participants as p')
            ->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as w', 'w.id', '=', 'v.workflow_definition_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->where('i.status', 'in_progress')->where('w.is_confidential', false)
            ->whereIn('p.user_id', $people)
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, count(*) AS open_count, count(*) FILTER (WHERE i.due_at IS NOT NULL AND i.due_at < ?) AS overdue_count, min(p.updated_at) AS oldest', [now()->toDateTimeString()])
            ->orderByDesc('overdue_count')->orderByDesc('open_count')->orderBy('u.name')
            ->get()->map(fn (object $row): array => ['name' => (string) $row->name, 'open' => (int) $row->open_count, 'overdue' => (int) $row->overdue_count, 'oldest' => $row->oldest])->all();
    }

    /** Employees with an outstanding name, photo or current-document review; one file per employee. */
    public function staffFilesForReview(): Builder
    {
        return DB::table('employees as e')->whereNull('e.deleted_at')->where(fn (Builder $query) => $query
            ->where('e.name_review_status', 'suggested')
            ->orWhereExists(fn (Builder $documents) => $documents->from('employee_documents as doc')->whereColumn('doc.employee_id', 'e.id')
                ->where('doc.verification_status', 'pending')->where('doc.is_current', true)->whereNull('doc.archived_at'))
            ->orWhereExists(fn (Builder $photos) => $photos->from('employee_photos as photo')->whereColumn('photo.employee_id', 'e.id')->where('photo.status', 'pending_review')));
    }

    /** @return array<string, mixed> */
    private function staffFilesCard(User $viewer): array
    {
        return ['key' => 'staff_files', 'label' => 'ملفات موظفين تحتاج مراجعة', 'value' => $this->staffFilesForReview()->count(),
            'detail' => 'وثائق أو صور أو أسماء تنتظر المراجعة؛ كل موظف يُحسب مرة واحدة',
            'url' => $viewer->canManageEmployees() ? route('admin.employee-profiles.index', ['state' => 'review'], false) : null];
    }

    /**
     * Leave requests still in process, those waiting for HR registration first.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLeaves(User $viewer, int $limit = 8): array
    {
        abort_unless(self::canView($viewer), 403);

        return $this->leaveQuery()->whereIn('l.status', self::PENDING_LEAVE)
            ->selectRaw('EXISTS (SELECT 1 FROM workflow_participants p WHERE p.workflow_instance_id = l.workflow_instance_id AND p.user_id = ? AND p.participation_type = ? AND p.is_active) AS mine', [$viewer->id, 'assignee'])
            ->orderByRaw("CASE l.status WHEN 'pending_registration' THEN 0 ELSE 1 END")->orderBy('l.submitted_at')
            ->limit($limit)->get()->map(fn (object $row): array => $this->leaveRow($row) + ['mine' => (bool) $row->mine])->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function recentLeaves(User $viewer, int $page): array
    {
        abort_unless(self::canView($viewer), 403);
        $paginator = $this->leaveQuery()->where('l.status', '<>', 'draft')
            ->orderByRaw('COALESCE(l.submitted_at, l.created_at) DESC')->paginate(self::PER_PAGE, ['*'], 'leaves_page', $page);

        return $this->page($paginator, fn (object $row): array => $this->leaveRow($row));
    }

    /**
     * The day's attendance cases that need a look.
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceExceptions(string $date, int $limit = 8): array
    {
        return $this->attendanceQuery($date)->whereIn('r.status', self::ATTENDANCE_REVIEW)
            ->orderByRaw("CASE r.status WHEN 'missing_punches' THEN 0 WHEN 'incomplete_punches' THEN 1 ELSE 2 END")->orderBy('e.first_name_ar')
            ->limit($limit)->get()->map(fn (object $row): array => $this->attendanceRow($row))->all();
    }

    /**
     * The day in one line for the head's home page: who attended out of the scheduled working days, how many days
     * need a look (the rows attendanceExceptions() lists, counted by the same query) and who is on leave. "attended"
     * and "scheduled" are null until the day is computed.
     *
     * @return array{date: string, attended: ?int, scheduled: ?int, review: int, on_leave: int, cases: list<array<string, mixed>>}
     */
    public function today(User $viewer, string $date, int $cases = 5): array
    {
        abort_unless(self::canView($viewer), 403);
        $day = DB::table('attendance_daily_results')->where('work_date', $date)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($day[$status] ?? 0));
        $scheduled = $sum(self::EVALUATED);

        return [
            'date' => $date,
            'attended' => $scheduled ? $sum(self::ATTENDED) : null,
            'scheduled' => $scheduled ?: null,
            'review' => $this->attendanceQuery($date)->whereIn('r.status', self::ATTENDANCE_REVIEW)->count(),
            'on_leave' => DB::table('employee_leave_requests')->whereIn('status', ['approved', 'pending_registration'])
                ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->count(),
            'cases' => $this->attendanceExceptions($date, $cases),
        ];
    }

    /**
     * Open workflow tasks of the HR department's people (and the viewer's own), those past a real deadline first.
     *
     * @return list<array<string, mixed>>
     */
    public function departmentTasks(User $viewer, int $limit = 10): array
    {
        if (! self::canView($viewer)) {
            return [];
        }
        $people = DB::table('users')->whereIn('department_id', self::departmentIds())->pluck('id')->push($viewer->id)->unique()->values();
        $rows = DB::table('workflow_participants as p')
            ->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as w', 'w.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->where('i.status', 'in_progress')->where('w.is_confidential', false)
            ->whereIn('p.user_id', $people)
            ->select(['i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.department_id', 'i.due_at', 'i.held_at',
                'w.name as workflow', 's.name as step', 'u.name as assignee'])
            ->selectSub($this->priority('name_ar'), 'priority')
            ->orderByRaw('CASE WHEN i.due_at IS NOT NULL AND i.due_at < ? THEN 0 ELSE 1 END', [now()->toDateTimeString()])
            ->orderByRaw('i.due_at NULLS LAST')->orderBy('i.updated_at')
            ->limit($limit)->get();
        $visible = $this->visibleInstances($viewer, $rows);

        return $rows->map(fn (object $row): array => [
            'reference' => $row->reference_no, 'title' => $row->workflow.' — '.$row->step, 'assignee' => $row->assignee,
            'priority' => $row->priority, 'due_at' => $row->due_at, 'overdue' => $this->isLate($row),
            'status' => $row->held_at ? 'معلقة مؤقتًا' : ($this->isLate($row) ? 'متأخرة' : 'قيد التنفيذ'),
            'url' => isset($visible[(int) $row->id]) ? $this->subjects->url($row) : null,
        ])->all();
    }

    /**
     * Links to the department's screens the viewer may open, each with an icon of the sidebar set.
     *
     * @return list<array{label: string, url: string, icon: string}>
     */
    public function links(User $viewer): array
    {
        $links = [];
        if ($viewer->canManageEmployees()) {
            $links[] = ['label' => 'ملفات الموظفين', 'url' => route('admin.employee-profiles.index', [], false), 'icon' => 'users'];
        }
        $links[] = ['label' => 'الحضور والانصراف', 'url' => route('admin.attendance.index', [], false), 'icon' => 'calendar'];
        $links[] = ['label' => 'الإجازات', 'url' => route('leaves.index', [], false), 'icon' => 'calendar'];
        if ($viewer->canManageEmployees()) {
            $links[] = ['label' => 'أرصدة الإجازات', 'url' => route('leaves.balances', [], false), 'icon' => 'list'];
        }
        if (app(DepartmentWorkspace::class)->departments($viewer) !== []) {
            $links[] = ['label' => 'قسمي: الفريق والوحدات', 'url' => route('department.workspace', [], false), 'icon' => 'users'];
        }
        if (KpiScope::resolve($viewer) !== null) {
            $links[] = ['label' => 'مؤشرات الأداء', 'url' => route('kpi.index', [], false), 'icon' => 'chart'];
        }
        $links[] = ['label' => 'مهامي', 'url' => route('tasks.index', [], false), 'icon' => 'inbox'];

        return $links;
    }

    private function leaveQuery(): Builder
    {
        return DB::table('employee_leave_requests as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->leftJoin('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->select(['l.id', 'l.status', 'l.start_date', 'l.end_date', 'l.requested_days', 'l.submitted_at', 'l.created_at',
                'e.first_name_ar', 'e.family_name_ar', 'd.name as department', 't.name_ar as leave_type', 's.name as step'])
            ->selectSub($this->holders('l.workflow_instance_id'), 'holders');
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveRow(object $row): array
    {
        return [
            'employee' => trim($row->first_name_ar.' '.$row->family_name_ar), 'department' => $row->department, 'type' => $row->leave_type ?? 'إجازة',
            'start_date' => $row->start_date, 'end_date' => $row->end_date, 'days' => $row->requested_days !== null ? (float) $row->requested_days : null,
            'submitted_at' => $row->submitted_at ?? $row->created_at, 'status' => self::LEAVE_STATUS[$row->status] ?? $row->status,
            'step' => in_array($row->status, self::PENDING_LEAVE, true) ? $row->step : null, 'holders' => $row->holders,
            'url' => route('leaves.show', ['leave' => $row->id], false),
        ];
    }

    private function attendanceQuery(string $date): Builder
    {
        return DB::table('attendance_daily_results as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->whereNull('e.deleted_at')->where('r.work_date', $date)
            ->select(['r.id', 'r.employee_id', 'r.work_date', 'r.status', 'r.expected_in', 'r.expected_out', 'r.actual_in', 'r.actual_out', 'r.late_minutes', 'r.early_minutes',
                'e.employee_number', 'e.first_name_ar', 'e.family_name_ar', 'd.name as department']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceRow(object $row): array
    {
        return [
            // The employee and the day, so a page can open exactly that day in the attendance ledger's day detail.
            'employee_id' => (int) $row->employee_id, 'work_date' => (string) $row->work_date,
            'employee' => trim($row->first_name_ar.' '.$row->family_name_ar), 'employee_number' => $row->employee_number, 'department' => $row->department,
            'expected_in' => $row->expected_in, 'expected_out' => $row->expected_out, 'actual_in' => $row->actual_in, 'actual_out' => $row->actual_out,
            'late_minutes' => (int) $row->late_minutes, 'early_minutes' => (int) $row->early_minutes,
            'status' => self::ATTENDANCE_STATUS[$row->status] ?? $row->status, 'status_code' => $row->status,
            'review' => in_array($row->status, self::ATTENDANCE_REVIEW, true),
        ];
    }

    /** The priority constant of a generic request (its code or Arabic name). */
    private function priority(string $column): Builder
    {
        return DB::table('custom_field_values as pv')->join('custom_field_definitions as pd', 'pd.id', '=', 'pv.definition_id')
            ->join('master_data_values as pm', 'pm.id', '=', 'pv.value_master_id')
            ->whereColumn('pv.entity_id', 'i.subject_id')->where('i.subject_type', 'aims_request')
            ->where('pv.entity_type', 'aims_request')->where('pd.code', 'priority')->limit(1)->select('pm.'.$column);
    }

    /** Names of whoever must act on the request now. */
    private function holders(string $instanceColumn = 'i.id'): Builder
    {
        return DB::table('workflow_participants as hp')->join('users as hu', 'hu.id', '=', 'hp.user_id')
            ->whereColumn('hp.workflow_instance_id', $instanceColumn)->where('hp.participation_type', 'assignee')->where('hp.is_active', true)
            ->selectRaw("string_agg(hu.name, '، ' ORDER BY hu.name)");
    }

    /** «متأخرة» only for a real deadline that has passed. */
    private function isLate(object $row): bool
    {
        return $row->due_at !== null && $row->due_at < now()->toDateTimeString();
    }

    /**
     * Which of these requests the viewer may open, with the rules of WorkflowVisibilityService in bulk: the system
     * administrator and the executive director every non-confidential request, others the requests they made, take
     * part in, or that come from a department they head. $supervised adds the requests whose current step the
     * viewer supervises (StepAssignments), which the caller already knows.
     *
     * @param  Collection<int, object>  $rows  rows with the instance id
     * @param  list<int>  $supervised  instance ids
     * @return array<int, true>
     */
    public function visibleInstances(User $viewer, Collection $rows, array $supervised = []): array
    {
        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty() || ! $viewer->is_active) {
            return [];
        }
        $open = DB::table('workflow_instances as i')->whereIn('i.id', $ids)
            ->whereNotIn('i.workflow_version_id', WorkflowVisibilityService::confidentialVersions());
        if (! $viewer->isSystemAdmin() && ! app(ApprovalDelegations::class)->isExecutive($viewer)) {
            $headed = DB::table('departments as d')->join('department_user_roles as a', fn ($join) => $join->on('a.department_id', '=', 'd.id')->where('a.user_id', $viewer->id))
                ->join('roles as r', 'r.id', '=', 'a.role_id')->where('r.code', 'department_head')->where('d.head_user_id', $viewer->id)->whereRaw(EffectiveAssignments::sql('a'))
                ->where('d.is_active', true)->whereNull('d.deleted_at')->pluck('d.id')->map(fn ($id): int => (int) $id)->all();
            $open->where(fn (Builder $q) => $q->where('i.requested_by', $viewer->id)->orWhereIn('i.department_id', $headed ?: [0])
                ->orWhereIn('i.id', $supervised ?: [0])
                ->orWhereExists(fn (Builder $p) => $p->from('workflow_participants as vp')->whereColumn('vp.workflow_instance_id', 'i.id')
                    ->where('vp.user_id', $viewer->id)->where('vp.is_active', true)->whereNull('vp.revoked_at')
                    ->whereIn('vp.visibility_scope', ['public_status', 'operational', 'full_authorized'])));
        }

        return $open->pluck('i.id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    private function page(LengthAwarePaginator $paginator, callable $map): array
    {
        return ['data' => collect($paginator->items())->map($map)->values()->all(), 'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(), 'total' => $paginator->total()];
    }
}
