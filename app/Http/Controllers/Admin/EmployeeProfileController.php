<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Configuration\GazaAddress;
use App\Services\EmployeeAccountService;
use App\Services\EmployeeActivationService;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\EmployeeDocumentAlerts;
use App\Services\Hr\EmployeeFileChanges;
use App\Services\Hr\EmployeeNames;
use App\Services\Hr\EmployeePlacements;
use App\Services\Hr\EmployeeProfileView;
use App\Services\Hr\HrServicesDashboard;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EmployeeProfileController extends Controller
{
    /**
     * Constants lists the profile form offers as options.
     *
     * @var list<string>
     */
    private const LOOKUP_TYPES = [
        'marital_statuses', 'employment_terms', 'contract_types', 'work_schedules', 'administrative_levels', 'leave_types',
        'governorates', 'cities', 'areas', 'neighborhoods', 'kinship_types', 'academic_grades',
        'identity_document_types', 'nationalities',
    ];

    /** The identity document whose number must be exactly 9 digits (owner decision 2026-10-22). */
    private const PALESTINIAN_ID = 'palestinian_id';

    /**
     * Profile columns that hold a constants value, and the constants type each one must belong to.
     *
     * @var array<string, string>
     */
    private const VALUE_FIELDS = [
        'identity_document_type_value_id' => 'identity_document_types',
        'nationality_value_id' => 'nationalities',
        'marital_status_value_id' => 'marital_statuses',
        'employment_term_value_id' => 'employment_terms',
        'contract_type_value_id' => 'contract_types',
        'work_schedule_value_id' => 'work_schedules',
        'administrative_level_value_id' => 'administrative_levels',
    ];

    private const PHONE_PATTERN = 'regex:/^\+?[0-9]{8,15}$/D';

    /** Employees must be at least this old (Palestinian Labour Law: no employment under 15). */
    private const MINIMUM_AGE = 15;

    private function authorizeManagement(Request $request): void
    {
        abort_unless(
            $request->user()?->is_active && $request->user()->canManageEmployees(),
            403,
            'إدارة ملفات الموظفين متاحة لمدير النظام ورئيس الموارد البشرية فقط.'
        );
    }

    /**
     * The files list, the forms and the lookups: HR management, and HR staff whose saves wait for the HR head's
     * approval (EmployeeFileChanges). Account actions, exports and imports stay with HR management.
     */
    private function authorizeFiles(Request $request): void
    {
        abort_unless(
            $request->user() && app(EmployeeAccess::class)->canOpenFiles($request->user()),
            403,
            'ملفات الموظفين متاحة لإدارة الموارد البشرية وموظفيها.'
        );
    }

    private function navigation(): array
    {
        return [
            ['label' => 'لوحة التحكم', 'url' => route('ui.preview'), 'icon' => '▦', 'active' => false],
            ['label' => 'إدارة الموظفين', 'url' => route('admin.employees.index'), 'icon' => '👥', 'active' => false],
            ['label' => 'ملفات الموظفين', 'url' => route('admin.employee-profiles.index'), 'icon' => '📁', 'active' => true],
        ];
    }

    /**
     * Selectable constants: the value, its type and its group are active and today is inside the value's effective window.
     */
    private function selectableValues(array $typeCodes): Collection
    {
        $today = now()->toDateString();

        return DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->join('master_data_groups as g', 'g.id', '=', 't.group_id')
            ->whereIn('t.code', $typeCodes)
            ->where('g.is_active', true)->where('t.is_active', true)->where('v.is_active', true)
            ->where(fn ($query) => $query->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $today))
            ->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['v.id', 'v.name_ar', 'v.code', 't.code as type_code']);
    }

    private function lookups(Request $request, ?Employee $employee = null): array
    {
        $values = $this->selectableValues(self::LOOKUP_TYPES)->map(fn (object $value): object => (object) [...(array) $value, 'selectable' => true]);

        // A value the employee already holds stays visible on the edit form even after it was deactivated.
        $held = $employee ? array_values(array_filter(array_map(fn (string $field) => $employee->{$field}, array_keys(self::VALUE_FIELDS)))) : [];
        $missing = array_diff($held, $values->pluck('id')->all());
        if ($missing) {
            $values = $values->concat(DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->whereIn('v.id', $missing)->get(['v.id', 'v.name_ar', 'v.code', 't.code as type_code'])
                ->map(fn (object $value): object => (object) [...(array) $value, 'selectable' => false]));
        }

        return [
            'departments' => DB::table('departments')->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
            'jobCategories' => DB::table('job_categories')
                ->orderBy('sort_order')->get(['id', 'code', 'name_ar', 'is_active']),
            'jobTitles' => DB::table('job_titles')
                ->orderBy('sort_order')->get(['id', 'job_category_id', 'code', 'name_ar', 'is_active']),
            'masterData' => $values->values(),
            'jobGrades' => DB::table('job_grades as g')
                ->join('career_scales as s', 's.id', '=', 'g.career_scale_id')
                ->orderBy('s.sort_order')->orderBy('g.sort_order')
                ->get(['g.id', 'g.career_scale_id', 'g.code', 'g.name_ar', 'g.is_active', 's.name_ar as scale_name', 's.is_active as scale_is_active']),
            // Where each constants list is maintained; links are shown to the system administrator only.
            'constantsLinks' => $request->user()->isSystemAdmin()
                ? DB::table('master_data_types')->whereIn('code', self::LOOKUP_TYPES)->get(['code', 'group_id', 'id'])
                    ->mapWithKeys(fn (object $type): array => [$type->code => route('admin.master-data.index', ['group_id' => $type->group_id, 'type_id' => $type->id], false)])
                : (object) [],
            // Second way to open the account (system administrator only): active now with a temporary password.
            'canActivateDirectly' => $request->user()->isSystemAdmin(),
            // HR staff: the form sends to the HR head for approval instead of saving.
            'submitsForReview' => app(EmployeeAccess::class)->canPrepare($request->user()),
        ];
    }

    /**
     * The activation chosen when an account is created: «sms» (default) or «direct», which only the system
     * administrator may choose, with a temporary password the employee must change at the first sign-in.
     *
     * @return array{direct: bool, password: ?string}
     */
    private function activationChoice(Request $request): array
    {
        $data = $request->validate([
            'activation_method' => ['nullable', 'in:sms,direct'],
            'temporary_password' => ['nullable', 'required_if:activation_method,direct', 'string', 'min:10', 'max:128', 'confirmed'],
        ], [], ['temporary_password' => 'كلمة المرور المؤقتة']);
        $direct = ($data['activation_method'] ?? 'sms') === 'direct';
        abort_if($direct && ! $request->user()->isSystemAdmin(), 403, 'إنشاء حساب مفعّل دون SMS لمدير النظام فقط.');

        return ['direct' => $direct, 'password' => $direct ? (string) $data['temporary_password'] : null];
    }

    /** Display-only summary; pending policies are NEVER used to credit balances. */
    private function leaveOnboardingContext(?int $employeeId = null): array
    {
        $correction = DB::table('leave_policy_corrections')
            ->where('original_rule_id', 1)->first(['status', 'go_live_date', 'replacement_rules']);
        $tiers = [];
        if ($correction) {
            $payload = json_decode((string) $correction->replacement_rules, true);
            $tiers = is_array($payload) ? ($payload['tiers'] ?? []) : [];
        }

        return [
            'policy_status' => $correction?->status ?? 'missing',
            'go_live_date' => $correction?->go_live_date,
            'annual_policy_tiers' => $tiers,
            'opening_balances' => $employeeId
                ? DB::table('employee_leave_opening_balances')
                    ->where('employee_id', $employeeId)
                    ->orderByDesc('balance_year')->get([
                        'leave_type_value_id', 'balance_year',
                        'annual_entitlement_days', 'opening_balance_days', 'notes',
                    ])->toArray()
                : [],
        ];
    }

    private function employeeOr404(int $id): Employee
    {
        return Employee::query()->findOrFail($id);
    }

    /**
     * Remove untouched placeholder rows, preserving existing IDs and
     * incomplete rows so required-field validation still catches mistakes.
     */
    private function cleanBlankChildren(Request $request): array
    {
        $groups = [
            'addresses' => ['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'governorate', 'city', 'area', 'neighborhood', 'address_details', 'notes', 'valid_from', 'valid_to'],
            'qualifications' => ['qualification_name', 'degree_level', 'institution_name', 'specialization', 'issuing_country_code', 'study_start_date', 'graduation_date', 'graduation_year', 'grade', 'gpa', 'gpa_scale', 'certificate_number', 'notes'],
            'family_members' => ['relationship_code', 'full_name_ar', 'full_name_en', 'national_id', 'date_of_birth', 'phone', 'notes'],
            'leave_opening_balances' => ['leave_type_value_id', 'balance_year', 'annual_entitlement_days', 'opening_balance_days', 'notes'],
        ];

        $clean = [];
        foreach ($groups as $key => $fields) {
            if (! $request->exists($key) || ! is_array($request->input($key))) {
                continue;
            }

            $clean[$key] = array_values(array_filter($request->input($key), function ($row) use ($fields) {
                if (! is_array($row) || ! empty($row['id'])) {
                    return true;
                }
                foreach ($fields as $field) {
                    if (isset($row[$field]) && trim((string) $row[$field]) !== '') {
                        return true;
                    }
                }

                return false;
            }));
        }

        // ISO country codes are stored in capitals (PS, EG, JO).
        foreach ($clean['qualifications'] ?? [] as $index => $row) {
            if (is_array($row) && isset($row['issuing_country_code']) && is_string($row['issuing_country_code'])) {
                $clean['qualifications'][$index]['issuing_country_code'] = strtoupper(trim($row['issuing_country_code']));
            }
        }

        return $clean;
    }

    private function rules(?Employee $employee = null): array
    {
        $youngestBirthDate = now()->subYears(self::MINIMUM_AGE)->toDateString();

        $rules = [
            'first_name_ar' => ['required', 'string', 'max:150'],
            'father_name_ar' => ['nullable', 'string', 'max:150'],
            'grandfather_name_ar' => ['nullable', 'string', 'max:150'],
            'family_name_ar' => ['required', 'string', 'max:150'],
            'full_name_ar' => ['nullable', 'string', 'max:255'],
            'original_family_name_ar' => ['nullable', 'string', 'max:150'],
            'name_parts_reviewed' => ['sometimes', 'boolean'],
            'children_count' => ['nullable', 'integer', 'between:0,40'],
            'landline_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/D', 'max:30'],
            'residence_address' => ['nullable', 'string', 'max:2000'],
            'attendance_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'full_name_en' => ['nullable', 'string', 'max:255'],
            // The document type decides the format: 9 digits for the Palestinian identity card (checked in verifyPersonalData).
            'national_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D'],
            'identity_document_type_value_id' => ['nullable', 'integer'],
            'nationality_value_id' => ['nullable', 'integer'],
            'date_of_birth' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:'.$youngestBirthDate],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            // The primary mobile receives the activation code and urgent notifications.
            'phone' => ['required', 'string', self::PHONE_PATTERN, 'max:30'],
            'alternative_phone' => ['nullable', 'string', self::PHONE_PATTERN, 'max:30', 'different:phone'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'job_category_id' => ['nullable', 'integer', Rule::exists('job_categories', 'id')->where('is_active', true)],
            'job_title_id' => ['nullable', 'integer', Rule::exists('job_titles', 'id')->where('is_active', true)],
            'job_grade_id' => ['nullable', 'integer', Rule::exists('job_grades', 'id')->where('is_active', true)],
            'hire_date' => ['nullable', 'date', 'after:1950-01-01'],
            'work_start_date' => ['nullable', 'date', 'after:1950-01-01'],
            'employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave', 'terminated'])],
            'notes' => ['nullable', 'string', 'max:10000'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'marital_status_value_id' => ['nullable', 'integer'],
            'has_qualifications' => ['nullable', 'boolean'],
            'has_family_members' => ['nullable', 'boolean'],
            'employment_term_value_id' => ['nullable', 'integer'],
            'contract_type_value_id' => ['nullable', 'integer'],
            'work_schedule_value_id' => ['nullable', 'integer'],
            'administrative_level_value_id' => ['nullable', 'integer'],
            'confirm_no_opening_balances' => ['sometimes', 'boolean'],

            'leave_opening_balances' => ['sometimes', 'array', 'max:30'],
            'leave_opening_balances.*' => ['required', 'array'],
            'leave_opening_balances.*.id' => ['sometimes', 'integer', 'distinct'],
            'leave_opening_balances.*.leave_type_value_id' => ['required', 'integer'],
            // Same rules as «أرصدة الإجازات»: current year only, documented, never credited automatically.
            'leave_opening_balances.*.balance_year' => ['required', 'integer', 'in:'.now()->year],
            'leave_opening_balances.*.annual_entitlement_days' => ['required', 'numeric', 'min:0', 'max:366', 'decimal:0,2'],
            'leave_opening_balances.*.opening_balance_days' => ['required', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
            'leave_opening_balances.*.notes' => ['required', 'string', 'min:5', 'max:2000'],

            // Optional child collections. A missing collection means "do not change";
            // sending [] never deletes existing records.
            'addresses' => ['sometimes', 'array', 'max:30'],
            'addresses.*' => ['required', 'array'],
            'addresses.*.id' => ['sometimes', 'integer', 'distinct'],
            'addresses.*.address_type' => ['required', Rule::in(['original', 'current', 'previous'])],
            'addresses.*.governorate' => ['nullable', 'string', 'max:150'],
            'addresses.*.city' => ['nullable', 'string', 'max:150'],
            ...GazaAddress::rules('addresses.*.'),
            'addresses.*.area' => ['nullable', 'string', 'max:150'],
            'addresses.*.neighborhood' => ['nullable', 'string', 'max:255'],
            'addresses.*.address_details' => ['nullable', 'string', 'max:10000'],
            'addresses.*.notes' => ['nullable', 'string', 'max:10000'],
            'addresses.*.valid_from' => ['nullable', 'date'],
            'addresses.*.valid_to' => ['nullable', 'date'],
            'addresses.*.is_active' => ['sometimes', 'boolean'],

            'qualifications' => ['sometimes', 'array', 'max:30'],
            'qualifications.*' => ['required', 'array'],
            'qualifications.*.id' => ['sometimes', 'integer', 'distinct'],
            'qualifications.*.qualification_name' => ['required', 'string', 'max:255'],
            'qualifications.*.degree_level' => ['nullable', 'string', 'max:100'],
            'qualifications.*.institution_name' => ['nullable', 'string', 'max:255'],
            'qualifications.*.specialization' => ['nullable', 'string', 'max:255'],
            'qualifications.*.issuing_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2,3}$/D'],
            'qualifications.*.study_start_date' => ['nullable', 'date'],
            'qualifications.*.graduation_date' => ['nullable', 'date'],
            'qualifications.*.graduation_year' => ['nullable', 'integer', 'between:1940,'.(now()->year + 1)],
            'qualifications.*.grade' => ['nullable', 'string', 'max:100'],
            'qualifications.*.gpa' => ['nullable', 'numeric', 'between:0,999.999'],
            'qualifications.*.gpa_scale' => ['nullable', 'string', 'max:30'],
            'qualifications.*.certificate_number' => ['nullable', 'string', 'max:150'],
            'qualifications.*.notes' => ['nullable', 'string', 'max:10000'],
            'qualifications.*.is_highest_qualification' => ['sometimes', 'boolean'],

            'family_members' => ['sometimes', 'array', 'max:100'],
            'family_members.*' => ['required', 'array'],
            'family_members.*.id' => ['sometimes', 'integer', 'distinct'],
            'family_members.*.relationship_code' => ['required', 'string', 'max:100'],
            'family_members.*.full_name_ar' => ['required', 'string', 'max:255'],
            'family_members.*.full_name_en' => ['nullable', 'string', 'max:255'],
            'family_members.*.national_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D', 'distinct'],
            'family_members.*.date_of_birth' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:today'],
            'family_members.*.phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/D', 'max:30'],
            'family_members.*.notes' => ['nullable', 'string', 'max:1000'],
            'family_members.*.is_dependent' => ['sometimes', 'boolean'],
            'family_members.*.is_active' => ['sometimes', 'boolean'],
        ];

        if ($employee === null) {
            // Existing accounts may be linked only after explicit HR confirmation;
            // duplicates in employees (including archived ones) are rejected in store().
            $rules['employee_number'] = ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'];
            $rules['link_existing_account'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => 'تاريخ الميلاد يجعل عمر الموظف أقل من '.self::MINIMUM_AGE.' سنة. راجع التاريخ.',
            'alternative_phone.different' => 'الجوال الاحتياطي مطابق للجوال الأساسي؛ اتركه فارغًا أو أدخل رقمًا آخر.',
            'qualifications.*.issuing_country_code.regex' => 'رمز دولة إصدار الشهادة (المؤهل :position) حرفان أو ثلاثة بالإنجليزية، مثل PS أو EG.',
            'family_members.*.national_id.distinct' => 'رقم هوية فرد العائلة :position مكرر في القائمة.',
            'family_members.*.national_id.regex' => 'رقم هوية فرد العائلة :position يقبل الأرقام والحروف الإنجليزية فقط، دون مسافات.',
            'national_id.regex' => 'رقم الوثيقة يقبل الأرقام والحروف الإنجليزية فقط، دون مسافات أو شرطات.',
            'leave_opening_balances.*.balance_year.in' => 'يُسجَّل الرصيد الافتتاحي للسنة الحالية ('.now()->year.') فقط؛ السنوات السابقة تحتاج ترحيلًا معتمدًا.',
            'leave_opening_balances.*.notes.required' => 'اكتب مرجع المستند المعتمد للرصيد :position (مثل رقم الكتاب أو الكشف).',
        ];
    }

    /**
     * Constants-backed fields must hold an active value of the right type. A value the employee already
     * holds is accepted unchanged on edit, so deactivating a constant never blocks saving an old profile.
     */
    private function verifyReferences(array $data, ?Employee $record = null): void
    {
        foreach (self::VALUE_FIELDS as $field => $type) {
            if (empty($data[$field]) || ($record !== null && (int) $record->{$field} === (int) $data[$field])) {
                continue;
            }
            if (! $this->selectableValues([$type])->contains('id', (int) $data[$field])) {
                throw ValidationException::withMessages([
                    $field => 'القيمة المحددة غير موجودة ضمن الثوابت النشطة الصحيحة.',
                ]);
            }
        }

        $categoryId = $data['job_category_id'] ?? null;
        $titleId = $data['job_title_id'] ?? null;
        if ($titleId !== null) {
            $title = DB::table('job_titles')->where('id', $titleId)->first();
            if (! $title || $categoryId === null || (int) $title->job_category_id !== (int) $categoryId) {
                throw ValidationException::withMessages([
                    'job_title_id' => 'المسمى الوظيفي يجب أن يكون تابعًا للفئة الوظيفية المحددة.',
                ]);
            }
            if (! DB::table('job_categories')->where('id', $categoryId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['job_category_id' => 'الفئة الوظيفية غير نشطة.']);
            }
        }

        if (! empty($data['job_grade_id'])) {
            $validGrade = DB::table('job_grades as g')
                ->join('career_scales as s', 's.id', '=', 'g.career_scale_id')
                ->where('g.id', $data['job_grade_id'])
                ->where('g.is_active', true)->where('s.is_active', true)->exists();
            if (! $validGrade) {
                throw ValidationException::withMessages(['job_grade_id' => 'الدرجة أو السلم الوظيفي غير نشط.']);
            }
        }

        $this->verifyConstantChoices($data, $record);
        $this->verifyPersonalData($data, $record);
    }

    /**
     * Governorates and kinship come from the constants lists. Cities, areas and neighbourhoods are suggested
     * from the constants but may be typed, because those lists are still being completed.
     */
    private function verifyConstantChoices(array $data, ?Employee $record): void
    {
        $errors = [];

        $kinship = $this->selectableValues(['kinship_types'])->pluck('code')->all();
        foreach ($data['family_members'] ?? [] as $index => $row) {
            $value = trim((string) ($row['relationship_code'] ?? ''));
            if ($value === '' || in_array($value, $kinship, true)) {
                continue;
            }
            $stored = ($record && ! empty($row['id'])) ? $record->familyMembers()->whereKey($row['id'])->value('relationship_code') : null;
            if ($stored !== $value) {
                $errors["family_members.{$index}.relationship_code"] = $kinship
                    ? 'اختر صلة القرابة من القائمة المعتمدة في ثوابت النظام.'
                    : 'لا توجد صلات قرابة معتمدة في ثوابت النظام بعد. يضيفها مدير النظام من «ثوابت النظام ← ثوابت البيانات الاجتماعية ← صلات القرابة».';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The document number follows its type: exactly 9 digits for the Palestinian identity card, letters and
     * digits for a passport or a foreign card. One document belongs to one current employee; hire and start
     * dates follow the birth date.
     */
    private function verifyPersonalData(array $data, ?Employee $record): void
    {
        $nationalId = trim((string) ($data['national_id'] ?? ''));
        $documentTypeId = array_key_exists('identity_document_type_value_id', $data)
            ? ($data['identity_document_type_value_id'] ?: null)
            : $record?->identity_document_type_value_id;
        if ($nationalId !== '') {
            if (! $documentTypeId) {
                throw ValidationException::withMessages(['identity_document_type_value_id' => 'اختر نوع الوثيقة التي يتبعها الرقم.']);
            }
            $documentType = DB::table('master_data_values')->where('id', $documentTypeId)->value('code');
            if ($documentType === self::PALESTINIAN_ID && ! preg_match('/^[0-9]{9}$/D', $nationalId)) {
                throw ValidationException::withMessages(['national_id' => 'رقم الهوية الفلسطينية يتكون من 9 أرقام بالأرقام الإنجليزية.']);
            }
            $holder = Employee::query()->where('national_id', $nationalId)
                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                ->first(['employee_number']);
            if ($holder) {
                throw ValidationException::withMessages([
                    'national_id' => "رقم الوثيقة مسجل في ملف موظف آخر (الرقم الوظيفي {$holder->employee_number}).",
                ]);
            }
        }

        $attendance = trim((string) ($data['attendance_number'] ?? ''));
        if ($attendance !== '' && Employee::query()->where('attendance_number', $attendance)->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
            throw ValidationException::withMessages(['attendance_number' => 'رقم جهاز البصمة مسجل لموظف آخر.']);
        }

        $birth = ! empty($data['date_of_birth']) ? Carbon::parse($data['date_of_birth']) : $record?->date_of_birth;
        foreach (['hire_date' => 'تاريخ التعيين', 'work_start_date' => 'تاريخ مباشرة العمل'] as $field => $label) {
            if ($birth && ! empty($data[$field]) && Carbon::parse($data[$field])->lte($birth)) {
                throw ValidationException::withMessages([$field => "{$label} يسبق تاريخ الميلاد أو يساويه. راجع التاريخين."]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra  e.g. the prepared change it applies and who prepared it
     */
    private function audit(Request $request, string $action, int $id, ?array $before, array $after, array $relatedTypes = [], array $extra = []): void
    {
        // Compare dates as Y-m-d and booleans as 0/1 so unchanged fields are not reported as changed.
        $comparable = fn (array $values): array => array_map(fn ($value) => match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => (int) $value,
            is_scalar($value), $value === null => $value,
            default => json_encode($value),
        }, $values);
        $changed = $before === null
            ? array_keys($after)
            : array_keys(array_diff_assoc($comparable($after), $comparable($before)));

        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'employees',
            'subject_id' => $id,
            // Do not copy personal, family or educational data into the audit log.
            'metadata' => json_encode([
                'changed_fields' => $changed,
                'related_collections_touched' => $relatedTypes,
                ...$extra,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function index(Request $request, EmployeeAccess $access, EmployeeFileChanges $changes): Response
    {
        $this->authorizeFiles($request);

        $filters = $this->listFilters($request);
        $user = $request->user();

        return Inertia::render('Admin/EmployeeProfiles/Index', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'employees' => $this->listQuery($filters)
                ->select(['e.id', 'e.user_id', 'e.employee_number', 'e.attendance_number', 'e.first_name_ar', 'e.family_name_ar', 'e.full_name_ar', 'e.employment_status',
                    'e.onboarding_status', 'e.name_review_status', 'e.import_batch_id', 'e.department_id', 'e.department_source', 'd.name as department_name',
                    'jt.name_ar as job_title_name', 'e.job_title_source', 'u.is_active as account_active'])
                ->paginate(20)->withQueryString(),
            'filters' => $filters,
            'departments' => DB::table('departments')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'smsEnabled' => (bool) config('services.hotsms.enabled', false),
            'accountsWithoutProfile' => $this->accountsWithoutProfile(),
            'can' => ['manage' => $access->canManage($user), 'prepare' => $access->canPrepare($user), 'review' => $access->canReviewChanges($user)],
            // What HR staff prepared: waiting for the HR head (management), or the viewer's own open ones (HR staff).
            'fileChanges' => EmployeeFileChanges::available() ? ['url' => EmployeeFileChanges::url(),
                'count' => $access->canManage($user) ? $changes->pendingCount() : count($changes->rows($user, 'open'))] : null,
        ]);
    }

    /**
     * @return array{search: string, department: int, state: string}
     */
    private function listFilters(Request $request): array
    {
        $state = (string) $request->query('state', '');

        return [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'department' => max(0, $request->integer('department')),
            'state' => in_array($state, ['imported', 'no_account', 'draft', 'name_review', 'review', 'documents_expiring', 'documents_missing'], true) ? $state : '',
        ];
    }

    /**
     * @param  array{search: string, department: int, state: string}  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $search = $filters['search'];

        return DB::table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('job_titles as jt', 'jt.id', '=', 'e.job_title_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->whereNull('e.deleted_at')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($search, $like) {
                    $q->where('e.employee_number', 'ilike', $like)
                        ->orWhere('e.attendance_number', $search)
                        ->orWhere('e.full_name_ar', 'ilike', $like)
                        ->orWhere('e.first_name_ar', 'ilike', $like)
                        ->orWhere('e.father_name_ar', 'ilike', $like)
                        ->orWhere('e.family_name_ar', 'ilike', $like)
                        ->orWhere('e.national_id', $search)
                        ->orWhere('e.phone', 'ilike', $like);
                });
            })
            ->when($filters['department'] > 0, fn ($query) => $query->where('e.department_id', $filters['department']))
            ->when($filters['state'] === 'imported', fn ($query) => $query->whereNotNull('e.import_batch_id'))
            ->when($filters['state'] === 'no_account', fn ($query) => $query->whereNull('e.user_id'))
            ->when($filters['state'] === 'draft', fn ($query) => $query->where('e.onboarding_status', 'draft'))
            ->when($filters['state'] === 'name_review', fn ($query) => $query->where('e.name_review_status', 'suggested'))
            ->when($filters['state'] === 'review', fn ($query) => $query->whereIn('e.id', app(HrServicesDashboard::class)->staffFilesForReview()->select('e.id')))
            // The same rows the HR dashboards count and «hr:document-alerts» announces (EmployeeDocumentAlerts).
            ->when($filters['state'] === 'documents_expiring', fn ($query) => $query->whereIn('e.id', app(EmployeeDocumentAlerts::class)->expiring()->select('e.id')))
            ->when($filters['state'] === 'documents_missing', fn ($query) => ($missing = app(EmployeeDocumentAlerts::class)->missing()) !== null
                ? $query->whereIn('e.id', $missing->select('e.id')) : $query->whereRaw('false'))
            ->orderByDesc('e.id');
    }

    /**
     * The staff list as CSV for HR: job and status columns only. Identity numbers, phones, addresses, pay and bank
     * data are never exported from here. Every export is audited.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeManagement($request);
        $filters = $this->listFilters($request);
        $rows = $this->listQuery($filters)->limit(5000)->get(['e.employee_number', 'e.attendance_number', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar',
            'd.name as department', 'e.department_source', 'jt.name_ar as job_title', 'e.job_title_source', 'e.hire_date', 'e.employment_status', 'e.onboarding_status', 'u.is_active']);
        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id, 'action' => 'employee_profile.list_exported', 'subject_type' => 'employees', 'subject_id' => '0',
            'metadata' => json_encode(['filters' => $filters, 'rows' => $rows->count(), 'columns' => 'job_and_status_only'], JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cell = fn (?string $value): string => preg_match('/^[=+\-@]/', (string) $value) ? "'".$value : (string) $value;

        return response()->streamDownload(function () use ($rows, $cell): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الرقم الوظيفي', 'رقم البصمة', 'الاسم', 'القسم', 'الوحدة كما وردت', 'المسمى', 'المسمى كما ورد', 'تاريخ التعيين', 'الحالة الوظيفية', 'حالة الملف', 'حساب الدخول'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map($cell, [$row->employee_number, $row->attendance_number, EmployeeNames::display($row), $row->department, $row->department_source,
                    $row->job_title, $row->job_title_source, $row->hire_date, $row->employment_status, $row->onboarding_status,
                    $row->is_active === null ? 'بلا حساب' : ($row->is_active ? 'نشط' : 'غير نشط')]), ',', '"', '');
            }
            fclose($out);
        }, 'employees-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Staff accounts that sign in today but have no employee profile yet (leave requests need one).
     * The system administrator's technical account is not an employee and is left out.
     */
    private function accountsWithoutProfile(): Collection
    {
        return DB::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('employees as e')->whereColumn('e.user_id', 'u.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.user_id', 'u.id')->where('r.code', 'system_admin'))
            ->where('u.is_active', true)
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.employee_number', 'd.name as department_name',
                // An unlinked profile with the same number (e.g. imported) is linked from that profile, not created again.
                DB::raw('(select p.id from employees p where p.employee_number = u.employee_number and p.user_id is null and p.deleted_at is null limit 1) as profile_id')]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeFiles($request);

        return Inertia::render('Admin/EmployeeProfiles/Form', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'employee' => null,
            'prefill' => $this->prefillFromAccount($request->integer('account')),
            'leaveOnboarding' => $this->leaveOnboardingContext(),
            'lookups' => $this->lookups($request),
        ]);
    }

    /**
     * Starting values for completing the profile of an existing account: its number, department and the
     * first and last words of its name. The form still asks HR to confirm the link after checking identity.
     *
     * @return array<string, mixed>|null
     */
    private function prefillFromAccount(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $account = $this->accountsWithoutProfile()->firstWhere('id', $userId);
        if (! $account) {
            return null;
        }
        $words = preg_split('/\s+/u', trim((string) $account->name)) ?: [];
        // Compound names: «عبد المجيد» as a first name, «أبو شمالة» as a family name.
        $first = array_shift($words) ?? '';
        if (in_array($first, ['عبد', 'أبو', 'ابو'], true) && count($words) > 1) {
            $first .= ' '.array_shift($words);
        }
        $family = array_pop($words) ?? '';
        if ($words && in_array(end($words), ['أبو', 'ابو', 'آل', 'بن', 'بني'], true)) {
            $family = array_pop($words).' '.$family;
        }

        return [
            'employee_number' => $account->employee_number,
            'first_name_ar' => $first,
            'family_name_ar' => $family,
            'department_id' => DB::table('users')->where('id', $userId)->value('department_id'),
            'account_name' => $account->name,
        ];
    }

    /**
     * Server-side final-onboarding gate: never rely on the five-step UI alone.
     * The user must explicitly complete each applicable section.
     */
    private function validateFinalOnboarding(array $data): void
    {
        $errors = [];

        foreach (['father_name_ar', 'grandfather_name_ar', 'national_id', 'gender',
            'date_of_birth', 'place_of_birth', 'phone', 'department_id',
            'job_category_id', 'job_title_id', 'hire_date', 'work_start_date'] as $field) {
            if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
                $errors[$field] = 'هذا الحقل مطلوب لاعتماد ملف الموظف.';
            }
        }

        // Leave entitlement depends on the employment term and the contract type.
        foreach (['identity_document_type_value_id', 'nationality_value_id', 'marital_status_value_id', 'employment_term_value_id', 'contract_type_value_id',
            'has_qualifications', 'has_family_members'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = 'يجب تحديد القيمة لإكمال المرحلة.';
            }
        }

        $addresses = collect($data['addresses'] ?? []);
        if (! $addresses->contains(fn ($a) => ($a['address_type'] ?? null) === 'original' && (bool) ($a['is_active'] ?? true))) {
            $errors['addresses'] = 'يجب إدخال عنوان السكن الأصلي.';
        }
        if (! $addresses->contains(fn ($a) => ($a['address_type'] ?? null) === 'current' && (bool) ($a['is_active'] ?? true))) {
            $errors['addresses.current'] = 'يجب إدخال عنوان السكن الحالي أو تأكيده حتى لو كان مطابقًا للأصلي.';
        }

        $hasQualifications = filter_var($data['has_qualifications'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $qualifications = $data['qualifications'] ?? [];
        if ($hasQualifications && count($qualifications) < 1) {
            $errors['qualifications'] = 'أدخل مؤهلًا واحدًا على الأقل، أو أكّد عدم وجود مؤهلات.';
        }
        if (! $hasQualifications && count($qualifications) > 0) {
            $errors['has_qualifications'] = 'حدد وجود مؤهلات بما يتوافق مع السجلات المدخلة.';
        }

        $hasFamily = filter_var($data['has_family_members'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $family = $data['family_members'] ?? [];
        if ($hasFamily && count($family) < 1) {
            $errors['family_members'] = 'أدخل بيانات العائلة أو أكد عدم وجودها.';
        }
        if (! $hasFamily && count($family) > 0) {
            $errors['has_family_members'] = 'أكد وجود أفراد العائلة قبل إضافة سجلاتهم.';
        }

        $leaves = $data['leave_opening_balances'] ?? [];
        $noBalances = filter_var($data['confirm_no_opening_balances'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (count($leaves) === 0 && ! $noBalances) {
            $errors['leave_opening_balances'] = 'أدخل بيانات الإجازات أو أكد عدم وجود أرصدة افتتاحية.';
        }
        if (count($leaves) > 0 && $noBalances) {
            $errors['confirm_no_opening_balances'] = 'لا يمكن تأكيد عدم وجود أرصدة مع إدخال أرصدة.';
        }

        $leaveTypes = $this->selectableValues(['leave_types'])->pluck('id')->all();
        $seen = [];
        foreach ($leaves as $i => $row) {
            $key = ($row['leave_type_value_id'] ?? '').'-'.($row['balance_year'] ?? '');
            if (isset($seen[$key])) {
                $errors["leave_opening_balances.$i.leave_type_value_id"] = 'نوع الإجازة والسنة مكرران.';
            }
            $seen[$key] = true;
            if (! in_array((int) ($row['leave_type_value_id'] ?? 0), $leaveTypes, true)) {
                $errors["leave_opening_balances.$i.leave_type_value_id"] = 'نوع الإجازة غير معتمد في ثوابت الموارد البشرية.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function saveOpeningBalances(Employee $employee, array $rows, int $actor): void
    {
        foreach ($rows as $row) {
            DB::table('employee_leave_opening_balances')->insert([
                'employee_id' => $employee->id,
                'leave_type_value_id' => $row['leave_type_value_id'],
                'balance_year' => $row['balance_year'],
                'annual_entitlement_days' => $row['annual_entitlement_days'],
                'opening_balance_days' => $row['opening_balance_days'],
                'notes' => $row['notes'] ?? null,
                'created_by' => $actor, 'updated_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Address and qualification rules that span several rows, checked before anything is written.
     */
    private function verifyRows(array $data): void
    {
        $activeAddresses = [];
        foreach ($data['addresses'] ?? [] as $index => $row) {
            if (! empty($row['valid_from']) && ! empty($row['valid_to']) && $row['valid_to'] < $row['valid_from']) {
                throw ValidationException::withMessages(["addresses.{$index}.valid_to" => 'تاريخ نهاية السكن يسبق بدايته.']);
            }
            if (in_array($row['address_type'], ['original', 'current'], true) && (bool) ($row['is_active'] ?? true)) {
                if (isset($activeAddresses[$row['address_type']])) {
                    throw ValidationException::withMessages(["addresses.{$index}.address_type" => 'لا يجوز تكرار عنوان نشط من النوع نفسه.']);
                }
                $activeAddresses[$row['address_type']] = true;
            }
        }

        foreach ($data['qualifications'] ?? [] as $index => $row) {
            if (! empty($row['study_start_date']) && ! empty($row['graduation_date']) && $row['graduation_date'] < $row['study_start_date']) {
                throw ValidationException::withMessages(["qualifications.{$index}.graduation_date" => 'تاريخ التخرج يسبق بداية الدراسة.']);
            }
        }

        if (collect($data['qualifications'] ?? [])->filter(fn ($row) => (bool) ($row['is_highest_qualification'] ?? false))->count() > 1) {
            throw ValidationException::withMessages(['qualifications' => 'يمكن اختيار مؤهل أعلى واحد فقط.']);
        }
    }

    /**
     * Create the employee and related records atomically. The activation SMS is sent only after the
     * response, so a slow or unavailable SMS gateway never delays the screen or loses the new profile.
     */
    public function store(Request $request, EmployeeAccountService $accounts, EmployeeActivationService $activation, EmployeeAccess $access, EmployeeFileChanges $changes): RedirectResponse
    {
        $this->authorizeFiles($request);

        abort_unless(Schema::hasColumn('users', 'must_change_password'), 503, 'حقل التحقق الأمني غير جاهز.');

        $request->merge($this->cleanBlankChildren($request));
        $choice = $this->activationChoice($request);
        $data = $request->validate($this->rules(), $this->messages(), ['national_id' => 'رقم الوثيقة']);
        if (isset($data['addresses'])) {
            $data['addresses'] = app(GazaAddress::class)->employeeRows($data['addresses']);
        }
        $this->checkNewProfile($data);

        // HR staff: the new file waits for the HR head's approval (approved HR requirements §7.1); nothing is created yet.
        if ($access->canPrepare($request->user())) {
            $this->accountForNewProfile($data, false);
            $id = $changes->submitCreate($request->user(), $data, $request->integer('file_change_id') ?: null, $request->ip());

            return redirect()->to(EmployeeFileChanges::url($id))
                ->with('success', 'أُرسل الملف إلى رئيس الموارد البشرية للاعتماد. يُنشأ الملف وحساب الدخول بعد اعتماده.');
        }

        $created = $this->createProfile($request, $data, $choice, (int) $request->user()->id, [], $accounts, $activation);

        if ($created['linked_existing']) {
            return redirect()->route('admin.employee-profiles.show', $created['employee']->id)
                ->with('success', 'تم استكمال ملف الموظف وربطه بحسابه الحالي دون تعديل حالة الحساب أو صلاحياته أو إرسال SMS.');
        }
        if ($choice['direct']) {
            return redirect()->route('admin.employee-profiles.show', $created['employee']->id)
                ->with('success', 'تم إنشاء ملف الموظف وحساب دخول مفعّل دون SMS. سلّم الموظف كلمة المرور المؤقتة شخصيًا؛ سيُطلب منه تغييرها عند أول دخول.');
        }

        $message = 'تم إنشاء ملف الموظف وحساب دخول بانتظار التفعيل.';
        if (config('services.hotsms.enabled', false)) {
            $this->sendActivationAfterResponse($activation, $created['user']);
            $message .= ' سيُرسل رمز التفعيل إلى الجوال الأساسي خلال لحظات، وتظهر حالة الإرسال في ملف الموظف.';
        } else {
            $message .= ' خدمة SMS معطّلة؛ أرسل رمز التفعيل من ملف الموظف بعد تفعيلها.';
        }

        return redirect()->route('admin.employee-profiles.show', $created['employee']->id)->with('success', $message);
    }

    /**
     * The checks a new file passes before anything is written, whether it is saved now or submitted for approval.
     *
     * @param  array<string, mixed>  $data  validated form data
     */
    private function checkNewProfile(array $data): void
    {
        $this->verifyReferences($data);
        $this->validateFinalOnboarding($data);

        // An archived employee number must not be reused.
        if (Employee::withTrashed()->where('employee_number', $data['employee_number'])->exists()) {
            throw ValidationException::withMessages(['employee_number' => 'الرقم الوظيفي مستخدم في ملف حالي أو مؤرشف.']);
        }

        foreach (['addresses', 'qualifications', 'family_members', 'leave_opening_balances'] as $collection) {
            foreach ($data[$collection] ?? [] as $index => $row) {
                if (isset($row['id'])) {
                    throw ValidationException::withMessages(["{$collection}.{$index}.id" => 'لا يمكن ربط سجل قديم بملف موظف جديد.']);
                }
            }
        }

        $this->verifyRows($data);
    }

    /**
     * The existing login account a new file links to (or null when a new account is created), after its checks.
     * Locked when the file is written, to serialize concurrent linking or creation for one account.
     *
     * @param  array<string, mixed>  $data
     */
    private function accountForNewProfile(array $data, bool $lock): ?User
    {
        $existingUser = User::query()->where('employee_number', $data['employee_number'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $link = (bool) ($data['link_existing_account'] ?? false);

        if (! $existingUser) {
            if ($link) {
                throw ValidationException::withMessages([
                    'employee_number' => 'لا يوجد حساب مطابق للربط. ألغِ خيار الربط لإنشاء حساب جديد.',
                ]);
            }

            return null;
        }

        if (! $link) {
            throw ValidationException::withMessages([
                'employee_number' => 'هذا الرقم مرتبط بحساب مستخدم موجود. راجع الاسم وأكّد ربط الملف بالحساب الحالي.',
            ]);
        }

        // Matching name is an extra guard, not proof of identity. Accounts often hold a short name
        // («هاني دبابش») while the profile needs all four parts, so the first and family names are compared.
        if (! $this->accountNameMatches((string) $existingUser->name, (string) ($data['first_name_ar'] ?? ''), (string) ($data['family_name_ar'] ?? ''))) {
            throw ValidationException::withMessages([
                'employee_number' => "اسم الحساب «{$existingUser->name}» لا يطابق الاسم الأول واسم العائلة في الملف. راجع الهوية، أو صحّح اسم الحساب من «حسابات الموظفين والأدوار».",
            ]);
        }

        if (Employee::withTrashed()->where('user_id', $existingUser->id)->exists()) {
            throw ValidationException::withMessages(['employee_number' => 'الحساب مرتبط بملف موظف مسبقًا أو بملف مؤرشف.']);
        }

        if ((int) ($data['department_id'] ?? 0) !== (int) $existingUser->department_id) {
            throw ValidationException::withMessages([
                'department_id' => 'يجب أن يطابق قسم الملف قسم الحساب المرتبط. عدّل تكليف الحساب أولًا من إدارة الموظفين.',
            ]);
        }

        return $existingUser;
    }

    /**
     * Creates the employee and related records atomically. $actorId is who entered the file (created_by/updated_by):
     * the HR head or administrator themselves, or the HR staff member whose prepared file is being approved.
     *
     * @param  array<string, mixed>  $data
     * @param  array{direct: bool, password: ?string}  $choice
     * @param  array<string, mixed>  $auditExtra
     * @return array{employee: Employee, user: User, linked_existing: bool}
     */
    private function createProfile(Request $request, array $data, array $choice, int $actorId, array $auditExtra, EmployeeAccountService $accounts, EmployeeActivationService $activation): array
    {
        return DB::transaction(function () use ($request, $accounts, $activation, $data, $choice, $actorId, $auditExtra): array {
            $basic = Arr::except($data, ['addresses', 'qualifications', 'family_members', 'leave_opening_balances', 'confirm_no_opening_balances', 'link_existing_account', 'name_parts_reviewed']);
            $existingUser = $this->accountForNewProfile($data, true);

            if ($existingUser) {
                // Existing account data (password, role assignments, department, enabled state) stays untouched.
                $profile = new Employee;
                $profile->fill($basic);
                $profile->user_id = $existingUser->id;
                $profile->created_by = $actorId;
                $profile->updated_by = $actorId;
                $profile->save();

                $result = ['employee' => $profile, 'user' => $existingUser, 'linked_existing' => true];
            } else {
                $result = $accounts->create($basic);
                $result['linked_existing'] = false;
            }

            $employee = $result['employee'];
            $this->saveSubmittedRelations($employee, $data, $actorId);
            $this->saveOpeningBalances($employee, $data['leave_opening_balances'] ?? [], $actorId);
            app(EmployeePlacements::class)->record($employee->id, $employee->only(['department_id', 'job_title_id', 'job_grade_id']),
                ($employee->work_start_date ?? $employee->hire_date ?? now())->format('Y-m-d'), 'profile', 'إنشاء ملف الموظف', $actorId);

            $employee->onboarding_status = $result['linked_existing'] ? 'completed' : 'pending_activation';
            $employee->onboarding_step = 5;
            $employee->onboarding_completed_at = now();
            $employee->created_by = $actorId;
            $employee->updated_by = $actorId;
            $employee->save();

            $this->audit($request, 'employee_profile.created', $employee->id, null, $basic,
                array_values(array_intersect(['addresses', 'qualifications', 'family_members', 'leave_opening_balances'], array_keys($data))), $auditExtra);

            if ($choice['direct'] && ! $result['linked_existing']) {
                $activation->activateByAdministrator($request->user(), $result['user'], (string) $choice['password'], $request->ip());
            }

            return $result;
        });
    }

    /**
     * The account name starts with the profile's first name and ends with its family name. Spelling
     * variants that are common between two typists (أ/إ/آ/ا, ى/ي, ة/ه, tatweel, extra spaces) are ignored.
     */
    private function accountNameMatches(string $accountName, string $firstName, string $familyName): bool
    {
        return EmployeeNames::accountMatches($accountName, $firstName, $familyName);
    }

    /**
     * Never include an OTP or provider response in flash, session or audit data. A failed attempt
     * stays visible on the profile (no "sent" time) and can be repeated from there.
     */
    private function sendActivationAfterResponse(EmployeeActivationService $activation, User $user): void
    {
        app()->terminating(function () use ($activation, $user): void {
            try {
                $activation->sendCode($user);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * HR sends (or re-sends) the activation code of an account still waiting for activation.
     */
    public function sendActivationCode(Request $request, int $employee, EmployeeActivationService $activation): RedirectResponse
    {
        $this->authorizeManagement($request);
        $record = $this->employeeOr404($employee);
        $user = $record->user_id ? User::query()->find($record->user_id) : null;

        if (! $user || $user->is_active) {
            throw ValidationException::withMessages(['account' => 'لا يوجد حساب بانتظار التفعيل لهذا الموظف.']);
        }
        if (! config('services.hotsms.enabled', false)) {
            throw ValidationException::withMessages(['account' => 'خدمة SMS معطّلة حاليًا في إعدادات النظام.']);
        }

        try {
            $activation->sendCode($user);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['account' => 'تعذر تأكيد إرسال الرسالة. إذا وصل الرمز إلى الموظف فليستخدمه، وإلا أعد المحاولة بعد دقيقة.']);
        }

        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id,
            'action' => 'employee_profile.activation_code_sent',
            'subject_type' => 'employees',
            'subject_id' => $record->id,
            'metadata' => json_encode(['user_id' => $user->id], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'أُرسل طلب رمز التفعيل إلى الجوال الأساسي للموظف. الرمز صالح 5 دقائق.');
    }

    /**
     * Second activation option, without SMS: the system administrator sets a temporary password that the employee
     * must change at the first sign-in.
     */
    public function activateWithoutSms(Request $request, int $employee, EmployeeActivationService $activation): RedirectResponse
    {
        abort_unless($request->user()->is_active && $request->user()->isSystemAdmin(), 403, 'التفعيل دون رمز SMS لمدير النظام فقط.');
        $request->validate([
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
            'confirm' => ['required', 'accepted'],
        ], ['confirm.accepted' => 'أكّد مراجعة هوية الموظف وتسليمه كلمة المرور شخصيًا.'], ['password' => 'كلمة المرور المؤقتة']);
        $record = $this->employeeOr404($employee);
        $user = $record->user_id ? User::query()->find($record->user_id) : null;
        if (! $user || $user->is_active) {
            throw ValidationException::withMessages(['account' => 'لا يوجد حساب بانتظار التفعيل لهذا الموظف.']);
        }

        $activation->activateByAdministrator($request->user(), $user, (string) $request->input('password'), $request->ip());

        return back()->with('success', 'فُعّل الحساب دون SMS. سلّم الموظف كلمة المرور المؤقتة شخصيًا؛ سيُطلب منه تغييرها عند أول دخول.');
    }

    private function saveSubmittedRelations(Employee $record, array $data, int $actor): void
    {
        if (array_key_exists('addresses', $data)) {
            $this->saveRelated($record, 'addresses', $data['addresses'], [
                'address_type', 'governorate', 'city', 'area', 'neighborhood',
                'governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'address_other',
                'address_details', 'notes', 'valid_from', 'valid_to', 'is_active',
            ], $actor, 'addresses');
        }

        if (array_key_exists('qualifications', $data)) {
            $this->saveRelated($record, 'qualifications', $data['qualifications'], [
                'qualification_name', 'degree_level', 'institution_name', 'specialization',
                'issuing_country_code', 'study_start_date', 'graduation_date',
                'graduation_year', 'grade', 'gpa', 'gpa_scale', 'certificate_number',
                'notes', 'is_highest_qualification',
            ], $actor, 'qualifications');
        }

        if (array_key_exists('family_members', $data)) {
            $this->saveRelated($record, 'familyMembers', $data['family_members'], [
                'relationship_code', 'full_name_ar', 'full_name_en', 'national_id',
                'date_of_birth', 'phone', 'notes', 'is_dependent', 'is_active',
            ], $actor, 'family_members');
        }
    }

    private function employeePayload(Employee $employee): array
    {
        $employee->load(['addresses', 'qualifications', 'familyMembers']);

        return array_merge($employee->toArray(), [
            // Explicit API key used by the editable Vue form.
            'family_members' => $employee->familyMembers->toArray(),
        ]);
    }

    /**
     * The employee file: HR management, the executive director, the head of the employee's department and the
     * employee see it, each with only the sections EmployeeAccess allows.
     */
    public function show(Request $request, int $employee, EmployeeAccess $access, EmployeeProfileView $view): Response
    {
        $record = $this->employeeOr404($employee);
        abort_unless($request->user() && $access->canView($request->user(), $record), 403, 'لا تملك صلاحية الاطلاع على هذا الملف.');

        return Inertia::render('Admin/EmployeeProfiles/Show', [...$view->build($request->user(), $record), ...$this->accountPanel($request, $record, $access),
            // A change HR staff prepared on this file and the HR head has not decided yet.
            'fileChange' => app(EmployeeFileChanges::class)->banner($request->user(), (int) $record->id)]);
    }

    /**
     * Login account state and the HR actions on it (link an existing account or create one, then activate by SMS).
     *
     * @return array<string, mixed>
     */
    private function accountPanel(Request $request, Employee $record, EmployeeAccess $access): array
    {
        if (! $access->canManage($request->user())) {
            return ['account' => null, 'activation' => null, 'accountCandidate' => null, 'directCreate' => false];
        }
        $account = $record->user_id ? DB::table('users')->where('id', $record->user_id)->first(['id', 'employee_number', 'is_active', 'must_change_password']) : null;
        $lastCode = $account && ! $account->is_active
            ? DB::table('employee_activation_codes')->where('user_id', $account->id)->orderByDesc('id')->first(['created_at', 'sent_at', 'expires_at', 'consumed_at'])
            : null;
        $candidate = ! $account ? DB::table('users as u')->leftJoin('employees as e', 'e.user_id', '=', 'u.id')
            ->where('u.employee_number', $record->employee_number)->first(['u.id', 'u.name', 'u.is_active', 'e.id as profile_id']) : null;

        return [
            'account' => $account ? ['employee_number' => $account->employee_number, 'is_active' => (bool) $account->is_active, 'must_change_password' => (bool) $account->must_change_password] : null,
            'activation' => $account && ! $account->is_active ? [
                'smsEnabled' => (bool) config('services.hotsms.enabled', false),
                'directAllowed' => $request->user()->isSystemAdmin(),
                'lastRequestedAt' => $lastCode?->created_at, 'lastSentAt' => $lastCode?->sent_at, 'expiresAt' => $lastCode?->expires_at,
            ] : null,
            'accountCandidate' => $candidate ? ['name' => $candidate->name, 'is_active' => (bool) $candidate->is_active, 'linkable' => $candidate->profile_id === null,
                'name_matches' => EmployeeNames::accountMatches((string) $candidate->name, (string) $record->first_name_ar, (string) $record->family_name_ar)] : null,
            'directCreate' => ! $account && $request->user()->isSystemAdmin(),
        ];
    }

    /**
     * Links the profile to the existing login account with the same employee number, after HR confirms the identity.
     * The account's password, roles and state are untouched; its department must match the profile.
     */
    public function linkAccount(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeManagement($request);
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.accepted' => 'أكّد مراجعة هوية صاحب الحساب قبل الربط.']);

        DB::transaction(function () use ($request, $employee): void {
            $record = Employee::query()->lockForUpdate()->findOrFail($employee);
            if ($record->user_id !== null) {
                throw ValidationException::withMessages(['account' => 'الملف مرتبط بحساب دخول مسبقًا.']);
            }
            $user = User::query()->where('employee_number', $record->employee_number)->lockForUpdate()->first();
            if (! $user) {
                throw ValidationException::withMessages(['account' => 'لا يوجد حساب دخول بالرقم الوظيفي نفسه.']);
            }
            if (Employee::withTrashed()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['account' => 'الحساب مرتبط بملف موظف آخر أو بملف مؤرشف.']);
            }
            if (! EmployeeNames::accountMatches((string) $user->name, (string) $record->first_name_ar, (string) $record->family_name_ar)) {
                throw ValidationException::withMessages(['account' => "اسم الحساب «{$user->name}» لا يطابق الاسم الأول واسم العائلة في الملف. راجع الهوية أو صحّح الاسم."]);
            }
            if ($record->department_id !== null && (int) $record->department_id !== (int) $user->department_id) {
                throw ValidationException::withMessages(['account' => 'قسم الملف يختلف عن قسم الحساب. وحّدهما أولًا من «حسابات الموظفين والأدوار» أو من ملف الموظف.']);
            }
            $before = $record->only(['user_id', 'department_id', 'onboarding_status']);
            $record->forceFill([
                'user_id' => $user->id, 'department_id' => $record->department_id ?? $user->department_id,
                'onboarding_status' => $user->is_active ? 'completed' : 'pending_activation', 'onboarding_step' => 5,
                'onboarding_completed_at' => now(), 'updated_by' => $request->user()->id,
            ])->save();
            $this->audit($request, 'employee_profile.account_linked', $record->id, $before, $record->only(['user_id', 'department_id', 'onboarding_status']));
        });

        return back()->with('success', 'رُبط الملف بحساب الدخول القائم دون تعديل كلمة المرور أو الصلاحيات أو حالة الحساب.');
    }

    /**
     * Creates the login account of a profile that has none (e.g. an imported one). The account starts inactive with
     * an undisclosed random secret; the employee activates it with the SMS code. Never part of an import.
     */
    public function createAccount(Request $request, int $employee, EmployeeAccountService $accounts, EmployeeActivationService $activation): RedirectResponse
    {
        $this->authorizeManagement($request);
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.accepted' => 'أكّد إنشاء حساب الدخول.']);
        $choice = $this->activationChoice($request);
        $user = DB::transaction(function () use ($request, $employee, $accounts, $activation, $choice): User {
            $record = Employee::query()->lockForUpdate()->findOrFail($employee);
            $user = $accounts->createForProfile($record);
            $this->audit($request, 'employee_profile.account_created', $record->id, ['user_id' => null], ['user_id' => $user->id, 'onboarding_status' => 'pending_activation']);
            if ($choice['direct']) {
                $activation->activateByAdministrator($request->user(), $user, (string) $choice['password'], $request->ip());
            }

            return $user;
        });
        if ($choice['direct']) {
            return back()->with('success', 'أُنشئ حساب دخول مفعّل دون SMS. سلّم الموظف كلمة المرور المؤقتة شخصيًا؛ سيُطلب منه تغييرها عند أول دخول.');
        }
        if (config('services.hotsms.enabled', false)) {
            $this->sendActivationAfterResponse($activation, $user);

            return back()->with('success', 'أُنشئ حساب دخول بانتظار التفعيل، وسيصل رمز التفعيل إلى الجوال الأساسي خلال لحظات.');
        }

        return back()->with('success', 'أُنشئ حساب دخول بانتظار التفعيل. خدمة SMS معطّلة؛ أرسل الرمز من هذا الملف بعد تفعيلها.');
    }

    public function edit(Request $request, int $employee, EmployeeFileChanges $changes): Response
    {
        $this->authorizeFiles($request);
        $record = $this->employeeOr404($employee);
        $payload = $this->employeePayload($record);
        // HR staff continue their own open change: the form shows it, so a later step does not send old values back.
        $open = $changes->openFor((int) $record->id);
        if ($open !== null && (int) $open->prepared_by === (int) $request->user()->id) {
            $payload = $changes->overlay($payload, $open);
        }

        return Inertia::render('Admin/EmployeeProfiles/Form', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'employee' => $payload,
            'leaveOnboarding' => $this->leaveOnboardingContext($record->id),
            'lookups' => $this->lookups($request, $record),
            'fileChange' => $changes->banner($request->user(), (int) $record->id),
        ]);
    }

    /** HR staff reopen a new file they prepared (returned to them, or still waiting) to correct and resend it. */
    public function editChange(Request $request, int $change, EmployeeAccess $access): Response
    {
        abort_unless($request->user() && $access->canPrepare($request->user()) && EmployeeFileChanges::available(), 403);
        $row = DB::table('employee_file_changes')->where('id', $change)->first();
        abort_unless($row && $row->kind === 'create' && (int) $row->prepared_by === (int) $request->user()->id
            && in_array($row->status, EmployeeFileChanges::OPEN, true), 404);

        return Inertia::render('Admin/EmployeeProfiles/Form', [
            'username' => $request->user()->name,
            'navigation' => $this->navigation(),
            'employee' => null,
            'prefill' => null,
            'draft' => json_decode((string) $row->payload, true) ?: [],
            'fileChange' => ['id' => (int) $row->id, 'status' => $row->status, 'note' => $row->review_note, 'own' => true],
            'leaveOnboarding' => $this->leaveOnboardingContext(),
            'lookups' => $this->lookups($request),
        ]);
    }

    /**
     * «تعديلات ملفات الموظفين»: what HR staff prepared. The HR head approves or returns it; the administrator reads it;
     * HR staff follow, correct or withdraw their own.
     */
    public function changes(Request $request, EmployeeFileChanges $changes, EmployeeAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user && $access->canOpenFiles($user), 403, 'ملفات الموظفين متاحة لإدارة الموارد البشرية وموظفيها.');
        abort_unless(EmployeeFileChanges::available(), 503, 'مراجعة ملفات الموظفين غير مفعلة بعد.');
        $scope = $request->query('scope') === 'all' ? 'all' : 'open';
        $selected = $request->integer('change');

        return Inertia::render('Admin/EmployeeProfiles/Changes', [
            'rows' => $changes->rows($user, $scope),
            'scope' => $scope,
            'selected' => $selected > 0 ? $changes->detail($user, $selected) : null,
            'can' => ['review' => $access->canReviewChanges($user), 'prepare' => $access->canPrepare($user), 'manage' => $access->canManage($user)],
        ]);
    }

    /**
     * The HR head approves a prepared file or change: it is checked again against the file as it is now and written
     * exactly like the head's own save, with the preparer as author and the approval recorded.
     */
    public function approveChange(Request $request, int $change, EmployeeFileChanges $changes, EmployeeAccountService $accounts, EmployeeActivationService $activation): RedirectResponse
    {
        $revision = (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $created = null;
        $changes->approve($request->user(), $change, $revision, function (object $item, array $payload, int $preparedBy) use ($request, $accounts, $activation, &$created): int {
            $extra = ['file_change_id' => (int) $item->id, 'prepared_by' => $preparedBy];
            if ($item->kind === 'create') {
                $data = Validator::make($payload, $this->rules(), $this->messages(), ['national_id' => 'رقم الوثيقة'])->validate();
                $this->checkNewProfile($data);
                $created = $this->createProfile($request, $data, ['direct' => false, 'password' => null], $preparedBy, $extra, $accounts, $activation);

                return (int) $created['employee']->id;
            }
            $record = Employee::query()->findOrFail($item->employee_id);
            $this->revalidateUpdate($record, $payload);
            $this->applyUpdate($request, (int) $record->id, $payload, $preparedBy, $extra);

            return (int) $record->id;
        }, $request->ip());

        $message = 'اعتُمد التعديل وثُبّت في ملف الموظف، وسُجل من أعده ومن اعتمده.';
        if ($created !== null) {
            if ($created['linked_existing']) {
                $message = 'اعتُمد الملف الجديد ورُبط بحساب الموظف القائم دون تعديل كلمة مروره أو صلاحياته.';
            } elseif (config('services.hotsms.enabled', false)) {
                $this->sendActivationAfterResponse($activation, $created['user']);
                $message = 'اعتُمد الملف الجديد وأُنشئ له حساب دخول بانتظار التفعيل؛ سيصل رمز التفعيل إلى الجوال الأساسي خلال لحظات.';
            } else {
                $message = 'اعتُمد الملف الجديد وأُنشئ له حساب دخول بانتظار التفعيل. خدمة SMS معطّلة؛ أرسل الرمز من ملف الموظف بعد تفعيلها.';
            }
        }

        return redirect()->to(EmployeeFileChanges::url($change))->with('success', $message);
    }

    public function returnChange(Request $request, int $change, EmployeeFileChanges $changes): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ], [], ['note' => 'الملاحظة']);
        $changes->returnToPreparer($request->user(), $change, (int) $data['revision'], (string) $data['note'], $request->ip());

        return redirect()->to(EmployeeFileChanges::url($change))->with('success', 'أُعيد التعديل إلى مُعِدّه مع ملاحظتك.');
    }

    public function withdrawChange(Request $request, int $change, EmployeeFileChanges $changes): RedirectResponse
    {
        $changes->withdraw($request->user(), $change, $request->ip());

        return redirect()->to(EmployeeFileChanges::url())->with('success', 'سُحب التعديل؛ لم يُغيَّر شيء في ملف الموظف.');
    }

    /**
     * The profile columns the form edits (rule keys of the file itself, not of its row collections).
     *
     * @return list<string>
     */
    private function basicKeys(Employee $record): array
    {
        return array_values(array_filter(array_keys($this->rules($record)), fn (string $key): bool => ! str_contains($key, '.')
            && ! in_array($key, ['addresses', 'qualifications', 'family_members', 'leave_opening_balances', 'confirm_no_opening_balances'], true)));
    }

    /**
     * At approval, a prepared change is validated again as the whole file it produces (the file as it is now with
     * the change on top), so a department, title or constant deactivated meanwhile is refused.
     *
     * @param  array<string, mixed>  $payload
     */
    private function revalidateUpdate(Employee $record, array $payload): void
    {
        $collections = array_keys(EmployeeFileChanges::COLLECTIONS);
        $merged = [];
        foreach ($this->basicKeys($record) as $key) {
            if ($key !== 'name_parts_reviewed') {
                $value = $record->getAttribute($key);
                $merged[$key] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
        }
        $merged = [...$merged, ...Arr::except($payload, $collections), ...Arr::only($payload, $collections)];
        $data = Validator::make($merged, $this->rules($record), $this->messages(), ['national_id' => 'رقم الوثيقة'])->validate();
        $this->verifyReferences($data, $record);
    }

    /**
     * The employee number and department of a file with a login account change on «حسابات الموظفين والأدوار» only.
     *
     * @param  array<string, mixed>  $basic
     */
    private function verifyAccountFields(Employee $record, array $basic): void
    {
        if ($record->user_id === null) {
            return;
        }
        $account = User::query()->findOrFail($record->user_id);
        foreach (['employee_number', 'department_id'] as $field) {
            if (array_key_exists($field, $basic) && (string) $basic[$field] !== (string) $account->{$field}) {
                throw ValidationException::withMessages([
                    $field => 'عدّل الرقم الوظيفي والقسم من «حسابات الموظفين والأدوار» لضمان تطابق الحساب والملف.',
                ]);
            }
        }
    }

    /**
     * Update or add dependent rows; never delete rows by omission.
     * Every supplied ID must already belong to the employee.
     */
    private function saveRelated(Employee $employee, string $relationship, array $rows, array $fields, int $actorId, string $inputKey): void
    {
        foreach ($rows as $index => $row) {
            $relation = $employee->{$relationship}();
            $id = $row['id'] ?? null;

            if ($id !== null) {
                $existing = $relation->whereKey($id)->first();
                if (! $existing) {
                    throw ValidationException::withMessages(["{$inputKey}.{$index}.id" => 'السجل غير موجود ضمن ملف هذا الموظف.']);
                }
            } else {
                $existing = $relation->make();
                $existing->created_by = $actorId;
            }

            $existing->fill(Arr::only($row, $fields));
            $existing->updated_by = $actorId;
            $relation->save($existing);
        }
    }

    private function verifyRelated(Employee $employee, array $data): void
    {
        $relations = [
            'addresses' => 'addresses',
            'qualifications' => 'qualifications',
            'family_members' => 'familyMembers',
        ];

        foreach ($relations as $key => $relationName) {
            foreach ($data[$key] ?? [] as $index => $row) {
                if (isset($row['id']) && ! $employee->{$relationName}()->whereKey($row['id'])->exists()) {
                    throw ValidationException::withMessages(["{$key}.{$index}.id" => 'لا يجوز تعديل سجل تابع لموظف آخر.']);
                }
            }
        }

        $activeTypes = [];
        foreach ($data['addresses'] ?? [] as $index => $row) {
            if (! empty($row['valid_from']) && ! empty($row['valid_to']) && $row['valid_to'] < $row['valid_from']) {
                throw ValidationException::withMessages(["addresses.{$index}.valid_to" => 'تاريخ نهاية السكن يسبق تاريخ بدايته.']);
            }

            if (in_array($row['address_type'], ['original', 'current'], true) && (bool) ($row['is_active'] ?? true)) {
                if (isset($activeTypes[$row['address_type']])) {
                    throw ValidationException::withMessages(["addresses.{$index}.address_type" => 'لا يمكن إرسال عنوانين نشطين من النوع نفسه.']);
                }
                $activeTypes[$row['address_type']] = true;

                // The DB also enforces this rule under concurrency.
                $conflict = $employee->addresses()
                    ->where('address_type', $row['address_type'])
                    ->where('is_active', true)
                    ->when(isset($row['id']), fn ($q) => $q->whereKeyNot($row['id']))
                    ->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(["addresses.{$index}.address_type" => 'يوجد عنوان نشط من النوع نفسه؛ عطّل القديم أولًا.']);
                }
            }
        }

        foreach ($data['qualifications'] ?? [] as $index => $row) {
            if (! empty($row['study_start_date']) && ! empty($row['graduation_date']) && $row['graduation_date'] < $row['study_start_date']) {
                throw ValidationException::withMessages(["qualifications.{$index}.graduation_date" => 'تاريخ التخرج يسبق بداية الدراسة.']);
            }
        }

        if (collect($data['qualifications'] ?? [])->filter(fn ($r) => (bool) ($r['is_highest_qualification'] ?? false))->count() > 1) {
            throw ValidationException::withMessages(['qualifications' => 'يمكن تحديد مؤهل أعلى واحد فقط.']);
        }

        // "No qualifications" / "no family members" cannot stand next to recorded rows.
        foreach (['qualifications' => ['has_qualifications', 'qualifications', 'لا يمكن تأكيد عدم وجود مؤهلات مع وجود مؤهلات مسجلة.'],
            'family_members' => ['has_family_members', 'familyMembers', 'لا يمكن تأكيد عدم وجود أفراد عائلة مع وجود سجلات نشطة لهم.']] as $key => [$flag, $relationName, $message]) {
            if (! array_key_exists($flag, $data) || $data[$flag] === null || (bool) $data[$flag]) {
                continue;
            }
            $rows = collect($data[$key] ?? []);
            $counts = fn (array $row): bool => $key !== 'family_members' || (bool) ($row['is_active'] ?? true);
            $untouched = $employee->{$relationName}()
                ->whereNotIn('id', $rows->pluck('id')->filter()->map(fn ($id) => (int) $id)->all())
                ->when($key === 'family_members', fn ($q) => $q->where('is_active', true))
                ->count();
            if ($untouched + $rows->filter($counts)->count() > 0) {
                throw ValidationException::withMessages([$flag => $message]);
            }
        }
    }

    public function update(Request $request, int $employee, EmployeeAccess $access, EmployeeFileChanges $changes): RedirectResponse
    {
        $this->authorizeFiles($request);
        $record = $this->employeeOr404($employee);

        $request->merge($this->cleanBlankChildren($request));
        $data = $request->validate($this->rules($record), $this->messages(), ['national_id' => 'رقم الوثيقة']);
        if (isset($data['addresses'])) {
            $data['addresses'] = app(GazaAddress::class)->employeeRows($data['addresses'], $record->addresses()->get()->keyBy('id')->toArray());
        }
        $this->verifyReferences($data, $record);

        // HR staff: the change waits for the HR head's approval; the file is untouched until then.
        if ($access->canPrepare($request->user())) {
            $this->verifyRelated($record, $data);
            $this->verifyAccountFields($record, Arr::only($data, $this->basicKeys($record)));
            $changes->submitUpdate($request->user(), $record, $data, $this->basicKeys($record), $request->ip());

            return redirect()->route('admin.employee-profiles.show', $employee)
                ->with('success', 'أُرسلت البيانات إلى رئيس الموارد البشرية للاعتماد؛ تظهر في الملف بعد اعتمادها.');
        }

        $this->applyUpdate($request, $employee, $data, (int) $request->user()->id, []);

        return redirect()->route('admin.employee-profiles.show', $employee)
            ->with('success', 'تم حفظ بيانات الموظف والسجلات التابعة المرسلة دون تعديل الحساب أو الصلاحيات.');
    }

    /**
     * Writes a change to the file. $actorId is who entered it (updated_by): the HR head or administrator themselves,
     * or the HR staff member whose prepared change is being approved (the approval is in $auditExtra and on the change).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $auditExtra
     */
    private function applyUpdate(Request $request, int $employee, array $data, int $actorId, array $auditExtra): void
    {
        // Lock the employee during the multi-table operation; audit and dependent
        // data are committed together. Never modify user credentials or roles here.
        DB::transaction(function () use ($request, $employee, $data, $actorId, $auditExtra) {
            $record = Employee::query()->lockForUpdate()->findOrFail($employee);
            $this->verifyRelated($record, $data);

            $basic = Arr::only($data, $this->basicKeys($record));
            // HR confirms the suggested split of an imported name; until then the source full name is the reference.
            if (array_key_exists('name_parts_reviewed', $basic)) {
                if ($basic['name_parts_reviewed']) {
                    $basic['name_review_status'] = 'reviewed';
                }
                unset($basic['name_parts_reviewed']);
            }

            // Recording a qualification or a family member answers the "is there any?" question.
            if (! empty($data['qualifications'])) {
                $basic['has_qualifications'] = true;
            }
            if (collect($data['family_members'] ?? [])->contains(fn ($row) => (bool) ($row['is_active'] ?? true))) {
                $basic['has_family_members'] = true;
            }

            $this->verifyAccountFields($record, $basic);

            $before = $record->only(array_keys($basic));
            $moved = collect(['department_id', 'job_title_id', 'job_grade_id'])
                ->contains(fn (string $key): bool => array_key_exists($key, $basic) && (string) ($before[$key] ?? '') !== (string) ($basic[$key] ?? ''));
            $record->fill($basic);
            $record->updated_by = $actorId;
            $record->save();

            $actor = $actorId;
            if ($moved) {
                // A transfer closes the previous placement; the history is never overwritten.
                app(EmployeePlacements::class)->record($record->id, $record->only(['department_id', 'job_title_id', 'job_grade_id', 'department_source', 'job_title_source', 'grade_source']),
                    now()->toDateString(), 'profile', 'تعديل القسم أو المسمى أو الدرجة من ملف الموظف', $actor);
            }

            if (array_key_exists('addresses', $data)) {
                $this->saveRelated($record, 'addresses', $data['addresses'], [
                    'address_type', 'governorate', 'city', 'area', 'neighborhood',
                    'governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'address_other',
                    'address_details', 'notes', 'valid_from', 'valid_to', 'is_active',
                ], $actor, 'addresses');
            }

            if (array_key_exists('qualifications', $data)) {
                // Demote first to respect the PostgreSQL unique index for highest degree.
                $high = collect($data['qualifications'])->first(fn ($r) => (bool) ($r['is_highest_qualification'] ?? false));
                if ($high !== null) {
                    $record->qualifications()->where('is_highest_qualification', true)
                        ->update(['is_highest_qualification' => false, 'updated_by' => $actor, 'updated_at' => now()]);
                }

                $this->saveRelated($record, 'qualifications', $data['qualifications'], [
                    'qualification_name', 'degree_level', 'institution_name', 'specialization',
                    'issuing_country_code', 'study_start_date', 'graduation_date',
                    'graduation_year', 'grade', 'gpa', 'gpa_scale', 'certificate_number',
                    'notes', 'is_highest_qualification',
                ], $actor, 'qualifications');
            }

            if (array_key_exists('family_members', $data)) {
                $this->saveRelated($record, 'familyMembers', $data['family_members'], [
                    'relationship_code', 'full_name_ar', 'full_name_en', 'national_id',
                    'date_of_birth', 'phone', 'notes', 'is_dependent', 'is_active',
                ], $actor, 'family_members');
            }

            $touched = array_values(array_intersect(['addresses', 'qualifications', 'family_members'], array_keys($data)));
            $this->audit($request, 'employee_profile.updated', $record->id, $before, $basic, $touched, $auditExtra);
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeFiles($request);

        return response()->json($this->lookups($request));
    }
}
