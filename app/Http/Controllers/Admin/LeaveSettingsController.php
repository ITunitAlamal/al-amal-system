<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\EmployeeLeaveEntitlementService;
use App\Services\LeaveApproverResolver;
use App\Services\LeaveSubmissionRules;
use App\Services\LeaveWorkflow;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LeaveSettingsController extends Controller
{
    /** @var array<string, string> approval mode => Arabic label */
    private const APPROVAL_MODES = [
        'workflow' => 'المسار المركزي عبر مسارات العمل: الموظف ← رئيس القسم (توصية) ← المدير التنفيذي (قرار نهائي)، ورؤساء الأقسام ← المدير التنفيذي، وإجازة المدير التنفيذي بقرار مجلس الإدارة',
        'department_or_executive' => 'الاعتماد السابق بخطوة واحدة: رئيس القسم يعتمد نهائيًا، والمدير التنفيذي لرؤساء الأقسام',
    ];

    public function __construct(private readonly LeaveApproverResolver $approvers) {}

    /**
     * Leave workflows (bound to leave requests) with whether a version is published.
     *
     * @return list<array{code: string, name: string, published: bool}>
     */
    private function leaveWorkflows(): array
    {
        return DB::table('workflow_definitions as d')->where('d.subject_type', LeaveWorkflow::SUBJECT_TYPE)->where('d.is_active', true)
            ->orderBy('d.name')->get(['d.id', 'd.code', 'd.name'])
            ->map(fn (object $definition): array => [
                'code' => $definition->code, 'name' => $definition->name,
                'published' => DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')->exists(),
            ])->all();
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403);
    }

    /** The executive director decides policies; the system admin only configures (centralized approval). */
    private function isExecutive(Request $request): bool
    {
        return $request->user() !== null && $this->approvers->isExecutive((int) $request->user()->id);
    }

    public function show(Request $request): Response
    {
        $isAdmin = (bool) $request->user()?->isSystemAdmin();
        $isExecutive = $this->isExecutive($request);
        abort_unless($isAdmin || $isExecutive, 403);
        $settings = DB::table('leave_module_settings')->where('id', 1)->first();
        $names = DB::table('users')->whereIn('id', array_filter([$settings?->prorata_proposed_by, $settings?->prorata_approved_by]))->pluck('name', 'id');

        return Inertia::render('Leaves/Settings', [
            'username' => $request->user()->name,
            'navigation' => [['label' => 'الإجازات', 'url' => route('leaves.index'), 'icon' => '▦', 'active' => false]],
            'settings' => $settings,
            'approvalModes' => self::APPROVAL_MODES,
            'leaveWorkflows' => $this->leaveWorkflows(),
            'workflowsUrl' => route('admin.workflows.index', [], false),
            'delegationsUrl' => route('admin.approval-delegations.index', [], false),
            'openRequests' => DB::table('employee_leave_requests')->whereIn('status', LeaveSubmissionRules::OPEN_STATUSES)->count(),
            'holidays' => DB::table('leave_holidays')->orderByDesc('holiday_date')->limit(100)->get(),
            'launched' => DB::table('leave_policy_drafts')->where('status', 'launched')->exists(),
            'policyPreviewUrl' => route('admin.leave-policy-corrections.show', 1),
            'canConfigure' => $isAdmin,
            'canDecideProrata' => $isExecutive,
            'prorata' => [
                'status' => $settings?->prorata_status ?? 'not_defined',
                'proposedBy' => $settings?->prorata_proposed_by ? ($names[$settings->prorata_proposed_by] ?? null) : null,
                'proposedAt' => $settings?->prorata_proposed_at,
                'approvedBy' => $settings?->prorata_approved_by ? ($names[$settings->prorata_approved_by] ?? null) : null,
                'approvedAt' => $settings?->prorata_approved_at,
                'roundingOptions' => EmployeeLeaveEntitlementService::ROUNDING,
            ],
        ]);
    }

    /**
     * The system admin records the pro-rata parameters from the association's bylaws.
     * Saving always returns the policy to "proposed": nothing is shown to employees until approval.
     */
    public function proposeProrata(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'prorata_monthly_rate_days' => ['required', 'numeric', 'gt:0', 'max:31', 'decimal:0,3'],
            'prorata_min_service_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'prorata_rounding' => ['required', Rule::in(array_keys(EmployeeLeaveEntitlementService::ROUNDING))],
            'prorata_notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('leave_module_settings')->where('id', 1)->update([
                ...$data,
                'prorata_status' => 'proposed',
                'prorata_proposed_by' => $request->user()->id, 'prorata_proposed_at' => now(),
                'prorata_approved_by' => null, 'prorata_approved_at' => null,
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'leave_prorata.proposed', $data);
        });
        app(NotificationCenter::class)->notifyRole('executive_director', 'leave_prorata_proposed', [
            'title' => 'سياسة احتساب نسبي للإجازة بانتظار قرارك',
            'body' => 'اقترح مدير النظام معدل استحقاق للموظفين بخدمة أقل من سنة. لا يظهر للموظفين قبل اعتمادك.',
            'url' => route('admin.leaves.settings', [], false),
            'dedupe' => 'leave_prorata_proposed:'.now()->timestamp,
        ]);

        return back()->with('success', 'حُفظت معايير الاحتساب النسبي وأُرسلت إلى المدير التنفيذي للاعتماد. لا تظهر للموظفين قبل الاعتماد.');
    }

    /** Executive director decision on the proposed pro-rata policy: approve, or return it with a reason. */
    public function decideProrata(Request $request): RedirectResponse
    {
        abort_unless($this->isExecutive($request), 403, 'اعتماد سياسات الاستحقاق من صلاحية المدير التنفيذي.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'note' => ['required_if:decision,return', 'nullable', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $current = DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            if (! $current || $current->prorata_status !== 'proposed') {
                throw ValidationException::withMessages(['decision' => 'لا توجد سياسة احتساب نسبي مقترحة بانتظار القرار.']);
            }
            if ((int) $current->prorata_proposed_by === (int) $request->user()->id) {
                throw ValidationException::withMessages(['decision' => 'لا يجوز اعتماد مقترح قدّمته بنفسك.']);
            }
            DB::table('leave_module_settings')->where('id', 1)->update($data['decision'] === 'approve'
                ? ['prorata_status' => 'approved', 'prorata_approved_by' => $request->user()->id, 'prorata_approved_at' => now(), 'updated_at' => now()]
                : ['prorata_status' => 'not_defined', 'prorata_approved_by' => null, 'prorata_approved_at' => null, 'updated_at' => now()]);
            $this->audit($request, $data['decision'] === 'approve' ? 'leave_prorata.approved' : 'leave_prorata.returned', ['note' => $data['note'] ?? null]);
        });

        return back()->with('success', $data['decision'] === 'approve'
            ? 'اعتُمدت سياسة الاحتساب النسبي. يظهر الاستحقاق المتوقع للعرض فقط ولا يُضاف لأي رصيد تلقائيًا.'
            : 'أُعيد المقترح إلى مدير النظام مع السبب.');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->guard($request);
        $published = collect($this->leaveWorkflows())->where('published', true)->pluck('code')->all();
        $data = $request->validate([
            'counting_mode' => ['required', Rule::in(['calendar', 'working'])],
            'approval_mode' => ['required', Rule::in(array_keys(self::APPROVAL_MODES))],
            'employee_workflow_code' => ['nullable', 'required_if:approval_mode,workflow', Rule::in($published)],
            'head_workflow_code' => ['nullable', 'required_if:approval_mode,workflow', Rule::in($published)],
            'executive_workflow_code' => ['nullable', 'required_if:approval_mode,workflow', Rule::in($published)],
            'grant_mode' => ['nullable', Rule::in(['full_year'])],
            'working_weekdays' => ['exclude_unless:counting_mode,working', 'required', 'array', 'min:1', 'max:7'],
            'working_weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'requests_enabled' => ['required', 'boolean'],
        ], [
            'employee_workflow_code.in' => 'اختر مسار إجازات منشورًا للموظفين.',
            'head_workflow_code.in' => 'اختر مسار إجازات منشورًا لرؤساء الأقسام.',
            'executive_workflow_code.in' => 'اختر مسارًا منشورًا لإجازة المدير التنفيذي (قرار مجلس الإدارة).',
        ], [
            'executive_workflow_code' => 'مسار إجازة المدير التنفيذي',
        ]);
        if ($data['counting_mode'] !== 'calendar') {
            throw ValidationException::withMessages(['counting_mode' => 'اعتمدت الجمعية الاحتساب بالأيام التقويمية بما فيها العطل.']);
        }
        if ($data['counting_mode'] === 'working' && empty($data['working_weekdays'])) {
            throw ValidationException::withMessages(['working_weekdays' => 'يجب تحديد أيام العمل المعتمدة.']);
        }
        if ($data['requests_enabled'] && ! DB::table('leave_policy_drafts')->where('status', 'launched')->exists()) {
            throw ValidationException::withMessages(['requests_enabled' => 'لا يمكن تشغيل الطلبات قبل تطبيق سياسة الإطلاق الرسمية.']);
        }
        $workflowMode = $data['approval_mode'] === 'workflow';
        DB::transaction(function () use ($data, $request, $workflowMode) {
            $current = DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            // Prevent retroactive reinterpretation of submitted requests: counting rules apply to approved ones too.
            if (DB::table('employee_leave_requests')->whereIn('status', [...LeaveSubmissionRules::OPEN_STATUSES, 'approved'])->exists()) {
                if ($current && ($current->counting_mode !== $data['counting_mode']
                    || json_decode((string) $current->working_weekdays, true) !== ($data['counting_mode'] === 'working' ? $data['working_weekdays'] : []))) {
                    throw ValidationException::withMessages(['counting_mode' => 'توجد طلبات معتمدة أو قيد المعالجة؛ لا يمكن تعديل طريقة الحساب قبل ترحيل معتمد.']);
                }
            }
            // The approval path may change only while no request is in process; each request keeps the path it started on.
            if ($current && $current->approval_mode !== $data['approval_mode']
                && DB::table('employee_leave_requests')->whereIn('status', LeaveSubmissionRules::OPEN_STATUSES)->exists()) {
                throw ValidationException::withMessages(['approval_mode' => 'توجد طلبات قيد المعالجة؛ أكملها أو ألغها قبل تغيير مسار الاعتماد.']);
            }
            $values = [
                'counting_mode' => $data['counting_mode'],
                'approval_mode' => $data['approval_mode'],
                'employee_workflow_code' => $workflowMode ? $data['employee_workflow_code'] : ($current->employee_workflow_code ?? null),
                'head_workflow_code' => $workflowMode ? $data['head_workflow_code'] : ($current->head_workflow_code ?? null),
                'executive_workflow_code' => $workflowMode ? $data['executive_workflow_code'] : ($current->executive_workflow_code ?? null),
                'working_weekdays' => json_encode($data['counting_mode'] === 'working' ? $data['working_weekdays'] : [], JSON_THROW_ON_ERROR),
                'grant_mode' => $data['grant_mode'] ?? null,
                'requests_enabled' => $data['requests_enabled'],
            ];
            DB::table('leave_module_settings')->updateOrInsert(['id' => 1], [...$values, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $before = $current ? array_intersect_key((array) $current, $values) : null;
            $this->audit($request, 'leave_settings.updated', ['before' => $before, 'after' => $values]);
        });

        return back()->with('success', 'تم حفظ إعدادات الإجازات.');
    }

    /**
     * The official calendar stays editable (TRANSFORM §8): a holiday changes a leave's counted days only when leave is
     * counted in working days (LeaveDayCounter ignores holidays in calendar counting), so only an open or approved
     * request that covers this very date blocks the change. A holiday that an approved or credited compensatory work
     * day rests on is never removed silently either.
     */
    private function guardHolidayChange(string $date, string $field, bool $removing): void
    {
        $counting = DB::table('leave_module_settings')->where('id', 1)->value('counting_mode');
        if ($counting === 'working' && DB::table('employee_leave_requests')->whereIn('status', [...LeaveSubmissionRules::OPEN_STATUSES, 'approved'])
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
            throw ValidationException::withMessages([$field => 'توجد إجازة قيد المعالجة أو معتمدة تشمل هذا التاريخ وتُحتسب بأيام العمل؛ تغيير العطلة يغيّر أيامها، فيلزم تصحيح إداري منفصل.']);
        }
        if ($removing && DB::table('attendance_compensatory_work')->where('work_date', $date)->whereIn('status', ['approved', 'credited'])->exists()) {
            throw ValidationException::withMessages([$field => 'اعتُمد عمل في هذا اليوم على أنه عطلة رسمية؛ لا تُحذف العطلة قبل معالجة العمل التعويضي المرتبط بها.']);
        }
    }

    public function addHoliday(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['holiday_date' => ['required', 'date_format:Y-m-d', 'unique:leave_holidays,holiday_date'], 'name_ar' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($request, $data) {
            DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            $this->guardHolidayChange($data['holiday_date'], 'holiday_date', false);
            $id = DB::table('leave_holidays')->insertGetId([...$data, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'leave_holiday.added', ['id' => $id, 'date' => $data['holiday_date'], 'name' => $data['name_ar']]);
            // Attendance days already calculated for that date become «عطلة رسمية».
            app(AttendanceDailyService::class)->refreshExisting(null, $data['holiday_date'], $data['holiday_date']);
        });

        return back()->with('success', 'أُضيفت العطلة وأُعيد احتساب الحضور في ذلك اليوم.');
    }

    public function removeHoliday(Request $request, int $holiday): RedirectResponse
    {
        $this->guard($request);
        DB::transaction(function () use ($request, $holiday) {
            DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('leave_holidays')->where('id', $holiday)->lockForUpdate()->first();
            abort_unless($row, 404);
            // Counting already made must never be silently reinterpreted.
            $this->guardHolidayChange((string) $row->holiday_date, 'holiday', true);
            DB::table('leave_holidays')->where('id', $holiday)->delete();
            $this->audit($request, 'leave_holiday.removed', ['id' => $holiday, 'date' => $row->holiday_date, 'name' => $row->name_ar]);
            app(AttendanceDailyService::class)->refreshExisting(null, (string) $row->holiday_date, (string) $row->holiday_date);
        });

        return back()->with('success', 'حُذفت العطلة وأُعيد احتساب الحضور في ذلك اليوم.');
    }

    private function audit(Request $r, string $action, array $metadata): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $r->user()->id, 'action' => $action, 'subject_type' => 'leave_module_settings',
            'subject_id' => 1, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip_address' => $r->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
