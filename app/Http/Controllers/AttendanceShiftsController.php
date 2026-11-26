<?php

namespace App\Http\Controllers;

use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceMonthlySummary;
use App\Services\Attendance\AttendanceShiftRoster;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** «جدول المناوبات» for department heads and «دوامي الشهري» for every employee (and their head, HR, the ED). */
class AttendanceShiftsController extends Controller
{
    public function __construct(private readonly AttendanceShiftRoster $roster, private readonly AttendanceMonthlySummary $summary) {}

    public function shifts(Request $request): Response
    {
        $user = $request->user();
        $viewable = $this->roster->viewableDepartments($user);
        abort_if($viewable === [], 403, '«جدول المناوبات» لرئيس القسم المعيّن، ولرئيس الموارد البشرية والمدير التنفيذي للاطلاع.');
        $editable = $this->roster->editableDepartments($user);
        $department = (int) ($request->integer('department') ?: ($editable[0] ?? $viewable[0]));
        $month = $this->month($request->query('month'));

        return Inertia::render('Attendance/Shifts', [
            ...$this->roster->board($user, $department, $month),
            // Root departments first: a head sees the departments they lead and their units.
            'departments' => DB::table('departments')->whereIn('id', $viewable)->orderBy('name')->get(['id', 'name'])->map(fn ($d): array => ['id' => (int) $d->id, 'name' => $d->name,
                'editable' => in_array((int) $d->id, $editable, true)])->all(),
            'manageTypes' => $request->user()->isHrDepartmentHead() || in_array($department, $editable, true), 'canOrganizationTypes' => $request->user()->isHrDepartmentHead(),
            'allTypes' => $this->roster->shiftTypes($department, true), 'warnings' => $request->session()->get('roster_warnings', []),
        ]);
    }

    public function save(Request $request, int $department): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['changes' => ['required', 'array', 'min:1', 'max:'.AttendanceShiftRoster::MAX_CHANGES], 'changes.*' => ['array']]);
        $result = $this->roster->save($request->user(), $department, $data['changes']);
        $message = 'حُفظ '.$result['changed'].' يومًا في جدول المناوبات، وأُشعر الموظفون بالتغيير.';

        return back()->with('success', $message)->with('roster_warnings', $result['warnings']);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $this->roster->saveShiftType($request->user(), $this->typeData($request) + ['department_id' => $request->input('department_id')]);

        return back()->with('success', 'حُفظ نوع المناوبة.');
    }

    public function updateType(Request $request, int $type): RedirectResponse
    {
        $this->roster->saveShiftType($request->user(), $this->typeData($request), $type);

        return back()->with('success', 'حُدّث نوع المناوبة.');
    }

    public function monthly(Request $request): Response
    {
        $user = $request->user();
        $scope = $this->summary->scope($user);
        abort_if($scope === [], 403, 'لا يوجد ملف موظف مرتبط بحسابك، ولا تترأس قسمًا.');
        $month = $this->month($request->query('month'));
        $department = $request->integer('department') ?: null;
        $lines = $this->summary->lines($scope, $month, $department);
        $own = app(AttendanceAccess::class)->ownEmployee($user);
        $selected = $request->integer('employee') ?: (count($lines) === 1 ? $lines[0]['employee_id'] : null);
        abort_if($selected && $scope !== null && ! in_array($selected, $scope, true), 403);

        return Inertia::render('Attendance/Monthly', [
            'month' => $month, 'lines' => $lines, 'ownId' => $own ? (int) $own->id : null, 'selected' => $selected,
            'days' => $selected ? $this->summary->days($selected, $month) : [],
            'departments' => $scope === null ? DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
            'department' => $department, 'canRoster' => $this->roster->canOpen($user),
        ]);
    }

    private function typeData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'], 'start' => ['required', 'date_format:H:i'], 'end' => ['required', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'], 'department_id' => ['nullable', 'integer'],
        ]);
    }

    private function month(mixed $value): string
    {
        return is_string($value) && preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $value) ? $value : CarbonImmutable::now(config('app.timezone'))->format('Y-m');
    }
}
