<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\WorkflowSubjects;
use App\Services\WorkflowVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data for «الموارد البشرية والخدمات العامة»: the HR head's daily picture of employees, attendance, leave,
 * tasks and general-service and maintenance requests. Everything is read from the live tables; when a source does
 * not exist yet (employee contracts, custody) the card says so instead of showing a number.
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
    ];

    /** Scheduled working days evaluated against punches (same definition as the attendance indicator). */
    private const EVALUATED = ['present', 'late', 'early', 'in_progress', 'missing_punches', 'incomplete_punches'];

    /** In progress: a verified entry today and the shift is still open. */
    private const ATTENDED = ['present', 'late', 'early', 'in_progress'];

    /** Days a person should look at. Missing punches are a question for review, never proof of absence. */
    public const ATTENDANCE_REVIEW = ['late', 'early', 'missing_punches', 'incomplete_punches', 'off_day_punch',
        'holiday_or_leave_work_review', 'schedule_missing', 'invalid_schedule', 'grace_unapproved', 'unscheduled'];

    public const LEAVE_STATUS = [
        'draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'manager_approved' => 'بانتظار الاعتماد النهائي',
        'pending_registration' => 'معتمدة — بانتظار التسجيل', 'returned' => 'مُعاد للتعديل', 'approved' => 'معتمدة ومسجلة',
        'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة',
    ];

    private const PENDING_LEAVE = ['submitted', 'manager_approved', 'pending_registration'];

    /** Workflows of general services and maintenance (their published definitions). */
    private const SERVICE_WORKFLOWS = ['MAINTENANCE', 'SERVICE'];

    public const SERVICE_FILTERS = ['open' => 'المفتوحة', 'overdue' => 'المتأخرة', 'urgent' => 'العاجلة والطارئة', 'closed' => 'المغلقة خلال 30 يومًا'];

    private const PER_PAGE = 10;

    public function __construct(private readonly WorkflowSubjects $subjects) {}

    public static function canView(User $user): bool
    {
        return $user->is_active && ($user->canManageEmployees() || app(ApprovalDelegations::class)->isExecutive($user));
    }

    /**
     * Four main indicators and three secondary ones. A card has a value, or "unavailable" with the reason.
     *
     * @return array{main: list<array<string, mixed>>, secondary: list<array<string, mixed>>}
     */
    public function kpis(User $viewer, string $date): array
    {
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

        $open = $this->serviceInstances()->where('i.status', 'in_progress');
        $openCount = (clone $open)->count();
        $overdue = $this->overdue(clone $open)->count();
        $urgent = $this->urgent(clone $open)->count();

        $isToday = $date === now()->toDateString();
        $attendanceUrl = route('admin.attendance.index', [], false);

        return [
            'main' => [
                ['key' => 'employees', 'label' => 'إجمالي الموظفين الفعّالين', 'value' => $active,
                    'detail' => $incomplete ? 'منها ملفات غير مكتملة: '.$incomplete : 'كل الملفات الفعالة مكتملة',
                    'url' => $viewer->canManageEmployees() ? route('admin.employee-profiles.index', [], false) : null],
                ['key' => 'attendance', 'label' => $isToday ? 'الحضور اليوم' : 'الحضور في اليوم المحدد', 'value' => $evaluated ? $sum(self::ATTENDED) : null,
                    'detail' => $evaluated ? 'من أصل '.$evaluated.' يوم دوام مُقيَّم بالبصمات' : 'لم تُحتسب نتائج الحضور لهذا اليوم بعد (لا جداول دوام مسندة أو لم تُزامَن البصمات).',
                    'url' => $attendanceUrl],
                ['key' => 'leaves', 'label' => 'طلبات الإجازات المعلقة', 'value' => (int) $leaves->sum(),
                    'detail' => 'بانتظار تسجيل الموارد البشرية: '.(int) ($leaves['pending_registration'] ?? 0), 'url' => route('leaves.index', [], false)],
                ['key' => 'services', 'label' => 'طلبات الخدمات والصيانة المفتوحة', 'value' => $openCount,
                    'detail' => 'المتأخرة: '.$overdue.' · العاجلة والطارئة: '.$urgent, 'url' => '#services'],
            ],
            'secondary' => [
                ['key' => 'review', 'label' => 'حالات حضور تحتاج مراجعة', 'value' => $day->isEmpty() ? null : $review,
                    'detail' => $day->isEmpty() ? 'لا توجد نتائج حضور لهذا اليوم.' : 'تأخير، خروج مبكر، بصمات ناقصة، أو جدول غير مكتمل', 'url' => $attendanceUrl],
                ['key' => 'on_leave', 'label' => 'في إجازة في اليوم المحدد', 'value' => $onLeave, 'detail' => 'إجازات معتمدة تشمل هذا اليوم', 'url' => null],
                ['key' => 'contracts', 'label' => 'العقود القريبة من الانتهاء', 'value' => null,
                    'detail' => 'غير متاح: لا يوجد في النظام سجل لعقود الموظفين وتواريخ انتهائها بعد.', 'url' => null],
                $this->staffFilesCard($viewer),
            ],
        ];
    }

    /**
     * Counts only: documents waiting for verification, photos waiting for review and imported names not yet reviewed.
     *
     * @return array<string, mixed>
     */
    private function staffFilesCard(User $viewer): array
    {
        $documents = DB::table('employee_documents')->where('verification_status', 'pending')->where('is_current', true)->whereNull('archived_at')->count();
        $photos = DB::table('employee_photos')->where('status', 'pending_review')->count();
        $names = DB::table('employees')->whereNull('deleted_at')->where('name_review_status', 'suggested')->count();

        return ['key' => 'staff_files', 'label' => 'ملفات الموظفين تحتاج مراجعة', 'value' => $documents + $photos + $names,
            'detail' => 'وثائق بانتظار التوثيق: '.$documents.' · صور بانتظار المراجعة: '.$photos.' · أسماء مستوردة غير مراجعة: '.$names,
            'url' => $viewer->canManageEmployees() ? route('admin.employee-profiles.index', ['state' => 'name_review'], false) : null];
    }

    /**
     * Leave requests still in process, those waiting for HR registration first.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLeaves(User $viewer, int $limit = 8): array
    {
        return $this->leaveQuery()->whereIn('l.status', self::PENDING_LEAVE)
            ->selectRaw('EXISTS (SELECT 1 FROM workflow_participants p WHERE p.workflow_instance_id = l.workflow_instance_id AND p.user_id = ? AND p.participation_type = ? AND p.is_active) AS mine', [$viewer->id, 'assignee'])
            ->orderByRaw("CASE l.status WHEN 'pending_registration' THEN 0 ELSE 1 END")->orderBy('l.submitted_at')
            ->limit($limit)->get()->map(fn (object $row): array => $this->leaveRow($row) + ['mine' => (bool) $row->mine])->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function recentLeaves(int $page): array
    {
        $paginator = $this->leaveQuery()->where('l.status', '<>', 'draft')
            ->orderByRaw('COALESCE(l.submitted_at, l.created_at) DESC')->paginate(self::PER_PAGE, ['*'], 'leaves_page', $page);

        return $this->page($paginator, fn (object $row): array => $this->leaveRow($row));
    }

    /**
     * The day's attendance cases that need a look.
     *
     * @return list<array<string, mixed>>
     */
    public function attendanceExceptions(string $date, int $limit = 8): array
    {
        return $this->attendanceQuery($date)->whereIn('r.status', self::ATTENDANCE_REVIEW)
            ->orderByRaw("CASE r.status WHEN 'missing_punches' THEN 0 WHEN 'incomplete_punches' THEN 1 ELSE 2 END")->orderBy('e.first_name_ar')
            ->limit($limit)->get()->map(fn (object $row): array => $this->attendanceRow($row))->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function attendanceDay(string $date, ?string $search, ?string $status, int $page): array
    {
        $query = $this->attendanceQuery($date)
            ->when($status, fn (Builder $q) => $q->where('r.status', $status))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereRaw("(COALESCE(e.first_name_ar, '') || ' ' || COALESCE(e.family_name_ar, '')) ILIKE ?", ['%'.$search.'%'])
                ->orWhere('e.employee_number', 'ilike', '%'.$search.'%')))
            ->orderBy('e.first_name_ar')->orderBy('e.family_name_ar');

        return $this->page($query->paginate(15, ['*'], 'attendance_page', $page), fn (object $row): array => $this->attendanceRow($row));
    }

    /**
     * Open workflow tasks of the HR department's people (and the viewer's own), late ones first.
     *
     * @return list<array<string, mixed>>
     */
    public function departmentTasks(User $viewer, int $limit = 10): array
    {
        $people = DB::table('users')->whereIn('department_id', $this->hrDepartmentIds())->pluck('id')->push($viewer->id)->unique()->values();
        $rows = DB::table('workflow_participants as p')
            ->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as w', 'w.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->where('i.status', 'in_progress')->where('w.is_confidential', false)
            ->whereIn('p.user_id', $people)
            ->select(['i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.department_id', 'i.due_at', 'i.sla_breached_at', 'i.held_at',
                'w.name as workflow', 's.name as step', 'u.name as assignee'])
            ->selectSub($this->priority('name_ar'), 'priority')
            ->orderByRaw('CASE WHEN i.sla_breached_at IS NOT NULL OR (i.due_at IS NOT NULL AND i.due_at < now()) THEN 0 ELSE 1 END')
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
     * General-service and maintenance requests by filter, with location, priority and who holds them now.
     *
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function services(User $viewer, string $filter, int $page): array
    {
        $query = $this->serviceInstances()
            ->select(['i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.department_id', 'i.status', 'i.due_at', 'i.sla_breached_at',
                'i.held_at', 'i.started_at', 'i.completed_at', 'w.name as category', 's.name as step', 'd.name as department'])
            ->selectSub($this->field('location'), 'location')
            ->selectSub($this->priority('name_ar'), 'priority')
            ->selectSub($this->holders(), 'holders');
        match ($filter) {
            'overdue' => $this->overdue($query->where('i.status', 'in_progress')),
            'urgent' => $this->urgent($query->where('i.status', 'in_progress')),
            'closed' => $query->whereIn('i.status', ['completed', 'rejected'])->where('i.completed_at', '>=', now()->subDays(30)),
            default => $query->where('i.status', 'in_progress'),
        };
        $query->orderByRaw('CASE WHEN i.sla_breached_at IS NOT NULL OR (i.due_at IS NOT NULL AND i.due_at < now()) THEN 0 ELSE 1 END')
            ->orderByDesc('i.started_at');
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'services_page', $page);
        $visible = $this->visibleInstances($viewer, collect($paginator->items()));

        return $this->page($paginator, fn (object $row): array => [
            'reference' => $row->reference_no, 'category' => $row->category, 'department' => $row->department, 'location' => $row->location,
            'priority' => $row->priority, 'step' => $row->status === 'in_progress' ? $row->step : '—', 'holders' => $row->holders,
            'due_at' => $row->due_at, 'overdue' => $row->status === 'in_progress' && $this->isLate($row),
            'status' => match (true) {
                $row->status === 'completed' => 'مكتملة', $row->status === 'rejected' => 'مرفوضة',
                $row->held_at !== null => 'معلقة مؤقتًا', $this->isLate($row) => 'متأخرة', default => 'قيد المعالجة',
            },
            'url' => isset($visible[(int) $row->id]) ? route('workflows.show', ['publicId' => $row->public_id], false) : null,
        ]);
    }

    /**
     * Links to the existing HR screens the viewer may open.
     *
     * @return list<array{label: string, url: string}>
     */
    public function links(User $viewer): array
    {
        $links = [];
        if ($viewer->canManageEmployees()) {
            $links[] = ['label' => 'ملفات الموظفين', 'url' => route('admin.employee-profiles.index', [], false)];
        }
        $links[] = ['label' => 'الحضور والانصراف', 'url' => route('admin.attendance.index', [], false)];
        $links[] = ['label' => 'الإجازات', 'url' => route('leaves.index', [], false)];
        if ($viewer->canManageEmployees()) {
            $links[] = ['label' => 'أرصدة الإجازات', 'url' => route('leaves.balances', [], false)];
        }
        $links[] = ['label' => 'مهامي', 'url' => route('tasks.index', [], false)];

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
            ->select(['r.id', 'r.status', 'r.expected_in', 'r.expected_out', 'r.actual_in', 'r.actual_out', 'r.late_minutes', 'r.early_minutes',
                'e.employee_number', 'e.first_name_ar', 'e.family_name_ar', 'd.name as department']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceRow(object $row): array
    {
        return [
            'employee' => trim($row->first_name_ar.' '.$row->family_name_ar), 'employee_number' => $row->employee_number, 'department' => $row->department,
            'expected_in' => $row->expected_in, 'expected_out' => $row->expected_out, 'actual_in' => $row->actual_in, 'actual_out' => $row->actual_out,
            'late_minutes' => (int) $row->late_minutes, 'early_minutes' => (int) $row->early_minutes,
            'status' => self::ATTENDANCE_STATUS[$row->status] ?? $row->status, 'status_code' => $row->status,
            'review' => in_array($row->status, self::ATTENDANCE_REVIEW, true),
        ];
    }

    private function serviceInstances(): Builder
    {
        return DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as w', 'w.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->whereIn('w.code', self::SERVICE_WORKFLOWS)->where('w.is_confidential', false);
    }

    private function overdue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('i.sla_breached_at')->orWhere(fn (Builder $due) => $due->whereNotNull('i.due_at')->where('i.due_at', '<', now())));
    }

    private function urgent(Builder $query): Builder
    {
        return $query->whereExists(fn (Builder $q) => $q->from('custom_field_values as uv')
            ->join('custom_field_definitions as ud', 'ud.id', '=', 'uv.definition_id')
            ->join('master_data_values as um', 'um.id', '=', 'uv.value_master_id')
            ->whereColumn('uv.entity_id', 'i.subject_id')->where('i.subject_type', 'aims_request')
            ->where('uv.entity_type', 'aims_request')->where('ud.code', 'priority')->whereIn('um.code', ['urgent', 'emergency']));
    }

    /** A custom field value of a generic request, by field code. */
    private function field(string $code): Builder
    {
        return DB::table('custom_field_values as fv')->join('custom_field_definitions as fd', 'fd.id', '=', 'fv.definition_id')
            ->whereColumn('fv.entity_id', 'i.subject_id')->where('i.subject_type', 'aims_request')
            ->where('fv.entity_type', 'aims_request')->where('fd.code', $code)->limit(1)->select('fv.value_text');
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

    private function isLate(object $row): bool
    {
        return $row->sla_breached_at !== null || ($row->due_at !== null && $row->due_at < now()->toDateTimeString());
    }

    /**
     * Which of these requests the viewer may open, with the rules of WorkflowVisibilityService in bulk: the system
     * administrator and the executive director every non-confidential request, others the requests they take part in
     * or that come from a department they head.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, true>
     */
    private function visibleInstances(User $viewer, Collection $rows): array
    {
        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        if ($viewer->isSystemAdmin() || app(ApprovalDelegations::class)->isExecutive($viewer)) {
            return $ids->flip()->map(fn (): bool => true)->all();
        }
        $headed = DB::table('departments as d')->join('department_user_roles as a', fn ($join) => $join->on('a.department_id', '=', 'd.id')->where('a.user_id', $viewer->id))
            ->join('roles as r', 'r.id', '=', 'a.role_id')->where('r.code', 'department_head')->where('d.head_user_id', $viewer->id)
            ->where('d.is_active', true)->whereNull('d.deleted_at')->pluck('d.id')->map(fn ($id): int => (int) $id)->all();

        return DB::table('workflow_instances as i')->whereIn('i.id', $ids)
            ->whereNotIn('i.workflow_version_id', WorkflowVisibilityService::confidentialVersions())
            ->where(fn (Builder $q) => $q->where('i.requested_by', $viewer->id)->orWhereIn('i.department_id', $headed ?: [0])
                ->orWhereExists(fn (Builder $p) => $p->from('workflow_participants as vp')->whereColumn('vp.workflow_instance_id', 'i.id')
                    ->where('vp.user_id', $viewer->id)->where('vp.is_active', true)->whereNull('vp.revoked_at')))
            ->pluck('i.id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * @return list<int> the HR department and its sub-units
     */
    private function hrDepartmentIds(): array
    {
        $root = DB::table('departments')->where('code', 'HR')->whereNull('deleted_at')->value('id');
        $ids = $root ? [(int) $root] : [];
        $frontier = $ids;
        while ($frontier !== []) {
            $children = DB::table('departments')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
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
