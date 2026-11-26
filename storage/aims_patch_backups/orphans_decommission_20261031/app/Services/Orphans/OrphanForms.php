<?php

namespace App\Services\Orphans;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Field rules of the orphans forms. Requiredness (required / optional / conditional / hidden) comes from the
 * versioned rules the admin manages (orphan_form_versions); types, lengths and identity formats are fixed in
 * code because they protect data integrity. An application keeps the version it was submitted under.
 */
final class OrphanForms
{
    /** Field => constants type that its value must belong to. */
    public const VALUE_TYPES = [
        'identity_type_value_id' => 'identity_document_types', 'orphan_class_value_id' => 'orphan_classifications',
        'vital_status_value_id' => 'vital_statuses', 'death_cause_value_id' => 'death_causes', 'marital_status_value_id' => 'marital_statuses',
        'education_level_value_id' => 'education_levels', 'enrollment_value_id' => 'enrollment_statuses', 'stage_value_id' => 'education_stages',
        'health_status_value_id' => 'health_statuses', 'income_source_value_id' => 'income_sources', 'economic_status_value_id' => 'economic_statuses',
        'needs_level_value_id' => 'needs_levels', 'previous_tenure_value_id' => 'housing_tenures', 'current_tenure_value_id' => 'housing_tenures',
        'shelter_type_value_id' => 'shelter_types', 'document_type_value_id' => 'orphan_document_types', 'channel_value_id' => 'payment_channels',
        'institution_value_id' => 'banks',
    ];

    public const LABELS = [
        'first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'grandfather_name' => 'اسم الجد', 'family_name' => 'اسم العائلة',
        'english_name' => 'الاسم بالإنجليزية', 'identity_type_value_id' => 'نوع وثيقة الهوية', 'identity_no' => 'رقم الوثيقة',
        'date_of_birth' => 'تاريخ الميلاد', 'place_of_birth' => 'مكان الميلاد', 'sex' => 'الجنس', 'orphan_class_value_id' => 'تصنيف اليتم',
        'sole_survivor' => 'الناجي الوحيد', 'phone' => 'رقم التواصل', 'family_size' => 'عدد أفراد الأسرة', 'dependents_count' => 'عدد المعالين',
        'working_members_count' => 'عدد العاملين', 'income_source_value_id' => 'مصدر الدخل', 'monthly_income' => 'الدخل الشهري',
        'economic_status_value_id' => 'الوضع الاقتصادي', 'needs_level_value_id' => 'مستوى الاحتياج', 'previous_tenure_value_id' => 'ملكية السكن السابق',
        'current_tenure_value_id' => 'ملكية السكن الحالي', 'shelter_type_value_id' => 'نوع المأوى', 'rent_amount' => 'قيمة الإيجار',
        'displaced' => 'نازحة', 'displaced_since' => 'تاريخ النزوح', 'basic_needs' => 'الاحتياجات الأساسية', 'original_address' => 'العنوان الأصلي',
        'current_address' => 'عنوان السكن/النزوح الحالي', 'full_name' => 'الاسم الرباعي', 'vital_status_value_id' => 'الحالة الحيوية',
        'death_date' => 'تاريخ الوفاة', 'death_cause_value_id' => 'سبب الوفاة', 'occupation' => 'العمل', 'marital_status_value_id' => 'الحالة الاجتماعية',
        'education_level_value_id' => 'المستوى التعليمي', 'specialization' => 'التخصص الجامعي', 'has_illness' => 'لديها مرض', 'illness_note' => 'وصف المرض',
        'has_disability' => 'لديه إعاقة', 'disability_types' => 'أنواع الإعاقة', 'school_year' => 'العام الدراسي', 'enrollment_value_id' => 'الحالة التعليمية',
        'stage_value_id' => 'المرحلة', 'school_name' => 'المدرسة', 'grade' => 'الصف', 'achievement' => 'التحصيل', 'support_needs' => 'احتياجات الدعم',
        'health_status_value_id' => 'الحالة الصحية', 'has_chronic' => 'مرض مزمن', 'chronic_note' => 'وصف موجز للمرض المزمن', 'medications' => 'الأدوية',
        'coverage' => 'التغطية العلاجية', 'follow_up' => 'المتابعة', 'name' => 'الاسم', 'alt_phone' => 'هاتف بديل', 'email' => 'البريد الإلكتروني',
        'employer' => 'جهة العمل',
    ];

    private const PHONE = 'regex:/^\+?[0-9]{8,15}$/';

    /** @var array<string, array<string, mixed>> */
    private array $versions = [];

    public function activeVersionId(): int
    {
        return (int) DB::table('orphan_form_versions')->where('status', 'active')->value('id');
    }

    /** @return array<string, array{req: string, when?: array<string, mixed>}> */
    public function rules(string $form, ?int $versionId = null): array
    {
        $versionId ??= $this->activeVersionId();
        $this->versions[$versionId] ??= json_decode((string) DB::table('orphan_form_versions')->where('id', $versionId)->value('rules'), true) ?: [];

        return $this->versions[$versionId][$form] ?? [];
    }

    /**
     * Options for the selects of the given fields, with the metadata flags conditions rely on.
     *
     * @param  list<string>  $fields
     * @return array<string, list<array{id: int, code: string, name: string, flags: array<string, mixed>}>>
     */
    public function options(array $fields): array
    {
        $types = collect($fields)->mapWithKeys(fn (string $field): array => [$field => self::VALUE_TYPES[$field] ?? $field])->all();
        $rows = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->whereIn('t.code', array_values($types))->where('v.is_active', true)
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', today()->toDateString()))
            ->orderBy('v.sort_order')->orderBy('v.id')->get(['t.code as type', 'v.id', 'v.code', 'v.name_ar', 'v.metadata'])->groupBy('type');
        $options = [];
        foreach ($types as $field => $type) {
            $options[$field] = ($rows[$type] ?? collect())->map(fn ($row): array => ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name_ar,
                'flags' => json_decode((string) $row->metadata, true) ?: []])->values()->all();
        }

        return $options;
    }

    /**
     * Validates one form against its base types and the versioned requiredness rules. Conditional fields whose
     * condition is false are returned as null so a stale value is never kept (e.g. a school for a dropped-out child).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<int, mixed>>  $base  field => base Laravel rules (no required/nullable)
     * @return array<string, mixed>
     */
    public function validate(string $form, array $input, array $base, ?int $versionId = null, string $prefix = ''): array
    {
        $rules = $this->rules($form, $versionId);
        $laravel = [];
        $active = [];
        foreach ($base as $field => $typeRules) {
            $rule = $rules[$field] ?? ['req' => 'optional'];
            $applies = match ($rule['req']) {
                'hidden' => false,
                'conditional' => $this->conditionHolds($rule['when'] ?? [], $input),
                default => true,
            };
            $active[$field] = $applies;
            if (! $applies) {
                continue;
            }
            $required = $rule['req'] === 'required' || $rule['req'] === 'conditional';
            $laravel[$field] = [$required ? 'required' : 'nullable', ...$typeRules];
            if (isset(self::VALUE_TYPES[$field])) {
                $laravel[$field][] = $this->valueOf(self::VALUE_TYPES[$field]);
            }
        }
        $attributes = collect(array_keys($laravel))->mapWithKeys(fn (string $f): array => [$f => self::LABELS[$f] ?? $f])->all();
        $validator = Validator::make($input, $laravel, [], $attributes);
        if ($validator->fails()) {
            $errors = collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $key): array => [$prefix.$key => $messages])->all();
            throw ValidationException::withMessages($errors);
        }
        $data = Arr::only($validator->validated(), array_keys($laravel));
        foreach ($active as $field => $applies) {
            if (! $applies || ! array_key_exists($field, $data)) {
                $data[$field] = null;
            }
            // A yes/no field left empty means "no": the columns behind them never hold an unknown state.
            if (in_array('boolean', $base[$field], true)) {
                $data[$field] = filter_var($data[$field] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('identity_no', $data) && $data['identity_no'] !== null) {
            $this->assertIdentityFormat($data['identity_type_value_id'] ?? null, (string) $data['identity_no'], $prefix);
        }

        return $data;
    }

    /** Identity format depends on the document type: the 9-digit rule is only for the Palestinian ID. */
    public function assertIdentityFormat(?int $typeId, string $number, string $prefix = ''): void
    {
        $type = $typeId ? DB::table('master_data_values')->where('id', $typeId)->first(['code', 'metadata']) : null;
        $pattern = $type ? (json_decode((string) $type->metadata, true)['pattern'] ?? null) : null;
        $valid = match (true) {
            $type?->code === 'palestinian_id' => (bool) preg_match('/^[0-9]{9}$/D', $number),
            is_string($pattern) && @preg_match($pattern, '') !== false => (bool) preg_match($pattern, $number),
            default => (bool) preg_match('/^[A-Za-z0-9\-]{3,40}$/D', $number),
        };
        if (! $valid) {
            throw ValidationException::withMessages([$prefix.'identity_no' => $type?->code === 'palestinian_id'
                ? 'رقم الهوية الفلسطينية يتكون من 9 أرقام بالأرقام الإنجليزية.' : 'رقم الوثيقة غير صالح لنوع الوثيقة المختار.']);
        }
    }

    /** @param array<string, mixed> $when */
    public function conditionHolds(array $when, array $input): bool
    {
        $value = $input[$when['field'] ?? ''] ?? null;
        if (array_key_exists('eq', $when)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $when['eq'] || $value === $when['eq'];
        }
        if (! empty($when['filled'])) {
            return $value !== null && $value !== '';
        }
        if (isset($when['meta'])) {
            $metadata = $value ? json_decode((string) DB::table('master_data_values')->where('id', (int) $value)->value('metadata'), true) : null;

            return ! empty($metadata[$when['meta']]);
        }
        if (isset($when['in'])) {
            return in_array($value, (array) $when['in'], false);
        }

        return false;
    }

    public function valueOf(string $type): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($type): void {
            $ok = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', $type)->where('v.id', (int) $value)->where('v.is_active', true)->exists();
            if (! $ok) {
                $fail('القيمة المختارة غير متاحة في الثوابت.');
            }
        };
    }

    public function codesOf(string $type): array
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', $type)->where('v.is_active', true)->pluck('v.code')->all();
    }

    /** Base type rules per form. @return array<string, array<int, mixed>> */
    public function base(string $form): array
    {
        $date = ['date', 'before_or_equal:today', 'after:1900-01-01'];
        $name = ['string', 'max:60'];
        $disabilities = ['array', 'max:10'];

        return match ($form) {
            'child' => ['first_name' => $name, 'father_name' => $name, 'grandfather_name' => $name, 'family_name' => $name, 'english_name' => ['string', 'max:150'],
                'identity_type_value_id' => ['integer'], 'identity_no' => ['string', 'max:40'], 'date_of_birth' => $date, 'place_of_birth' => ['string', 'max:150'],
                'sex' => ['in:male,female'], 'orphan_class_value_id' => ['integer'], 'sole_survivor' => ['boolean'], 'phone' => ['string', self::PHONE]],
            'household' => ['family_name' => ['string', 'max:100'], 'family_size' => ['integer', 'between:1,60'], 'dependents_count' => ['integer', 'between:0,60'],
                'working_members_count' => ['integer', 'between:0,60'], 'income_source_value_id' => ['integer'], 'monthly_income' => ['numeric', 'between:0,10000000'],
                'economic_status_value_id' => ['integer'], 'needs_level_value_id' => ['integer'], 'previous_tenure_value_id' => ['integer'],
                'current_tenure_value_id' => ['integer'], 'shelter_type_value_id' => ['integer'], 'rent_amount' => ['numeric', 'between:0,10000000'],
                'displaced' => ['boolean'], 'displaced_since' => $date, 'basic_needs' => ['string', 'max:1000']],
            'father', 'mother' => ['full_name' => ['string', 'max:200'], 'identity_type_value_id' => ['integer'], 'identity_no' => ['string', 'max:40'],
                'date_of_birth' => $date, 'vital_status_value_id' => ['integer'], 'death_date' => $date, 'death_cause_value_id' => ['integer'],
                'occupation' => ['string', 'max:150'], 'marital_status_value_id' => ['integer'], 'education_level_value_id' => ['integer'],
                'specialization' => ['string', 'max:150'], 'has_illness' => ['boolean'], 'illness_note' => ['string', 'max:200'],
                'has_disability' => ['boolean'], 'disability_types' => $disabilities],
            'education' => ['school_year' => ['string', 'regex:/^20[0-9]{2}\/20[0-9]{2}$/'], 'enrollment_value_id' => ['integer'], 'stage_value_id' => ['integer'],
                'school_name' => ['string', 'max:200'], 'grade' => ['string', 'max:40'], 'achievement' => ['string', 'max:120'], 'support_needs' => ['string', 'max:500']],
            'health' => ['health_status_value_id' => ['integer'], 'has_chronic' => ['boolean'], 'chronic_note' => ['string', 'max:200'],
                'has_disability' => ['boolean'], 'disability_types' => $disabilities, 'medications' => ['string', 'max:500'], 'coverage' => ['string', 'max:200'],
                'follow_up' => ['string', 'max:1000']],
            'guardian' => ['name' => ['string', 'min:3', 'max:200'], 'identity_type_value_id' => ['integer'], 'identity_no' => ['string', 'max:40'],
                'phone' => ['string', self::PHONE], 'alt_phone' => ['string', self::PHONE], 'email' => ['email', 'max:150'], 'date_of_birth' => $date,
                'sex' => ['in:male,female'], 'occupation' => ['string', 'max:150'], 'employer' => ['string', 'max:150']],
            default => [],
        };
    }

    /** Disability codes must exist in the constants; returns the clean list. @param mixed $codes */
    public function disabilityCodes(mixed $codes, string $field): ?array
    {
        if ($codes === null || $codes === []) {
            return null;
        }
        $allowed = $this->codesOf('disability_types');
        $codes = array_values(array_unique(array_map('strval', (array) $codes)));
        if (array_diff($codes, $allowed)) {
            throw ValidationException::withMessages([$field => 'نوع إعاقة غير معروف في الثوابت.']);
        }

        return $codes;
    }
}
