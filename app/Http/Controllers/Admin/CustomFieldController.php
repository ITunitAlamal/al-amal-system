<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dynamic field designer (SRS CFG03). System administrators only.
 *
 * Code, type and the linked reference list are fixed after creation so stored
 * values never change meaning. Fields are deactivated, never deleted.
 */
class CustomFieldController extends Controller
{
    public function __construct(
        private readonly CustomFieldEntities $entities,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $entity = (string) $request->query('entity', CustomFieldEntities::AIMS_REQUEST);
        abort_unless($this->entities->isKnown($entity), 404);
        $scopes = $this->entities->scopes($entity);
        $scope = (string) $request->query('scope', '');
        abort_unless(array_key_exists($scope, $scopes), 404);

        return Inertia::render('Admin/CustomFields/Index', [
            'entities' => $this->entities->all(),
            'scopes' => $scopes,
            'steps' => $this->entities->steps($entity, $scope),
            'stepActions' => (object) $this->entities->stepActions($entity, $scope),
            'selectedEntity' => $entity,
            'selectedScope' => $scope,
            'fields' => DB::table('custom_field_definitions')
                ->where('entity_type', $entity)->where('scope_key', $scope)
                ->orderBy('sort_order')->orderBy('id')->get(),
            'fieldTypes' => CustomFieldService::TYPES,
            'visibilityOperators' => CustomFieldService::VISIBILITY_OPERATORS,
            'masterTypes' => DB::table('master_data_types')->where('is_active', true)->orderBy('name_ar')->get(['id', 'name_ar']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $entity = is_string($request->input('entity_type')) ? $request->input('entity_type') : '';
        abort_unless($this->entities->isKnown($entity), 422, 'الكيان غير مدعوم.');
        $scope = is_string($request->input('scope_key')) ? $request->input('scope_key') : '';

        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'scope_key' => ['present', 'nullable', Rule::in(array_keys($this->entities->scopes($entity)))],
            'step_code' => ['nullable', Rule::in(array_keys($this->entities->steps($entity, $scope)))],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'field_type' => ['required', Rule::in(array_keys(CustomFieldService::TYPES))],
            'master_data_type_id' => ['nullable', 'required_if:field_type,select', 'integer', Rule::exists('master_data_types', 'id')],
            ...$this->editableRules(),
        ]);
        $data['scope_key'] = $scope;
        $this->ensureUniqueCode($entity, $scope, $data['code']);
        $this->checkVisibilityRule($entity, $scope, $data['code'], $data['visible_when'] ?? null);
        $this->checkActionCodes($entity, $scope, $data['step_code'] ?? null, $data['visible_when'] ?? null);

        DB::transaction(function () use ($request, $data): void {
            $row = $this->toRow($data);
            $id = DB::table('custom_field_definitions')->insertGetId([
                ...$row,
                'entity_type' => $data['entity_type'],
                'scope_key' => $data['scope_key'],
                'step_code' => $data['step_code'] ?? null,
                'code' => $data['code'],
                'field_type' => $data['field_type'],
                'master_data_type_id' => $data['field_type'] === 'select' ? $data['master_data_type_id'] : null,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->recordRow('custom_field.created', 'custom_field_definitions', $id, null, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تمت إضافة الحقل.');
    }

    public function update(Request $request, int $field): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $before = DB::table('custom_field_definitions')->where('id', $field)->first();
        abort_unless($before, 404);

        $data = $request->validate([...$this->editableRules(), 'is_active' => ['required', 'boolean']]);
        $this->checkVisibilityRule($before->entity_type, $before->scope_key, $before->code, $data['visible_when'] ?? null);
        $this->checkActionCodes($before->entity_type, $before->scope_key, $before->step_code, $data['visible_when'] ?? null);

        DB::transaction(function () use ($request, $field, $before, $data): void {
            DB::table('custom_field_definitions')->where('id', $field)->lockForUpdate()->first();
            DB::table('custom_field_definitions')->where('id', $field)->update([
                ...$this->toRow($data),
                'is_active' => $data['is_active'],
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $this->audit->recordRow('custom_field.updated', 'custom_field_definitions', $field, (array) $before, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم تحديث الحقل.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'إدارة الحقول الديناميكية متاحة لمدير النظام فقط.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function editableRules(): array
    {
        return [
            'label_ar' => ['required', 'string', 'max:200'],
            'label_en' => ['nullable', 'string', 'max:200'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'is_required' => ['required', 'boolean'],
            'is_sensitive' => ['required', 'boolean'],
            'validation' => ['nullable', 'array'],
            'validation.min' => ['nullable', 'numeric'],
            'validation.max' => ['nullable', 'numeric', function (string $attribute, mixed $value, \Closure $fail): void {
                $min = request()->input('validation.min');
                if ($value !== null && is_numeric($min) && (float) $value < (float) $min) {
                    $fail('أكبر قيمة يجب ألا تقل عن أقل قيمة.');
                }
            }],
            'validation.max_length' => ['nullable', 'integer', 'min:1', 'max:20000'],
            // «تاريخ ووقت»: refuse a time that has not come yet.
            'validation.not_future' => ['nullable', 'boolean'],
            'visible_when' => ['nullable', 'array'],
            'visible_when.field' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'visible_when.operator' => ['nullable', Rule::in(CustomFieldService::VISIBILITY_OPERATORS)],
            'visible_when.value' => ['nullable', 'string', 'max:200'],
            // A step field shown, checked and saved only with these actions of its step.
            'visible_when.action_codes' => ['nullable', 'array', 'max:30'],
            'visible_when.action_codes.*' => ['string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toRow(array $data): array
    {
        $validation = array_filter($data['validation'] ?? [], fn ($value): bool => $value !== null && $value !== '' && $value !== false);
        $visibleWhen = ! empty($data['visible_when']['field']) ? [
            'field' => $data['visible_when']['field'],
            'operator' => $data['visible_when']['operator'] ?? 'equals',
            'value' => $data['visible_when']['value'] ?? null,
        ] : [];
        $actionCodes = array_values(array_unique(array_filter($data['visible_when']['action_codes'] ?? [])));
        if ($actionCodes !== []) {
            $visibleWhen['action_codes'] = $actionCodes;
        }
        $visibleWhen = $visibleWhen === [] ? null : $visibleWhen;

        return [
            'label_ar' => $data['label_ar'],
            'label_en' => $data['label_en'] ?? null,
            'help_text' => $data['help_text'] ?? null,
            'is_required' => $data['is_required'],
            'is_sensitive' => $data['is_sensitive'],
            'validation' => $validation === [] ? null : json_encode($validation),
            'visible_when' => $visibleWhen === null ? null : json_encode($visibleWhen, JSON_UNESCAPED_UNICODE),
            'sort_order' => $data['sort_order'],
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ];
    }

    /**
     * A code must not collide between a general field and a field of a specific type,
     * because both appear in the same form.
     */
    private function ensureUniqueCode(string $entity, string $scope, string $code): void
    {
        $taken = DB::table('custom_field_definitions')
            ->where('entity_type', $entity)
            ->where('code', $code)
            ->when($scope !== '', fn ($query) => $query->whereIn('scope_key', ['', $scope]))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => 'الرمز مستخدم لحقل آخر يظهر في النموذج نفسه.']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $rule
     */
    private function checkVisibilityRule(string $entity, string $scope, string $code, ?array $rule): void
    {
        $field = $rule['field'] ?? null;
        if (! $field) {
            return;
        }

        $exists = DB::table('custom_field_definitions')
            ->where('entity_type', $entity)
            ->whereIn('scope_key', array_values(array_unique(['', $scope])))
            ->where('code', $field)
            ->exists();

        if ($field === $code || ! $exists) {
            throw ValidationException::withMessages(['visible_when.field' => 'شرط الظهور يجب أن يعتمد على حقل آخر موجود في النموذج نفسه.']);
        }
    }

    /**
     * A field can be tied only to actions of the step it is filled in.
     *
     * @param  array<string, mixed>|null  $rule
     */
    private function checkActionCodes(string $entity, string $scope, ?string $stepCode, ?array $rule): void
    {
        $codes = array_values(array_filter($rule['action_codes'] ?? []));
        if ($codes === []) {
            return;
        }
        $known = $stepCode ? array_keys($this->entities->stepActions($entity, $scope)[$stepCode] ?? []) : [];
        if (array_diff($codes, $known) !== []) {
            throw ValidationException::withMessages(['visible_when.action_codes' => 'اختر من إجراءات المرحلة التي يُعبأ فيها الحقل؛ حقول نموذج التقديم لا تُربط بإجراء.']);
        }
    }
}
