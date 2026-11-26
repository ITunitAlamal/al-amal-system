<?php

namespace App\Http\Controllers;

use App\Services\Organization\ScopedDelegations;
use App\Services\Organization\UnitAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actions of «قسمي» on functional units and delegations: a head places their own staff in their units and delegates,
 * for a limited time, a permission the institution marked delegable. Every check is repeated on the server.
 */
class DepartmentUnitsController extends Controller
{
    public function __construct(
        private readonly UnitAssignments $units,
        private readonly ScopedDelegations $delegations,
    ) {}

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(['member', 'coordinator'])],
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.today()->subDays(30)->toDateString(), 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['unit_id' => 'الوحدة', 'employee_id' => 'الموظف', 'starts_on' => 'تاريخ البدء', 'reason' => 'السبب']);
        $this->units->assign($request->user(), (int) $data['unit_id'], (int) $data['employee_id'], $data['role'], $data['starts_on'] ?? null, $data['reason'] ?? null, $request->ip());

        return back(303)->with('success', 'وُزّع الموظف على الوحدة.');
    }

    public function end(Request $request, int $assignment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'سبب الإنهاء']);
        $this->units->end($request->user(), $assignment, $data['reason'], $request->ip());

        return back(303)->with('success', 'أُنهي توزيع الموظف على الوحدة، وبقي في سجلها.');
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->delegations->preview($request->user(), $this->delegationInput($request)));
    }

    public function delegate(Request $request): RedirectResponse
    {
        $data = $this->delegationInput($request);
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب التفويض']);
        $this->delegations->create($request->user(), $data, $request->ip());

        return back(303)->with('success', 'سُجّل التفويض، ويصل إشعار إلى المفوَّض. ينتهي تلقائيًا في تاريخه.');
    }

    public function revoke(Request $request, int $delegation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'سبب الإلغاء']);
        $this->delegations->revoke($request->user(), $delegation, $data['reason'], $request->ip());

        return back(303)->with('success', 'أُلغي التفويض فورًا.');
    }

    /** @return array<string, mixed> */
    private function delegationInput(Request $request): array
    {
        return $request->validate([
            'delegate_user_id' => ['required', 'integer'],
            'permission_code' => ['required', 'string', 'max:100'],
            'scope_department_id' => ['required', 'integer'],
            'starts_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['delegate_user_id' => 'المفوَّض', 'permission_code' => 'الصلاحية', 'scope_department_id' => 'النطاق', 'starts_at' => 'تاريخ البدء', 'ends_at' => 'تاريخ الانتهاء']);
    }
}
