<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\DepartmentBudgets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DepartmentBudgetController extends Controller
{
    public function __construct(private readonly DepartmentBudgets $budgets) {}

    public function index(Request $request): Response
    {
        abort_unless($this->budgets->canManage($request->user()), 403, 'إدارة ميزانيات الأقسام لرئيس قسم الحسابات فقط.');
        $data = $request->validate(['period' => $this->periodRules(false)]);

        return Inertia::render('Finance/DepartmentBudgets', $this->budgets->management($data['period'] ?? now()->format('Y-m')));
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $data = $request->validate(['period' => $this->periodRules(false), 'department_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->budgets->summary($request->user(), $data['period'] ?? now()->format('Y-m'), isset($data['department_id']) ? (int) $data['department_id'] : null))
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->budgets->canManage($request->user()), 403);
        $data = $request->validate([...$this->rules(),
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)->whereNull('deleted_at')->where('node_type', 'department')],
            'period' => $this->periodRules(true), 'fiscal_year' => ['prohibited'], 'fiscal_month' => ['prohibited'],
            'currency' => ['required', 'string', Rule::exists('currencies', 'code')->where('is_active', true)],
            'revision' => ['prohibited'],
        ]);
        $this->budgets->create($request->user(), $data);

        return to_route('finance.department-budgets.index', ['period' => $data['period']])->with('success', 'حُفظ المخصص الشهري؛ يُحسب المصروف والمتبقي تلقائيًا من سندات صرف الشهر المسجلة.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->budgets->canManage($request->user()), 403);
        $data = $request->validate([...$this->rules(), 'revision' => ['required', 'integer', 'min:1'],
            'department_id' => ['prohibited'], 'period' => ['prohibited'], 'fiscal_year' => ['prohibited'], 'fiscal_month' => ['prohibited'], 'currency' => ['prohibited'],
        ]);
        $period = $this->budgets->update($request->user(), $id, $data);

        return to_route('finance.department-budgets.index', ['period' => $period])->with('success', 'حُدّث المخصص الشهري وحُفظ التعديل في سجل الحسابات.');
    }

    private function rules(): array
    {
        return ['allocated_amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'], 'spent_amount' => ['prohibited'],
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000']];
    }

    private function periodRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'date_format:Y-m', 'after_or_equal:2020-01', 'before_or_equal:2100-12'];
    }
}
