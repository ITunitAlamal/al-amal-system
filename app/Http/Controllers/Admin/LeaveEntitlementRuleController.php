<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveEntitlementRule;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveEntitlementRuleController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->is_active && $request->user()->isSystemAdmin(),
            403,
            'إدارة سياسات الإجازات متاحة لمدير النظام فقط.'
        );
    }

    private function navigation(): array
    {
        return [
            ['label' => 'لوحة التحكم', 'url' => route('ui.preview'), 'icon' => '▦', 'active' => false],
            ['label' => 'ثوابت النظام', 'url' => route('admin.master-data.index'), 'icon' => '⚙', 'active' => true],
        ];
    }

    private function masterValues(string $type): array
    {
        return DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', $type)
            ->where('t.is_active', true)
            ->where('v.is_active', true)
            ->orderBy('v.name_ar')
            ->get(['v.id', 'v.code', 'v.name_ar'])
            ->map(fn ($value) => (array) $value)->all();
    }

    private function validateTypedValue(int $valueId, string $type, string $field): void
    {
        $valid = DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('v.id', $valueId)
            ->where('t.code', $type)
            ->where('t.is_active', true)
            ->where('v.is_active', true)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => 'اختر قيمة نشطة من نوع الثابت الصحيح.',
            ]);
        }
    }

    private function validated(Request $request, ?LeaveEntitlementRule $rule = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('leave_entitlement_rules', 'code')->ignore($rule?->id),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'employment_term_value_id' => ['required', 'integer', Rule::exists('master_data_values', 'id')],
            'contract_type_value_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')],
            'leave_type_value_id' => ['required', 'integer', Rule::exists('master_data_values', 'id')],
            'min_service_months' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'max_service_months' => ['nullable', 'integer', 'gt:min_service_months', 'max:2147483647'],
            'annual_entitlement_days' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->validateTypedValue((int) $data['employment_term_value_id'], 'employment_terms', 'employment_term_value_id');
        $this->validateTypedValue((int) $data['leave_type_value_id'], 'leave_types', 'leave_type_value_id');
        if (! empty($data['contract_type_value_id'])) {
            $this->validateTypedValue((int) $data['contract_type_value_id'], 'contract_types', 'contract_type_value_id');
        }
        $data['contract_type_value_id'] = $data['contract_type_value_id'] ?? null;

        // Policy history is immutable once its effective period has started.
        // Use a new future-dated rule to make a prospective change.
        if ($rule && $rule->effective_from->toDateString() <= now()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'بدأ سريان هذه القاعدة؛ حفاظًا على الاستحقاقات التاريخية لا يمكن تعديلها. أنشئ سياسة جديدة مستقبلية بدلًا منها.',
            ]);
        }
        if ($rule === null && $data['effective_from'] < now()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'لا يمكن إنشاء سياسة جديدة بتاريخ سابق دون إجراء ترحيل تاريخي معتمد.',
            ]);
        }

        return $data;
    }

    private function audit(Request $request, string $action, LeaveEntitlementRule $rule, array $fields): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'leave_entitlement_rules',
            'subject_id' => $rule->id,
            'metadata' => json_encode(['changed_fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function saveSafely(callable $operation): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if ($state === '23P01') {
                throw ValidationException::withMessages([
                    'effective_from' => 'توجد سياسة نشطة متداخلة مع هذه السياسة في بند التوظيف ونوع العقد ونوع الإجازة وفترة الأقدمية والسريان.',
                ]);
            }
            if ($state === '23505') {
                throw ValidationException::withMessages([
                    'code' => 'رمز السياسة مستخدم بالفعل.',
                ]);
            }
            throw $exception;
        }
    }

    /**
     * Save all leave types for all seniority tiers of one employment term.
     * Existing rules are never replaced, deleted, or modified.
     * Payload:
     * employment_term_value_id, effective_from, effective_until?, notes?,
     * tiers: [{min_service_months, max_service_months?, entitlements:
     *          [{leave_type_value_id, annual_entitlement_days}]}]
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'employment_term_value_id' => ['required', 'integer', Rule::exists('master_data_values', 'id')],
            'contract_type_value_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'tiers' => ['required', 'array', 'min:1', 'max:30'],
            'tiers.*' => ['required', 'array'],
            'tiers.*.min_service_months' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'tiers.*.max_service_months' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'tiers.*.entitlements' => ['required', 'array', 'min:1', 'max:50'],
            'tiers.*.entitlements.*' => ['required', 'array'],
            'tiers.*.entitlements.*.leave_type_value_id' => ['required', 'integer', Rule::exists('master_data_values', 'id')],
            'tiers.*.entitlements.*.annual_entitlement_days' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $this->validateTypedValue(
            (int) $data['employment_term_value_id'], 'employment_terms', 'employment_term_value_id'
        );
        $contractName = null;
        if (! empty($data['contract_type_value_id'])) {
            $this->validateTypedValue((int) $data['contract_type_value_id'], 'contract_types', 'contract_type_value_id');
            $contractName = DB::table('master_data_values')->where('id', $data['contract_type_value_id'])->value('name_ar');
        }

        if ($data['effective_from'] < now()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'لا يمكن إنشاء سياسة بتاريخ سابق دون إجراء ترحيل تاريخي معتمد.',
            ]);
        }

        $errors = [];
        $intervalsByLeave = [];
        $leaveTypeCache = [];
        $prepared = [];

        foreach ($data['tiers'] as $tierIndex => $tier) {
            $min = (int) $tier['min_service_months'];
            $max = $tier['max_service_months'] ?? null;
            $max = $max === null ? null : (int) $max;

            if ($max !== null && $max <= $min) {
                $errors["tiers.$tierIndex.max_service_months"] = 'نهاية شريحة الأقدمية يجب أن تكون أكبر من بدايتها.';
            }

            $usedInTier = [];
            foreach ($tier['entitlements'] as $leaveIndex => $entry) {
                $typeId = (int) $entry['leave_type_value_id'];
                $field = "tiers.$tierIndex.entitlements.$leaveIndex.leave_type_value_id";

                if (isset($usedInTier[$typeId])) {
                    $errors[$field] = 'نوع الإجازة مكرر في شريحة الأقدمية نفسها.';
                }
                $usedInTier[$typeId] = true;

                if (! array_key_exists($typeId, $leaveTypeCache)) {
                    $leaveTypeCache[$typeId] = DB::table('master_data_values as v')
                        ->join('master_data_types as t', 't.id', '=', 'v.type_id')
                        ->where('v.id', $typeId)
                        ->where('t.code', 'leave_types')
                        ->where('v.is_active', true)->where('t.is_active', true)
                        ->value('v.name_ar');
                }
                if ($leaveTypeCache[$typeId] === null) {
                    $errors[$field] = 'نوع الإجازة غير نشط أو لا ينتمي لثوابت أنواع الإجازات.';
                }

                foreach ($intervalsByLeave[$typeId] ?? [] as $prior) {
                    // Half-open service intervals, with null upper bound = infinity.
                    if (($prior['max'] === null || $min < $prior['max'])
                        && ($max === null || $prior['min'] < $max)) {
                        $errors[$field] = 'تتداخل شرائح الأقدمية لهذا النوع من الإجازات.';
                        break;
                    }
                }
                $intervalsByLeave[$typeId][] = ['min' => $min, 'max' => $max];

                $prepared[] = [
                    'leave_type_value_id' => $typeId,
                    'leave_name' => $leaveTypeCache[$typeId],
                    'min_service_months' => $min,
                    'max_service_months' => $max,
                    'annual_entitlement_days' => $entry['annual_entitlement_days'],
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $this->saveSafely(function () use ($request, $data, $prepared, $contractName): void {
            foreach ($prepared as $item) {
                $rule = new LeaveEntitlementRule;
                $rule->fill([
                    // Generated codes are unique identifiers, not policy values.
                    'code' => 'leave_'.Str::lower(Str::random(24)),
                    'name_ar' => 'استحقاق '.$item['leave_name']
                        .($contractName ? ' (عقد '.$contractName.')' : '')
                        .' من الشهر '.$item['min_service_months']
                        .($item['max_service_months'] === null
                            ? ' فأكثر' : ' حتى أقل من الشهر '.$item['max_service_months']),
                    'employment_term_value_id' => $data['employment_term_value_id'],
                    'contract_type_value_id' => $data['contract_type_value_id'] ?? null,
                    'leave_type_value_id' => $item['leave_type_value_id'],
                    'min_service_months' => $item['min_service_months'],
                    'max_service_months' => $item['max_service_months'],
                    'annual_entitlement_days' => $item['annual_entitlement_days'],
                    'effective_from' => $data['effective_from'],
                    'effective_until' => $data['effective_until'] ?? null,
                    'is_active' => true,
                    'notes' => $data['notes'] ?? null,
                ]);
                $rule->created_by = $request->user()->id;
                $rule->updated_by = $request->user()->id;
                $rule->save();
                $this->audit($request, 'leave_entitlement_rule.created', $rule, array_keys($rule->getAttributes()));
            }
        });

        return redirect()->route('admin.leave-entitlement-rules.index')
            ->with('success', 'تم حفظ جميع قواعد الإجازات وشرائح الأقدمية بنجاح.');
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $rules = LeaveEntitlementRule::query()
            ->orderByDesc('effective_from')
            ->orderBy('employment_term_value_id')
            ->orderBy('leave_type_value_id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Admin/LeaveEntitlementRules/Index', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'rules' => $rules,
            'employmentTerms' => $this->masterValues('employment_terms'),
            'contractTypes' => $this->masterValues('contract_types'),
            'leaveTypes' => $this->masterValues('leave_types'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/LeaveEntitlementRules/Form', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'rule' => null,
            'employmentTerms' => $this->masterValues('employment_terms'),
            'contractTypes' => $this->masterValues('contract_types'),
            'leaveTypes' => $this->masterValues('leave_types'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request);
        $this->saveSafely(function () use ($request, $data): void {
            $rule = new LeaveEntitlementRule;
            $rule->fill($data);
            $rule->created_by = $request->user()->id;
            $rule->updated_by = $request->user()->id;
            $rule->save();
            $this->audit($request, 'leave_entitlement_rule.created', $rule, array_keys($data));
        });

        return redirect()->route('admin.leave-entitlement-rules.index')
            ->with('success', 'تمت إضافة سياسة الاستحقاق.');
    }

    public function edit(Request $request, LeaveEntitlementRule $leaveEntitlementRule): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/LeaveEntitlementRules/Form', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'rule' => $leaveEntitlementRule,
            'employmentTerms' => $this->masterValues('employment_terms'),
            'contractTypes' => $this->masterValues('contract_types'),
            'leaveTypes' => $this->masterValues('leave_types'),
        ]);
    }

    public function update(Request $request, LeaveEntitlementRule $leaveEntitlementRule): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, $leaveEntitlementRule);
        $this->saveSafely(function () use ($request, $leaveEntitlementRule, $data): void {
            $rule = LeaveEntitlementRule::query()->lockForUpdate()->findOrFail($leaveEntitlementRule->id);
            if ($rule->effective_from->toDateString() <= now()->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'بدأ سريان القاعدة بالفعل؛ لا يمكن تعديلها.',
                ]);
            }
            $before = $rule->only(array_keys($data));
            $rule->fill($data);
            $rule->updated_by = $request->user()->id;
            $rule->save();
            $changed = array_keys(array_filter($data, fn ($v, $k) => (string) $v !== (string) ($before[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
            $this->audit($request, 'leave_entitlement_rule.updated', $rule, $changed);
        });

        return redirect()->route('admin.leave-entitlement-rules.index')
            ->with('success', 'تم تحديث السياسة المستقبلية.');
    }
}
