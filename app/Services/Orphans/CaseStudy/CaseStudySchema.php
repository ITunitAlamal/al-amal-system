<?php

namespace App\Services\Orphans\CaseStudy;

/**
 * The social case study questionnaire («دراسة الحالة»), from sheet «الاستمارة» of «تسجيل الايتام.xlsx» (rows 28–97).
 *
 * Versioned: a saved study keeps the version it was filled with, and a change of questions is a new version here
 * (the old one stays for reading). Lists come from «ثوابت النظام» by type code. Nothing is required while the study is
 * a draft. 'required' applies only when the study is submitted, and a condition (same form as RegistrationFieldSpec)
 * limits it. 'other' => true adds an explanation «<key>_other», required on submission when «أخرى» is chosen.
 *
 * Question types: single / multi (list), choice (fixed options), text, textarea, money (ILS amount), number, date,
 * repeat (rows of columns; a 'person' column picks a member of the household or takes a written name).
 */
final class CaseStudySchema
{
    public const CURRENT = 1;

    public const YNU = ['yes' => 'نعم', 'no' => 'لا', 'unknown' => 'غير معروف'];

    private const SOMETIMES = ['yes' => 'نعم', 'no' => 'لا', 'sometimes' => 'أحيانًا', 'unknown' => 'غير معروف'];

    private const INTERMITTENT = ['yes' => 'نعم', 'no' => 'لا', 'intermittent' => 'بشكل متقطع', 'unknown' => 'غير معروف'];

    public static function version(int $version): array
    {
        return match ($version) {
            1 => self::v1(),
            default => throw new \InvalidArgumentException('Unknown case study version '.$version),
        };
    }

    /** Every list type the version refers to (for the page options). @return list<string> */
    public static function listTypes(int $version): array
    {
        $types = [];
        $walk = function (array $questions) use (&$walk, &$types): void {
            foreach ($questions as $q) {
                if (isset($q['list'])) {
                    $types[] = $q['list'];
                }
                if (isset($q['columns'])) {
                    $walk($q['columns']);
                }
            }
        };
        foreach (self::version($version) as $section) {
            $walk($section['questions']);
        }

        return array_values(array_unique($types));
    }

    private static function v1(): array
    {
        $yes = fn (string $key): array => ['eq', $key, 'yes'];
        $ynu = fn (string $key, string $label, array $extra = []): array => ['key' => $key, 'label' => $label, 'type' => 'choice', 'options' => self::YNU, ...$extra];
        $person = ['key' => 'person', 'label' => 'الاسم', 'type' => 'person'];

        return [
            'psych' => ['title' => 'الجانب النفسي', 'sensitive' => true, 'questions' => [
                ['key' => 'general_state', 'label' => 'الحالة النفسية العامة للأطفال', 'type' => 'single', 'list' => 'psych_general_states'],
                ['key' => 'worries', 'label' => 'أكثر ما يقلق الأطفال أو يسبب لهم الحزن', 'type' => 'multi', 'list' => 'child_worries', 'other' => true],
                ['key' => 'loss_coping', 'label' => 'كيف يتعامل الأطفال مع الفقد؟', 'type' => 'single', 'list' => 'loss_coping'],
                $ynu('has_symptoms', 'هل يعاني أحد الأيتام من أعراض نفسية أو تغيرات سلوكية بعد الفقد؟'),
                ['key' => 'children', 'label' => 'الأعراض والتغيرات لكل طفل', 'type' => 'repeat', 'show_if' => $yes('psych.has_symptoms'), 'required' => $yes('psych.has_symptoms'), 'columns' => [
                    $person,
                    ['key' => 'symptoms', 'label' => 'الأعراض النفسية والجسدية', 'type' => 'multi', 'list' => 'psych_symptoms'],
                    ['key' => 'behavior', 'label' => 'التغيرات السلوكية', 'type' => 'multi', 'list' => 'behavioral_changes'],
                    ['key' => 'had_sessions', 'label' => 'تلقى جلسات دعم', 'type' => 'choice', 'options' => self::YNU],
                    ['key' => 'provider', 'label' => 'الجهة المقدمة', 'type' => 'text'],
                    ['key' => 'medication', 'label' => 'يأخذ علاجًا نفسيًا', 'type' => 'choice', 'options' => self::YNU],
                    ['key' => 'needs_specialist', 'label' => 'بحاجة لمختص', 'type' => 'choice', 'options' => self::YNU],
                ]],
                $ynu('wants_sessions', 'هل ترغب الأسرة بتلقي جلسات دعم نفسي أو إرشادي؟'),
                ['key' => 'no_sessions_reason', 'label' => 'السبب', 'type' => 'text', 'show_if' => ['eq', 'psych.wants_sessions', 'no']],
                ['key' => 'needs', 'label' => 'أهم الاحتياجات النفسية للأطفال', 'type' => 'textarea'],
            ]],
            'social' => ['title' => 'الجانب الاجتماعي', 'sensitive' => false, 'questions' => [
                ['key' => 'relations', 'label' => 'طبيعة العلاقات بين أفراد الأسرة', 'type' => 'single', 'list' => 'family_relations'],
                ['key' => 'daily_caregiver', 'label' => 'المسؤول عن رعاية الأيتام بشكل يومي', 'type' => 'single', 'list' => 'daily_caregivers', 'other' => true],
                ['key' => 'integration', 'label' => 'مدى اندماج الأسرة في المجتمع المحلي', 'type' => 'single', 'list' => 'community_integration'],
                $ynu('conflicts', 'هل توجد صراعات داخل الأسرة؟'),
                ['key' => 'conflict_types', 'label' => 'نوع هذه الصراعات', 'type' => 'multi', 'list' => 'conflict_types', 'other' => true, 'show_if' => $yes('social.conflicts')],
                $ynu('stigma', 'هل هناك وصمة أو نظرة سلبية من المجتمع نحو الأسرة؟'),
                ['key' => 'stigma_note', 'label' => 'وضّح الموقف', 'type' => 'text', 'show_if' => $yes('social.stigma')],
                $ynu('support', 'هل للأسرة علاقات اجتماعية خارجية داعمة؟'),
                ['key' => 'supporters', 'label' => 'الداعم', 'type' => 'multi', 'list' => 'supporter_types', 'other' => true, 'show_if' => $yes('social.support')],
                ['key' => 'no_support_reason', 'label' => 'السبب', 'type' => 'text', 'show_if' => ['eq', 'social.support', 'no']],
                $ynu('services', 'هل تلقت الأسرة خدمات اجتماعية من جمعيات ومؤسسات؟'),
                ['key' => 'service_types', 'label' => 'نوع الخدمات', 'type' => 'multi', 'list' => 'received_service_types', 'other' => true, 'show_if' => $yes('social.services')],
                $ynu('activities', 'هل يشارك الأطفال في أنشطة مجتمعية؟'),
                ['key' => 'activity_types', 'label' => 'الأنشطة', 'type' => 'multi', 'list' => 'child_activities', 'show_if' => $yes('social.activities')],
                $ynu('peers', 'هل يتفاعل الأطفال مع أقرانهم؟'),
                $ynu('feels_different', 'هل يشعر الأطفال بأنهم مختلفون عن أقرانهم بسبب اليتم؟'),
                ['key' => 'feels_different_note', 'label' => 'وضّح كيف', 'type' => 'text', 'show_if' => $yes('social.feels_different')],
                $ynu('wants_training', 'هل ترغب الأسرة في المشاركة في برامج تدريبية أو تنموية؟'),
                ['key' => 'training_fields', 'label' => 'المجال', 'type' => 'multi', 'list' => 'training_interest_fields', 'other' => true, 'show_if' => $yes('social.wants_training')],
                ['key' => 'challenges', 'label' => 'أبرز التحديات الاجتماعية التي تواجهها الأسرة', 'type' => 'textarea'],
            ]],
            'health' => ['title' => 'الجانب الصحي', 'sensitive' => true, 'questions' => [
                ['key' => 'children_health', 'label' => 'الوضع الصحي العام للأطفال', 'type' => 'single', 'list' => 'health_ratings'],
                ['key' => 'vaccination', 'label' => 'هل يتلقى الأطفال التطعيمات بشكل دوري؟', 'type' => 'choice', 'options' => self::INTERMITTENT],
                ['key' => 'healthy_food', 'label' => 'هل يتوفر غذاء صحي وكافٍ للأطفال؟', 'type' => 'choice', 'options' => self::SOMETIMES],
                $ynu('care_access', 'هل تحصل الأسرة على خدمات الرعاية الصحية عند الحاجة؟'),
                ['key' => 'medicine_difficulty', 'label' => 'هل يوجد صعوبة في توفير الأدوية والعلاج؟', 'type' => 'choice', 'options' => self::SOMETIMES],
                $ynu('chronic', 'هل أحد أفراد الأسرة يعاني من مرض مزمن؟'),
                ['key' => 'chronic_rows', 'label' => 'الأمراض المزمنة', 'type' => 'repeat', 'show_if' => $yes('health.chronic'), 'required' => $yes('health.chronic'), 'columns' => [
                    $person, ['key' => 'disease', 'label' => 'المرض', 'type' => 'single', 'list' => 'chronic_diseases', 'other' => true],
                    ['key' => 'state', 'label' => 'حالة المريض', 'type' => 'single', 'list' => 'illness_states'],
                    ['key' => 'treatment', 'label' => 'درجة توفر العلاج', 'type' => 'single', 'list' => 'treatment_availability'],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ]],
                $ynu('disability', 'هل يعاني أحد أفراد الأسرة من إعاقة؟'),
                ['key' => 'disability_rows', 'label' => 'الإعاقات', 'type' => 'repeat', 'show_if' => $yes('health.disability'), 'required' => $yes('health.disability'), 'columns' => [
                    $person, ['key' => 'type', 'label' => 'نوع الإعاقة', 'type' => 'single', 'list' => 'disability_types'],
                    ['key' => 'aids', 'label' => 'الأدوات الصحية', 'type' => 'multi', 'list' => 'health_aids', 'other' => true],
                    ['key' => 'treatment', 'label' => 'درجة توفر العلاج', 'type' => 'single', 'list' => 'treatment_availability'],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ]],
                $ynu('war_injury', 'هل تعرض أحد أفراد الأسرة لإصابة بسبب الحرب؟'),
                ['key' => 'injury_rows', 'label' => 'الإصابات', 'type' => 'repeat', 'show_if' => $yes('health.war_injury'), 'required' => $yes('health.war_injury'), 'columns' => [
                    $person, ['key' => 'injury', 'label' => 'نوع الإصابة', 'type' => 'text'],
                    ['key' => 'severity', 'label' => 'درجة الإصابة', 'type' => 'single', 'list' => 'injury_severities', 'other' => true],
                    ['key' => 'follow_up', 'label' => 'نوع المتابعة الصحية', 'type' => 'single', 'list' => 'health_follow_up_types', 'other' => true],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ]],
                $ynu('hereditary', 'هل توجد أمراض وراثية في الأسرة؟'),
                ['key' => 'hereditary_disease', 'label' => 'حدد المرض', 'type' => 'text', 'show_if' => $yes('health.hereditary')],
                ['key' => 'needs', 'label' => 'أهم الاحتياجات الصحية التي ترغب الأسرة بتوفيرها', 'type' => 'textarea'],
            ]],
            'economic' => ['title' => 'الجانب الاقتصادي', 'sensitive' => false, 'questions' => [
                ['key' => 'income_sources', 'label' => 'مصادر دخل الأسرة', 'type' => 'multi', 'list' => 'income_sources', 'other' => true],
                ['key' => 'income_status', 'label' => 'الدخل الشهري', 'type' => 'choice', 'options' => ['known' => 'مبلغ معروف', 'none' => 'لا يوجد دخل', 'unknown' => 'غير معروف']],
                ['key' => 'monthly_income', 'label' => 'معدل الدخل الشهري (₪)', 'type' => 'money', 'show_if' => ['eq', 'economic.income_status', 'known'], 'required' => ['eq', 'economic.income_status', 'known']],
                ['key' => 'adequacy', 'label' => 'هل يكفي الدخل لتغطية الاحتياجات الأساسية؟', 'type' => 'choice', 'options' => ['yes' => 'نعم', 'partial' => 'جزئيًا', 'no' => 'لا', 'unknown' => 'غير معروف']],
                ['key' => 'expense_burdens', 'label' => 'أبرز النفقات التي تشكل عبئًا على الأسرة', 'type' => 'multi', 'list' => 'expense_burdens', 'other' => true],
                ['key' => 'urgent_needs', 'label' => 'الاحتياجات الاقتصادية الملحة', 'type' => 'textarea'],
                $ynu('food_aid', 'هل تمتلك الأسرة بطاقة تموينية أو مساعدات غذائية دورية؟'),
                $ynu('debts', 'هل على الأسرة ديون أو التزامات مالية؟'),
                ['key' => 'debt_amount', 'label' => 'قيمتها التقريبية (₪)', 'type' => 'money', 'show_if' => $yes('economic.debts')],
                $ynu('assets', 'هل تمتلك الأسرة أملاكًا أو أصولًا إنتاجية أو مشاريع صغيرة؟'),
                ['key' => 'asset_types', 'label' => 'أذكرها', 'type' => 'multi', 'list' => 'productive_assets', 'other' => true, 'show_if' => $yes('economic.assets')],
                ['key' => 'assets_note', 'label' => 'ملاحظات', 'type' => 'text', 'show_if' => $yes('economic.assets')],
                $ynu('vocational', 'هل سبق أن تلقت الأسرة تدريبًا مهنيًا؟'),
                ['key' => 'vocational_field', 'label' => 'في أي مجال؟', 'type' => 'single', 'list' => 'vocational_fields', 'other' => true, 'show_if' => $yes('economic.vocational')],
                ['key' => 'vocational_place', 'label' => 'مكان التدريب', 'type' => 'text', 'show_if' => $yes('economic.vocational')],
                $ynu('earners', 'هل يوجد من يعمل في الأسرة؟'),
                ['key' => 'earner_rows', 'label' => 'العاملون في الأسرة', 'type' => 'repeat', 'show_if' => $yes('economic.earners'), 'required' => $yes('economic.earners'), 'columns' => [
                    $person, ['key' => 'age', 'label' => 'العمر', 'type' => 'number'],
                    ['key' => 'work', 'label' => 'طبيعة العمل', 'type' => 'single', 'list' => 'employment_statuses'],
                    ['key' => 'hours', 'label' => 'ساعات العمل', 'type' => 'number'],
                    ['key' => 'income', 'label' => 'متوسط الدخل (₪)', 'type' => 'money'],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ]],
                $ynu('receives_aid', 'هل تتلقى الأسرة مساعدات من مؤسسات أو جمعيات؟'),
                ['key' => 'aid_rows', 'label' => 'المساعدات (إفادة الأسرة، لا تُعد صرفًا مؤكدًا)', 'type' => 'repeat', 'show_if' => $yes('economic.receives_aid'), 'required' => $yes('economic.receives_aid'), 'columns' => [
                    ['key' => 'type', 'label' => 'نوع المساعدة', 'type' => 'single', 'list' => 'assistance_types'],
                    ['key' => 'amount', 'label' => 'القيمة (₪)', 'type' => 'money'],
                    ['key' => 'provider', 'label' => 'الجهة المقدمة', 'type' => 'text'],
                    ['key' => 'frequency', 'label' => 'معدل التكرار', 'type' => 'single', 'list' => 'sponsorship_frequencies'],
                    ['key' => 'regularity', 'label' => 'الانتظام', 'type' => 'choice', 'options' => ['regular' => 'منتظم', 'irregular' => 'متقطع', 'one_off' => 'لمرة واحدة', 'unknown' => 'غير معروف']],
                ]],
            ]],
            'education' => ['title' => 'الجانب التعليمي', 'sensitive' => false, 'questions' => [
                ['key' => 'enrolled', 'label' => 'هل الأطفال ملتحقون بالعملية التعليمية؟', 'type' => 'choice', 'options' => self::INTERMITTENT],
                ['key' => 'nonenrollment_reasons', 'label' => 'أسباب عدم الالتحاق', 'type' => 'multi', 'list' => 'nonenrollment_reasons', 'other' => true,
                    'show_if' => ['in', 'education.enrolled', ['no', 'intermittent']]],
                ['key' => 'children', 'label' => 'الأطفال (لكل طفل وضعه)', 'type' => 'repeat', 'columns' => [
                    $person, ['key' => 'grade', 'label' => 'الصف الدراسي', 'type' => 'single', 'list' => 'school_grades'],
                    ['key' => 'achievement', 'label' => 'المستوى التحصيلي', 'type' => 'single', 'list' => 'achievement_levels'],
                    ['key' => 'enrollment', 'label' => 'ملتحق بالتعليم', 'type' => 'single', 'list' => 'enrollment_statuses'],
                    ['key' => 'provider', 'label' => 'الجهة التعليمية', 'type' => 'single', 'list' => 'institution_types', 'other' => true],
                    ['key' => 'difficulties', 'label' => 'صعوبات التعلم', 'type' => 'multi', 'list' => 'learning_difficulties', 'other' => true],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'text'],
                ]],
                $ynu('has_needs', 'هل هناك احتياجات تعليمية للأيتام؟'),
                ['key' => 'needs', 'label' => 'أهم هذه الاحتياجات', 'type' => 'multi', 'list' => 'educational_needs', 'other' => true, 'show_if' => $yes('education.has_needs')],
                ['key' => 'challenges', 'label' => 'أبرز التحديات التعليمية التي تواجه الأسرة', 'type' => 'textarea'],
            ]],
            'assessment' => ['title' => 'التقييم العام والتوصيات', 'sensitive' => false, 'questions' => [
                ['key' => 'overall', 'label' => 'الوضع العام للأسرة', 'type' => 'textarea', 'required' => true],
                ['key' => 'needs', 'label' => 'أبرز احتياجات الأسرة الحالية', 'type' => 'multi', 'list' => 'household_needs', 'other' => true],
                ['key' => 'challenges', 'label' => 'التحديات الأكبر التي تواجه الأسرة حاليًا', 'type' => 'textarea'],
                ['key' => 'suggestions', 'label' => 'مقترحات لدعم الأيتام وأسرهم', 'type' => 'textarea'],
                ['key' => 'recommendation', 'label' => 'التوصيات النهائية', 'type' => 'textarea', 'required' => true],
            ]],
        ];
    }
}
