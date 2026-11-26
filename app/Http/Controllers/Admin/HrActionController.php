<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\HrActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «الإجراءات الإدارية» tab of /admin/attendance: the confidential register (hr.actions.* only). The employee's own
 * acknowledgment and reply live in AttendanceSelfServiceController.
 */
final class HrActionController extends Controller
{
    public function __construct(private AttendanceAccess $access, private HrActionService $actions) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewHrActions($request->user()) && $this->actions->available(), 403);
        $data = $request->validate(['employee_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(array_keys(HrActionService::STATUS))], 'q' => ['nullable', 'string', 'max:100']]);
        $rows = collect($this->actions->list(isset($data['employee_id']) ? (int) $data['employee_id'] : null, false, 500));
        if (! empty($data['status'])) {
            $rows = $rows->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $ids = app(AttendanceLedger::class)->employeeIdsByName($data['q']);
            $rows = $rows->filter(fn (array $row): bool => in_array($row['employee_id'], $ids, true) || $row['reference_no'] === trim($data['q']));
        }
        $user = $request->user();

        return response()->json(['data' => $rows->values(), 'types' => $this->actions->types(),
            'can' => ['manage' => $this->access->canManageHrActions($user), 'decide' => $this->access->canDecideHrActions($user), 'user_id' => (int) $user->id]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')]]);
        $action = $this->actions->create($request->user(), $data, $request->file('attachment'));

        return back()->with('success', 'حُفظت مسودة الإجراء '.$action->reference_no.'. لا يصدر إلا بعد المراجعة.');
    }

    public function update(Request $request, int $action): RedirectResponse
    {
        $this->actions->update($request->user(), $this->row($action), $request->validate($this->rules()), $request->file('attachment'));

        return back()->with('success', 'حُفظ التعديل كإصدار جديد من المسودة.');
    }

    public function submit(Request $request, int $action): RedirectResponse
    {
        $this->actions->submit($request->user(), $this->row($action));

        return back()->with('success', 'رُفع الإجراء للمراجعة.');
    }

    public function decide(Request $request, int $action): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['issue', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->actions->decide($request->user(), $this->row($action), $data['decision'], $data['note'] ?? null);

        return back()->with('success', ['issue' => 'صدر الإجراء وأُبلغ الموظف.', 'reject' => 'رُفض الإجراء.', 'return' => 'أُعيد الإجراء للتعديل.'][$data['decision']]);
    }

    public function cancel(Request $request, int $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $this->actions->cancel($request->user(), $this->row($action), $data['reason']);

        return back()->with('success', 'أُلغي الإجراء مع حفظ سجله.');
    }

    /** Evidence file: the register's viewers, or the employee for an issued action. */
    public function attachment(Request $request, int $action): StreamedResponse
    {
        $row = $this->row($action);
        $self = (int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $request->user()->id && $row->status === 'issued';
        abort_unless(($this->access->canViewHrActions($request->user()) || $self) && $row->attachment_path, 403);

        return Storage::disk('local')->download($row->attachment_path, $row->attachment_name ?: 'evidence', ['Cache-Control' => 'no-store, private']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'type_value_id' => ['required', 'integer'], 'incident_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attendance_date' => ['nullable', 'date_format:Y-m-d'], 'facts' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['nullable', 'string', 'max:5000'], 'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    private function row(int $action): object
    {
        abort_unless($this->actions->available(), 404);
        $row = DB::table('employee_hr_actions')->where('id', $action)->first();
        abort_unless($row, 404);

        return $row;
    }
}
