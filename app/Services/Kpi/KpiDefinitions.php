<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Kpi\Sources\RentalCasesSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration of KPI definitions by the system administrator, every change audited.
 * Visibility and placement change in place; formula, target and thresholds change only through a new
 * version that starts today or later, so earlier figures keep the definition they were computed with.
 */
final class KpiDefinitions
{
    public const AUDIENCES = ['executive' => 'الإدارة العليا فقط', 'owner' => 'القسم المالك والإدارة العليا', 'shared' => 'كل رؤساء الأقسام كلٌّ لقسمه، والقسم المالك والإدارة العليا للكل'];

    private const ATTRIBUTES = [
        'code' => 'رمز المؤشر', 'domain' => 'المجال', 'owner_department_id' => 'القسم المالك', 'audience' => 'جمهور المؤشر',
        'viewer_department_ids' => 'أقسام إضافية تطلع على المؤشر', 'viewer_department_ids.*' => 'القسم الإضافي', 'visible_role_codes' => 'الأدوار المسموح لها',
        'visible_role_codes.*' => 'الدور', 'on_executive_home' => 'الظهور في لوحة الإدارة العليا', 'on_department_home' => 'الظهور في لوحة رئيس القسم',
        'is_active' => 'التفعيل', 'sort_order' => 'ترتيب الظهور', 'title' => 'اسم المؤشر', 'title_en' => 'الاسم الإنجليزي', 'description' => 'الوصف',
        'source' => 'مصدر البيانات', 'formula' => 'المعادلة', 'numerator' => 'البسط', 'denominator' => 'المقام', 'unit_value_id' => 'الوحدة',
        'period_value_id' => 'دورية القياس', 'polarity' => 'اتجاه التحسن', 'target' => 'الهدف', 'warning_threshold' => 'عتبة التنبيه',
        'critical_threshold' => 'العتبة الحرجة', 'filters' => 'فلاتر العينة', 'params' => 'معاملات المؤشر', 'unavailable_reason' => 'سبب عدم التوافر',
        'effective_from' => 'بداية سريان الإصدار', 'change_reason' => 'سبب التغيير', 'expected_version' => 'رقم الإصدار الحالي',
    ];

    public function __construct(private readonly KpiSources $sources, private readonly AuditService $audit) {}

    /** @return array<string, mixed> */
    public function validateSettings(array $input, ?object $definition = null): array
    {
        $departmentExists = Rule::exists('departments', 'id')->whereNull('deleted_at');
        $data = Validator::make($input, [
            'code' => [$definition ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]{2,99}$/D', Rule::unique('kpi_definitions', 'code')],
            'domain' => ['required', Rule::in(array_keys(KpiEngine::DOMAINS))],
            'owner_department_id' => ['nullable', 'integer', $departmentExists],
            'audience' => ['required', Rule::in(array_keys(self::AUDIENCES))],
            'viewer_department_ids' => ['present', 'array', 'max:20'],
            'viewer_department_ids.*' => ['integer', 'distinct', $departmentExists],
            'visible_role_codes' => ['present', 'array', 'max:20'],
            'visible_role_codes.*' => ['string', 'distinct', Rule::exists('roles', 'code')],
            'on_executive_home' => ['required', 'boolean'],
            'on_department_home' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], ['code.regex' => 'الرمز بحروف إنجليزية كبيرة وأرقام وشرطة سفلية، ويبدأ بحرف.'], self::ATTRIBUTES)->validate();
        if ($data['audience'] === 'owner' && empty($data['owner_department_id'])) {
            throw ValidationException::withMessages(['owner_department_id' => 'حدد القسم المالك عندما يكون الجمهور «القسم المالك والإدارة العليا».']);
        }
        $data['viewer_department_ids'] = array_values(array_map('intval', $data['viewer_department_ids']));
        $data['visible_role_codes'] = array_values($data['visible_role_codes']);

        return $data;
    }

    /**
     * A version configuration made only of registered sources, measures, formulas and whitelisted values.
     *
     * @return array<string, mixed>
     */
    public function validateConfiguration(array $input): array
    {
        $catalog = $this->sources->catalog();
        $amount = ['nullable', 'numeric', 'between:-1000000000000,1000000000000'];
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'source' => ['required', 'string', Rule::in(array_keys($catalog))],
            'formula' => ['required', 'string', Rule::in(array_keys(KpiSources::FORMULAS))],
            'numerator' => ['nullable', 'string', 'max:60'],
            'denominator' => ['nullable', 'string', 'max:60'],
            'unit_value_id' => ['required', 'integer'],
            'period_value_id' => ['required', 'integer'],
            'polarity' => ['required', Rule::in(['higher', 'lower'])],
            'target' => $amount, 'warning_threshold' => $amount, 'critical_threshold' => $amount,
            'filters' => ['present', 'array'],
            'filters.*' => ['nullable', 'string', 'max:100'],
            'params' => ['present', 'array'],
            'params.*' => ['nullable', 'integer'],
            'unavailable_reason' => ['nullable', 'required_if:source,'.KpiSources::UNAVAILABLE, 'string', 'max:1000'],
        ], [], self::ATTRIBUTES)->validate();

        $unit = $this->constant((int) $data['unit_value_id'], 'kpi_units', 'unit_value_id');
        $this->constant((int) $data['period_value_id'], 'kpi_periods', 'period_value_id');
        $config = [
            'title' => trim($data['title']), 'title_en' => $data['title_en'] ?? null, 'description' => trim((string) ($data['description'] ?? '')),
            'source' => $data['source'], 'formula' => $data['formula'], 'numerator' => null, 'denominator' => null,
            'unit_value_id' => (int) $data['unit_value_id'], 'period_value_id' => (int) $data['period_value_id'], 'polarity' => $data['polarity'],
            'target' => $this->number($data['target'] ?? null), 'warning_threshold' => $this->number($data['warning_threshold'] ?? null),
            'critical_threshold' => $this->number($data['critical_threshold'] ?? null), 'filters' => [], 'params' => [], 'unavailable_reason' => null,
        ];
        if ($data['source'] === KpiSources::UNAVAILABLE) {
            return [...$config, 'formula' => 'count', 'unavailable_reason' => trim((string) $data['unavailable_reason'])];
        }

        $source = $this->sources->get($data['source']);
        $measures = $source->measures();
        $numerator = $data['numerator'] ?? null;
        if (! isset($measures[$numerator])) {
            throw ValidationException::withMessages(['numerator' => 'اختر مقياسًا مسجلًا في هذا المصدر.']);
        }
        if (! in_array($measures[$numerator]['kind'], KpiSources::FORMULAS[$data['formula']][1], true)) {
            throw ValidationException::withMessages(['formula' => 'المعادلة لا تناسب نوع المقياس: العدد يُعد أو يُنسب، والمبالغ تُجمع أو تُنسب، والمدد يؤخذ متوسطها أو وسيطها.']);
        }
        $config['numerator'] = $numerator;
        if (in_array($data['formula'], ['ratio', 'percentage'], true)) {
            if (! in_array([$numerator, $data['denominator'] ?? null], $source->ratios(), true)) {
                throw ValidationException::withMessages(['denominator' => 'اختر مقامًا معتمدًا لهذا البسط في المصدر.']);
            }
            $config['denominator'] = $data['denominator'];
        }
        if (! in_array($unit->code, KpiSources::UNITS[$data['formula']], true)) {
            throw ValidationException::withMessages(['unit_value_id' => 'الوحدة لا تناسب المعادلة المختارة.']);
        }
        $config['filters'] = $this->filters($source->filters(), array_filter($data['filters'], fn ($value): bool => $value !== null && $value !== ''));
        $config['params'] = $this->params($source->params(), $data['params']);
        $this->checkThresholds($config);

        return $config;
    }

    public function create(User $actor, array $input, ?string $ipAddress): int
    {
        abort_unless($actor->isSystemAdmin(), 403);
        $settings = $this->validateSettings($input);
        $config = $this->validateConfiguration($input['configuration'] ?? []);
        $this->guardActivation($settings, $config);

        return DB::transaction(function () use ($actor, $settings, $config, $ipAddress): int {
            $id = DB::table('kpi_definitions')->insertGetId([...$this->settingsRow($settings), 'code' => $settings['code'],
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('kpi_versions')->insert(['definition_id' => $id, 'version' => 1, 'effective_from' => '2000-01-01',
                'configuration' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_by' => $actor->id, 'created_at' => now(),
                'change_reason' => 'الإصدار الأول؛ ينطبق على الفترات السابقة لعدم وجود تعريف قبله.']);
            $this->audit->recordRow('kpi.definition.created', 'kpi_definitions', $id, null, ['settings' => $settings, 'configuration' => $config], (int) $actor->id, $ipAddress);

            return (int) $id;
        });
    }

    public function updateSettings(User $actor, int $id, array $input, ?string $ipAddress): void
    {
        abort_unless($actor->isSystemAdmin(), 403);
        DB::transaction(function () use ($actor, $id, $input, $ipAddress): void {
            $definition = DB::table('kpi_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($definition, 404);
            $settings = $this->validateSettings($input, $definition);
            $latest = DB::table('kpi_versions')->where('definition_id', $id)->orderByDesc('version')->first();
            $this->guardActivation($settings, json_decode($latest->configuration, true) ?: []);
            $before = array_intersect_key((array) $definition, $this->settingsRow($settings));
            DB::table('kpi_definitions')->where('id', $id)->update([...$this->settingsRow($settings), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow('kpi.definition.updated', 'kpi_definitions', $id, $before, $this->settingsRow($settings), (int) $actor->id, $ipAddress);
        });
    }

    public function addVersion(User $actor, int $id, array $input, ?string $ipAddress): void
    {
        abort_unless($actor->isSystemAdmin(), 403);
        $meta = Validator::make($input, [
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'change_reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], [], self::ATTRIBUTES)->validate();
        $config = $this->validateConfiguration($input['configuration'] ?? []);

        DB::transaction(function () use ($actor, $id, $meta, $config, $ipAddress): void {
            $definition = DB::table('kpi_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($definition, 404);
            $latest = DB::table('kpi_versions')->where('definition_id', $id)->orderByDesc('version')->first();
            if ((int) $latest->version !== (int) $meta['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'أُضيف إصدار آخر لهذا المؤشر بعد فتح الصفحة؛ أعد تحميلها وراجع التغييرات.']);
            }
            if ($meta['effective_from'] < $latest->effective_from) {
                throw ValidationException::withMessages(['effective_from' => 'يوجد إصدار يبدأ في '.$latest->effective_from.'؛ لا يبدأ الإصدار الجديد قبله.']);
            }
            if ($definition->is_active && $config['source'] === KpiSources::UNAVAILABLE) {
                throw ValidationException::withMessages(['source' => 'عطّل المؤشر أولًا قبل تحويله إلى «غير متاح».']);
            }
            DB::table('kpi_versions')->where('definition_id', $id)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $meta['effective_from']))
                ->update(['effective_until' => $meta['effective_from']]);
            $number = (int) $latest->version + 1;
            DB::table('kpi_versions')->insert(['definition_id' => $id, 'version' => $number, 'effective_from' => $meta['effective_from'],
                'configuration' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'change_reason' => $meta['change_reason'],
                'created_by' => $actor->id, 'created_at' => now()]);
            DB::table('kpi_definitions')->where('id', $id)->update(['updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow('kpi.version.created', 'kpi_definitions', $id,
                ['version' => (int) $latest->version, 'configuration' => json_decode($latest->configuration, true)],
                ['version' => $number, 'effective_from' => $meta['effective_from'], 'reason' => $meta['change_reason'], 'configuration' => $config], (int) $actor->id, $ipAddress);
        });
    }

    /** @return array<string, mixed> */
    private function settingsRow(array $settings): array
    {
        return ['domain' => $settings['domain'], 'owner_department_id' => $settings['owner_department_id'] ?? null, 'audience' => $settings['audience'],
            'viewer_department_ids' => json_encode($settings['viewer_department_ids']), 'visible_role_codes' => json_encode($settings['visible_role_codes']),
            'on_executive_home' => (bool) $settings['on_executive_home'], 'on_department_home' => (bool) $settings['on_department_home'],
            'is_active' => (bool) $settings['is_active'], 'sort_order' => (int) $settings['sort_order']];
    }

    private function guardActivation(array $settings, array $config): void
    {
        if ($settings['is_active'] && ($config['source'] ?? null) === KpiSources::UNAVAILABLE) {
            throw ValidationException::withMessages(['is_active' => 'لا يُفعّل مؤشر لا مصدر لبياناته؛ أضف إصدارًا بمصدر معتمد أولًا.']);
        }
    }

    private function constant(int $id, string $type, string $field): object
    {
        $value = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('v.id', $id)->where('t.code', $type)->where('t.is_active', true)->where('v.is_active', true)->first(['v.id', 'v.code']);
        if (! $value) {
            throw ValidationException::withMessages([$field => 'اختر قيمة فعالة من ثوابت مؤشرات الأداء.']);
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $allowed
     * @return array<string, string>
     */
    private function filters(array $allowed, array $filters): array
    {
        $unknown = array_diff(array_keys($filters), array_keys($allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => 'فلتر غير مسموح لهذا المصدر.']);
        }
        foreach ($filters as $key => $value) {
            $valid = match ($key) {
                'workflow_code' => DB::table('workflow_definitions')->where('code', $value)->exists(),
                'subject_type' => DB::table('workflow_definitions')->where('subject_type', $value)->exists(),
                'step_role', 'assignee_role' => DB::table('roles')->where('code', $value)->exists(),
                'case_kind' => array_key_exists($value, RentalCasesSource::KINDS),
                'currency' => DB::table('currencies')->where('code', $value)->where('is_active', true)->exists(),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['filters' => 'قيمة الفلتر «'.$allowed[$key].'» غير موجودة في النظام.']);
            }
        }
        ksort($filters);

        return $filters;
    }

    /**
     * @param  array<string, array{label: string, min: int, max: int}>  $allowed
     * @return array<string, int>
     */
    private function params(array $allowed, array $params): array
    {
        $result = [];
        foreach ($allowed as $key => $rule) {
            $value = $params[$key] ?? null;
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ValidationException::withMessages(['params' => 'حدد «'.$rule['label'].'».']);
            }
            $value = (int) $value;
            if ($value < $rule['min'] || $value > $rule['max']) {
                throw ValidationException::withMessages(['params' => '«'.$rule['label'].'» بين '.$rule['min'].' و'.$rule['max'].'.']);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function checkThresholds(array $config): void
    {
        ['target' => $target, 'warning_threshold' => $warning, 'critical_threshold' => $critical, 'polarity' => $polarity] = $config;
        $ordered = fn (?float $worse, ?float $better): bool => $worse === null || $better === null || ($polarity === 'higher' ? $worse <= $better : $worse >= $better);
        if (! $ordered($critical, $warning) || ! $ordered($warning, $target) || ! $ordered($critical, $target)) {
            throw ValidationException::withMessages(['critical_threshold' => $polarity === 'higher'
                ? 'مع «الأعلى أفضل» تكون العتبة الحرجة ≤ عتبة التنبيه ≤ الهدف.'
                : 'مع «الأقل أفضل» تكون العتبة الحرجة ≥ عتبة التنبيه ≥ الهدف.']);
        }
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
