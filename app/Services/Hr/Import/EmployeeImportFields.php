<?php

namespace App\Services\Hr\Import;

/**
 * The staff workbook's columns (owner's «بيانات شاملة» sheet, 33 columns) and where each one lands in AIMS.
 *
 * Every column maps to a typed field; none is dropped. «م» is the source row serial: it is kept as the row pointer
 * of the import, not as an employee identifier. The optional internal personnel number is not in the workbook but
 * is accepted from the official template.
 */
final class EmployeeImportFields
{
    /**
     * key => [Arabic header, English header, type, group, target (shown to HR)]
     *
     * Groups decide who may see a value in the preview: pay and bank values need their confidential permission.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const FIELDS = [
        'source_serial' => ['م', 'Row No.', 'serial', 'source', 'مؤشر صف المصدر في الدفعة (ليس معرّفًا للموظف)'],
        'attendance_number' => ['الرقم الوظيفي ( الجهاز البصمة )', 'Employee / biometric device No.', 'code', 'identity', 'ملف الموظف: رقم جهاز البصمة، ويُعتمد رقمًا وظيفيًا للملفات الجديدة عند اختيار ذلك'],
        'employee_number' => ['الرقم الوظيفي الداخلي', 'Internal personnel No.', 'code', 'identity', 'ملف الموظف: الرقم الوظيفي (اختياري؛ ليس في الملف الحالي)'],
        'full_name' => ['اسم الموظف', 'Employee full name', 'text', 'personal', 'الاسم الكامل كما ورد، وتقسيم مقترح للأسماء يحتاج مراجعة'],
        'original_family_name' => ['عائلة الموظف/ة الأساسية', 'Original family name', 'text', 'personal', 'اسم العائلة الأصلي'],
        'national_id' => ['رقم الهوية', 'National ID No.', 'national_id', 'identity', 'رقم الهوية الفلسطينية (نص من 9 أرقام)'],
        'gender' => ['الجنس', 'Gender', 'gender', 'personal', 'الجنس'],
        'date_of_birth' => ['تاريخ الميلاد', 'Date of birth', 'date', 'personal', 'تاريخ الميلاد'],
        'marital_status' => ['الحالة الاجتماعية', 'Marital status', 'reference', 'personal', 'الحالة الاجتماعية (ثوابت النظام)'],
        'children_count' => ['عدد الابناء الكلي', 'Total number of children', 'int', 'personal', 'عدد الأبناء'],
        'mobile' => ['هاتف / جوال', 'Primary mobile', 'mobile', 'contact', 'الجوال الأساسي (صيغة دولية 970)'],
        'qualification' => ['المؤهل العلمي', 'Highest qualification', 'reference', 'qualification', 'المؤهل الأعلى ومستواه التعليمي (سجل مؤهلات غير موثّق)'],
        'specialization' => ['التخصص', 'Specialization', 'reference', 'qualification', 'تخصص المؤهل (نص المصدر، وربط اختياري بالتخصصات)'],
        'job_title' => ['المسمى الوظيفي', 'Job title', 'reference', 'employment', 'المسمى الوظيفي (نص المصدر، وربط بدليل المسميات)'],
        'employment_term' => ['بند التوظيف', 'Employment basis', 'reference', 'employment', 'بند التوظيف (ثوابت النظام)'],
        'hire_date' => ['تاريخ التعيين', 'Hire date', 'date', 'employment', 'تاريخ التعيين وبداية سجل التنقلات'],
        'department' => ['القسم', 'Department / unit', 'reference', 'employment', 'القسم في الهيكل (ربط بقسم قائم فقط) ونص الوحدة كما ورد'],
        'grade' => ['درجة الموظف', 'Grade', 'reference', 'employment', 'الدرجة كما وردت، وربط اختياري بالسلم الوظيفي'],
        'base_salary' => ['الراتب الأساسي', 'Basic salary', 'money', 'pay', 'سجل الراتب المرجعي (غير معتمد للصرف)'],
        'spouse_allowance' => ['العلاوة الاجتماعية للزوجة', 'Spouse allowance', 'money', 'pay', 'سجل الراتب: علاوة الزوجة'],
        'children_allowance' => ['العلاوة الاجتماعية للأبناء المستحقة', 'Children allowance', 'money', 'pay', 'سجل الراتب: علاوة الأبناء'],
        'cost_of_living_rate' => ['نسبة علاوة غلاء المعيشة', 'Cost-of-living allowance rate (%)', 'percent', 'pay', 'سجل الراتب: نسبة غلاء المعيشة (بالمئة)'],
        'nature_of_work_rate' => ['نسبة علاوة طبيعة العمل', 'Nature-of-work allowance rate (%)', 'percent', 'pay', 'سجل الراتب: نسبة طبيعة العمل (بالمئة)'],
        'total_salary' => ['جملة الراتب', 'Total salary (as stated)', 'money', 'pay', 'سجل الراتب: الجملة كما وردت (لا يعاد حسابها)'],
        'bank' => ['البنك', 'Bank', 'reference', 'bank', 'الحساب البنكي: البنك (ثوابت النظام)'],
        'bank_account' => ['الحساب البنكي', 'Bank account No.', 'account', 'bank', 'الحساب البنكي: الرقم (مشفّر)'],
        'leave_annual' => ['اجمالي الاجازات السنوية', 'Annual leave entitlement (days)', 'days', 'leave', 'لقطة رصيد الإجازات بتاريخ الأساس'],
        'leave_carried' => ['اجمالي الاجازات المرحلة', 'Carried-over leave (days)', 'days', 'leave', 'لقطة رصيد الإجازات: المرحّل'],
        'leave_accrued' => ['اجمالي الاجازات المستحقة حتى تاريخة', 'Accrued leave to date (days)', 'days', 'leave', 'لقطة رصيد الإجازات: المستحق'],
        'leave_used' => ['اجمالي  عدد الاجازات المستنفذة حتى تاريخه', 'Used leave to date (days)', 'days', 'leave', 'لقطة رصيد الإجازات: المستنفد'],
        'leave_balance' => ['رصيد الاجازات حتى تاريخه', 'Leave balance to date (days)', 'days', 'leave', 'لقطة رصيد الإجازات: الرصيد'],
        'landline' => ['رقم الهاتف', 'Landline', 'landline', 'contact', 'الهاتف الأرضي'],
        'secondary_mobile' => ['رقم جوال ثانوي', 'Secondary mobile', 'mobile', 'contact', 'الجوال الاحتياطي (صيغة دولية 970)'],
        'address' => ['عنوان السكن كاملا', 'Full residence address', 'text', 'address', 'عنوان السكن الكامل كما ورد'],
    ];

    /**
     * Reference columns: where their values are looked up.
     *
     * @var array<string, array{source: string, type?: string, create: bool, required_on_insert?: bool}>
     */
    public const REFERENCES = [
        'marital_status' => ['source' => 'master', 'type' => 'marital_statuses', 'create' => true],
        'qualification' => ['source' => 'master', 'type' => 'education_levels', 'create' => true],
        'specialization' => ['source' => 'master', 'type' => 'academic_disciplines', 'create' => true],
        'employment_term' => ['source' => 'master', 'type' => 'employment_terms', 'create' => true],
        'bank' => ['source' => 'master', 'type' => 'banks', 'create' => true],
        'job_title' => ['source' => 'job_titles', 'create' => false],
        'department' => ['source' => 'departments', 'create' => false],
        'grade' => ['source' => 'job_grades', 'create' => false],
    ];

    /** Columns whose values are confidential beyond HR management. */
    public const CONFIDENTIAL_GROUPS = ['pay', 'bank'];

    /** Plain words meaning "nothing" in the owner's sheets; they become empty, never data. */
    public const PLACEHOLDERS = ['لا يوجد', 'لايوجد', 'لا يوجد.', 'غير متوفر', 'غير متوفره', 'بدون', 'لا', '-', '--', '—', '_', 'n/a', 'na', 'none', 'null', '.', '0'];

    /**
     * Issue codes: level and the Arabic message. Messages never contain the value itself.
     * error = the row cannot be imported; field = this value is left empty; warning = kept, needs attention; info = a transformation.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ISSUES = [
        'no_key' => ['error', 'لا يوجد رقم هوية ولا رقم وظيفي؛ لا يمكن مطابقة الصف أو إنشاؤه.'],
        'name_missing' => ['error', 'اسم الموظف فارغ؛ لا يُنشأ ملف دون اسم.'],
        'duplicate_national_id' => ['error', 'رقم الهوية مكرر في أكثر من صف في الملف.'],
        'duplicate_attendance' => ['error', 'رقم جهاز البصمة مكرر في أكثر من صف في الملف.'],
        'duplicate_employee_number' => ['error', 'الرقم الوظيفي مكرر في أكثر من صف في الملف.'],
        'match_conflict' => ['error', 'رقم الهوية والرقم الوظيفي يطابقان ملفين مختلفين في النظام؛ يحتاج مراجعة يدوية.'],
        'archived_match' => ['error', 'الصف يطابق ملفًا مؤرشفًا؛ لا يُعاد إنشاؤه ولا يُحدَّث من الاستيراد.'],
        'number_taken_by_account' => ['error', 'الرقم الوظيفي (رقم البصمة) مستخدم لحساب دخول باسم شخص آخر. إن كان الحساب لهذا الموظف فصحّح اسم الحساب من «حسابات الموظفين والأدوار»، وإلا فصحّح الرقم في الملف أو أضف عمود «الرقم الوظيفي الداخلي»، ثم أعد التحقق.'],
        'employee_number_missing' => ['error', 'لا يوجد رقم وظيفي لإنشاء ملف جديد؛ فعّل اعتماد رقم البصمة رقمًا وظيفيًا أو أضف عمود الرقم الوظيفي.'],
        'national_id_invalid' => ['field', 'رقم الهوية ليس 9 أرقام؛ لم يُحفظ (إن خُزّن رقمًا في Excel فقد تكون الأصفار البادئة حُذفت).'],
        'national_id_checksum' => ['warning', 'رقم الهوية لا يجتاز خانة التحقق؛ راجعه مع الوثيقة.'],
        'code_invalid' => ['field', 'الرقم الوظيفي/رقم البصمة يحتوي رموزًا غير مقبولة؛ لم يُحفظ.'],
        'gender_unknown' => ['field', 'قيمة الجنس غير مفهومة؛ لم تُحفظ.'],
        'date_invalid' => ['field', 'التاريخ غير مقروء أو خارج النطاق المعقول؛ لم يُحفظ.'],
        'date_from_number' => ['warning', 'التاريخ مخزّن رقمًا عاديًا في Excel وفُسّر كتاريخ؛ تحقق منه.'],
        'age_under_minimum' => ['field', 'تاريخ الميلاد يجعل العمر أقل من 15 سنة؛ لم يُحفظ.'],
        'hire_before_adulthood' => ['warning', 'تاريخ التعيين يسبق بلوغ الموظف 15 سنة؛ راجع التاريخين.'],
        'hire_in_future' => ['warning', 'تاريخ التعيين في المستقبل.'],
        'int_invalid' => ['field', 'القيمة ليست عددًا صحيحًا معقولًا؛ لم تُحفظ.'],
        'phone_invalid' => ['field', 'رقم الهاتف غير صالح؛ لم يُحفظ.'],
        'leading_zero_restored' => ['info', 'أعيد الصفر البادئ للجوال الذي حذفه Excel، وحُوّل إلى الصيغة الدولية.'],
        'secondary_same_as_primary' => ['info', 'الجوال الثانوي مطابق للأساسي؛ لم يُكرَّر.'],
        'placeholder' => ['info', 'قيمة بمعنى «لا يوجد»؛ تُركت فارغة.'],
        'money_invalid' => ['field', 'المبلغ غير صالح أو سالب؛ لم يُحفظ.'],
        'money_precision' => ['warning', 'المبلغ بأكثر من منزلتين عشريتين؛ قُرّب إلى منزلتين.'],
        'zero_as_unknown' => ['info', 'قيمة صفرية في الراتب عوملت كغير معروفة حسب خيار الدفعة.'],
        'percent_ambiguous' => ['warning', 'نسبة بين 0 و1 (مثل 0.15) قد تعني 15% أو 0.15%؛ تُركت فارغة حتى تختار قاعدة التفسير.'],
        'percent_fraction_applied' => ['info', 'فُسّرت النسبة كسرًا عشريًا (×100) حسب القاعدة المعتمدة للدفعة.'],
        'percent_literal_applied' => ['info', 'فُسّرت النسبة بقيمتها كما هي (بالمئة) حسب القاعدة المعتمدة للدفعة.'],
        'percent_from_format' => ['info', 'الخلية منسقة نسبة مئوية في Excel؛ حُوّلت إلى قيمة بالمئة.'],
        'percent_invalid' => ['field', 'النسبة غير صالحة أو أكبر من 100؛ لم تُحفظ.'],
        'days_invalid' => ['field', 'عدد الأيام غير صالح؛ لم يُحفظ.'],
        'negative_balance' => ['warning', 'رصيد الإجازات سالب في المصدر؛ يُحفظ في اللقطة ولا يُسجل رصيدًا افتتاحيًا.'],
        'leave_inconsistent' => ['warning', 'الرصيد لا يساوي (المرحّل + المستحق − المستنفد)؛ تُحفظ القيم كما وردت مع علامة عدم اتساق.'],
        'account_invalid' => ['field', 'رقم الحساب البنكي قصير جدًا أو غير صالح؛ لم يُحفظ.'],
        'account_duplicate_in_file' => ['warning', 'رقم الحساب البنكي نفسه مسجل لأكثر من صف في الملف.'],
        'account_duplicate_existing' => ['warning', 'رقم الحساب البنكي مسجل لموظف آخر في النظام.'],
        'reference_unmapped' => ['warning', 'القيمة غير مربوطة بقيمة معتمدة بعد؛ يُحفظ نص المصدر ويبقى الحقل المعتمد فارغًا.'],
        'department_unmapped' => ['warning', 'القسم/الوحدة غير مربوط بقسم في الهيكل؛ يُحفظ النص ويبقى القسم فارغًا.'],
        'formula_value' => ['warning', 'القيمة ناتجة عن معادلة في Excel؛ يُستخدم آخر ناتج محفوظ وقد لا يكون محدّثًا.'],
        'total_below_base' => ['warning', 'جملة الراتب أقل من الراتب الأساسي؛ تحفظ كما وردت دون إعادة حساب.'],
        'pay_without_currency' => ['field', 'لم تُحدد عملة الرواتب في خيارات الدفعة؛ لا تُحفظ مبالغ الراتب.'],
        'baseline_without_date' => ['field', 'لم يُحدد تاريخ الأساس في خيارات الدفعة؛ لا تُحفظ الرواتب ولا لقطة الإجازات.'],
        'account_exists' => ['info', 'يوجد حساب دخول بالرقم نفسه والاسم المطابق؛ يُربط من ملف الموظف بعد الاستيراد.'],
        'name_parts_suggested' => ['info', 'تقسيم الاسم (الأول/الأب/الجد/العائلة) مقترح ويحتاج مراجعة في ملف الموظف.'],
        'matched_existing' => ['info', 'الصف يطابق ملفًا قائمًا؛ الإجراء الافتراضي «تخطي» ما لم تختر تحديث حقول محددة.'],
    ];

    public static function header(string $key): string
    {
        return self::FIELDS[$key][0];
    }

    public static function isConfidential(string $key): bool
    {
        return in_array(self::FIELDS[$key][3] ?? '', self::CONFIDENTIAL_GROUPS, true);
    }

    /** Arabic text normalised for comparison: no diacritics or tatweel, one form of alef, ya, ta marbuta and hamza seats. */
    public static function normalize(?string $text): string
    {
        $text = (string) $text;
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    /** Arabic letters and digits only (headers are compared this way, so spacing and brackets never matter). */
    public static function arabicKey(?string $text): string
    {
        return (string) preg_replace('/[^\p{Arabic}0-9]/u', '', preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{060C}\x{061B}\x{061F}]/u', '', self::normalize($text)) ?? '');
    }

    public static function latinKey(?string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $text));
    }

    /**
     * Matches a header to a field by its exact Arabic or English name (spacing, brackets and letter forms ignored).
     */
    public static function fieldForHeader(string $header): ?string
    {
        $arabic = self::arabicKey($header);
        $latin = self::latinKey($header);
        foreach (self::FIELDS as $key => [$ar, $en]) {
            if (($arabic !== '' && $arabic === self::arabicKey($ar)) || ($arabic === '' && $latin !== '' && $latin === self::latinKey($en))) {
                return $key;
            }
        }

        return null;
    }

    public static function isPlaceholder(string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized === '' || in_array($normalized, array_map(fn (string $word): string => self::normalize($word), self::PLACEHOLDERS), true);
    }

    /**
     * @return array{level: string, message: string}
     */
    public static function issue(string $code): array
    {
        [$level, $message] = self::ISSUES[$code] ?? ['warning', $code];

        return ['level' => $level, 'message' => $message];
    }
}
