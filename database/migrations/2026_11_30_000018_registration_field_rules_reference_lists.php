<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field rules and reference lists of orphan registration (owner prompt 2026-11-02, workbook «تسجيل الايتام.xlsx»:
 * sheet «الاستمارة» and its option sheet «البيانات»). Additive only.
 *
 * Reference lists live in «ثوابت النظام» (master_data_types/values). Only missing values are inserted. Existing
 * values receive the workbook wording as metadata.aliases, so an old spelling maps to one stable code instead of
 * becoming a second value. No value is renamed, deactivated or deleted. Every touched value records its source
 * column in metadata.xlsx.
 *
 * Registry columns:
 * - persons.id_missing_reason: why an identity number is absent (never a placeholder number).
 * - orphan_guardianships.court_status_value_id and kinship_note: court documentation status per child (a status,
 *   not a legal approval) and the explanation of «أخرى».
 * - registry_addresses.housing_type_value_id and dwelling_condition_value_id: original housing type and current
 *   dwelling condition.
 * - households.location_missing_reason and contact_unreachable_reason: a documented reason instead of an invented
 *   address or phone.
 * - education_records.grade_value_id and achievement_value_id.
 * - household_case_studies: the social case study («دراسة الحالة») as its own stage, separate from field visits
 *   (social_assessments). It holds a versioned questionnaire, and sensitive answers are encrypted apart.
 */
return new class extends Migration
{
    private const SOURCE = '2026_11_30_000018';

    /**
     * type code => [Arabic name or null for an existing type, workbook column(s), new values, aliases for existing values].
     * New value: code => [Arabic name, aliases]. Aliases: existing code => aliases.
     */
    private const LISTS = [
        // Existing lists: missing values plus the workbook wording as aliases.
        'death_causes' => [null, 'A', ['natural' => ['وفاة طبيعية', ['طبيعية']], 'illness' => ['مرض', ['مرض']], 'accident' => ['حادث', ['حادث']],
            'war' => ['شهيد حرب', ['شهيد حرب', 'شهيد']], 'other' => ['سبب آخر', ['غير ذلك', 'أخرى']]], []],
        'health_statuses' => [null, 'B, I', ['ill' => ['مريض', ['مريض']], 'war_injury' => ['مصاب حرب', ['مصاب حرب']], 'other' => ['أخرى', ['غير ذلك']]],
            ['no_known_issue' => ['مُعافَى', 'معافى', 'سليم'], 'disability' => ['ذو إعاقة', 'ذوي إعاقة']]],
        // «طفل» in the workbook's marital and education columns is not a status: those lists are shared with HR, so a
        // child's answer stays empty there and is kept in enrollment_statuses (روضة / دون سن الدراسة).
        'marital_statuses' => [null, 'C, H, S', ['separated' => ['منفصل/ة', ['منفصل', 'منفصلة']]],
            ['status_social' => ['أعزب', 'عزباء'], 'married_status' => ['متزوج', 'متزوجة'], 'widowed' => ['أرمل', 'أرملة'], 'divorced' => ['مطلق', 'مطلقة']]],
        'vital_statuses' => [null, 'S', ['missing' => ['مفقود', ['مفقود', 'مفقودة']], 'unknown' => ['غير محدد', ['غير معروف']]],
            ['deceased' => ['متوفي', 'متوفى', 'متوفية', 'متوفاة'], 'alive' => ['حي', 'على قيد الحياة']]],
        'education_levels' => [null, 'D, J', ['tawjihi' => ['ثانوية عامة', ['ثانوية عامة', 'توجيهي']], 'postgraduate' => ['دراسات عليا', ['دراسات عليا']]],
            ['illiterate' => ['أُمي', 'أمي'], 'primary' => ['ابتدائي'], 'preparatory' => ['اعدادي', 'إعدادي'], 'secondary' => ['ثانوي'], 'diploma' => ['دبلوم'], 'bachelor' => ['بكالوريوس']]],
        'employment_statuses' => [null, 'E, T', ['gov_employee' => ['موظف حكومة', ['موظف حكومة']], 'unrwa_employee' => ['موظف وكالة', ['موظف وكالة']],
            'tafrighat_2005' => ['تفريغات 2005', ['تفريغات 2005']], 'former_authority' => ['سلطة قديمة', ['سلطة قديمة']], 'gaza_government' => ['حكومة غزة', ['حكومة غزة']],
            'private_work' => ['عمل خاص', ['عمل خاص']], 'labourer' => ['عامل', ['عامل']], 'unemployment_program' => ['بطالات', ['بطالات', 'بطالة']],
            'not_working' => ['لا يعمل', ['لا يعمل']], 'housewife' => ['ربة منزل', ['ربة منزل']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'kinship_types' => [null, 'F, G, R', ['neighbor' => ['جار / جيران', ['جيران', 'جار']], 'nephew' => ['ابن الأخ أو الأخت', ['ابن أخ', 'ابن اخت', 'ابن أخت']],
            'cousin' => ['ابن العم أو الخال', ['ابن عم/ عمة', 'ابن خال/ خالة', 'ابن عم', 'ابن خال']], 'stepchild' => ['ابن الزوج/ة', ['ابن الزوج', 'ابن الزوجة']],
            'parent_in_law' => ['أم أو أب الزوج/ة', ['أم الزوج', 'أب الزوج']]],
            ['mother' => ['أم', 'الأم'], 'father' => ['أب', 'الأب'], 'brother' => ['أخ'], 'sister' => ['أخت'], 'grandfather' => ['جد'], 'grandmother' => ['جدة'],
                'paternal_uncle_aunt' => ['عم', 'عمة', 'العم/ العمة'], 'maternal_uncle_aunt' => ['خال', 'خالة', 'الخال/ الخالة'], 'other_relative' => ['أقارب'],
                'other' => ['غير ذلك'], 'child' => ['ابن', 'ابنة'], 'grandchild' => ['حفيد', 'حفيدة'], 'grandparent' => ['الجد/الجدة']]],
        'shelter_types' => [null, 'M', ['tent' => ['خيمة', ['خيمة']], 'shelter_room' => ['غرفة بمركز إيواء', ['غرفة بمركز إيواء']], 'apartment' => ['شقة', ['شقة']],
            'house' => ['بيت', ['بيت']], 'storefront' => ['حاصل (محل أو مخزن)', ['حاصل']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'building_conditions' => [null, 'N', ['partial_minor' => ['ضرر جزئي بسيط', ['جزئي بسيط']], 'partial_severe' => ['ضرر جزئي بليغ', ['جزئي بليغ']]],
            ['destroyed' => ['هدم كلي'], 'good' => ['سليم']]],
        'orphan_classifications' => [null, 'J (حالة اليُتم), Q', ['mother_deceased' => ['يتيم الأم', ['الأم']]],
            ['father_deceased' => ['الأب'], 'both_parents_deceased' => ['الأب والأم']]],
        'sponsorship_frequencies' => [null, 'U', ['emergency' => ['طارئ / عند الحاجة', ['طارئ']]], ['monthly' => ['شهري'], 'quarterly' => ['كل 3شهور', 'كل 3 شهور'], 'yearly' => ['سنوي']]],
        'assistance_types' => [null, 'AX', ['in_kind' => ['عينية', ['عينية']], 'cash' => ['نقدية', ['نقدية']], 'monthly_sponsorship' => ['كفالة شهرية', ['كفالة شهرية']],
            'other' => ['أخرى', ['غير ذلك']]], []],
        'income_sources' => [null, 'AR', ['orphan_sponsorship' => ['كفالات الأيتام', ['كفالات الايتام', 'كفالات الأيتام']], 'unemployment_program' => ['بطالات', ['بطالات']]],
            ['wages' => ['عمل أحد الافراد', 'عمل أحد الأفراد'], 'assistance' => ['مساعدات إنسانية'], 'pension' => ['راتب تقاعد'], 'social_affairs' => ['شؤون اجتماعية'],
                'martyrs_wounded' => ['راتب جريح', 'راتب شهيد']]],
        'income_bands' => [null, 'AS', ['lt_500' => ['أقل من 500 ₪', ['500']], 'b500_1000' => ['من 500 إلى 1000 ₪', ['1000-500']], 'b1000_1500' => ['من 1000 إلى 1500 ₪', ['1500-1000']],
            'gt_1500' => ['أكثر من 1500 ₪', ['1500 أكثر من']]], []],
        'enrollment_statuses' => [null, 'BA, J', ['intermittent' => ['ملتحق بشكل متقطع', ['بشكل متقطع']]],
            ['enrolled' => ['نعم', 'ملتحق'], 'not_enrolled' => ['لا', 'غير ملتحق'], 'kindergarten' => ['تمهيدي', 'روضة'], 'not_school_age' => ['طفل']]],
        'institution_types' => [null, 'BC', ['government_school' => ['مدارس حكومية', ['مدارس حكومية']], 'unrwa_school' => ['مدارس وكالة الغوث', ['مدارس وكالة']],
            'educational_project' => ['مشاريع تعليمية', ['مشاريع تعليمية']], 'online_follow_up' => ['متابعة إلكترونية', ['متابعة الكترونية']],
            'private_school' => ['مدارس خاصة', ['مدارس خاصة']], 'individual_initiative' => ['مبادرات فردية', ['مبادرات فردية']], 'university' => ['جامعة أو كلية', []],
            'other' => ['أخرى', ['غير ذلك']]], []],
        'disability_types' => [null, 'AP', ['physical' => ['حركية', ['حركية']]], ['hearing' => ['سمعية'], 'visual' => ['بصرية'], 'intellectual' => ['ذهنية']]],

        // New lists (group «الأيتام والأسر»).
        'original_housing_types' => ['نوع السكن الأصلي', 'L', ['house_owned' => ['بيت ملك', ['بيت ملك']], 'shared_with_family' => ['مشترك مع عائلة', ['مشترك مع عائلة']],
            'house_rented' => ['بيت إيجار', ['بيت إيجار']], 'apartment_owned' => ['شقة ملك', ['شقة ملك']], 'apartment_rented' => ['شقة إيجار', ['شقة إيجار']], 'other' => ['أخرى', []]], []],
        'dwelling_conditions' => ['صفة السكن الحالي', 'O', ['habitable' => ['صالح للسكن', ['صالح للسكن']], 'crowded_unhealthy' => ['مزدحم وغير صحي', ['مزدحم وغير صحي']],
            'demolition_risk' => ['مهدد بالهدم', ['مهدد بالهدم']], 'needs_repair' => ['بحاجة لترميم', ['بحاجة لترميم']], 'dilapidated' => ['متهالك', ['متهالك']], 'crowded' => ['مزدحم', ['مزدحم']]], []],
        'guardianship_court_statuses' => ['توثيق الوصاية في المحكمة', 'AZ', ['yes' => ['نعم، موثقة', ['نعم']], 'no' => ['لا', ['لا']], 'in_process' => ['قيد الإخراج', ['قيد الإخراج']],
            'unknown' => ['غير معروف', []]], []],
        'school_grades' => ['الصف الدراسي', 'BH', ['bustan' => ['بستان', ['بستان']], 'kg' => ['تمهيدي', ['تمهيدي']], 'g1' => ['الأول', ['الأول']], 'g2' => ['الثاني', ['الثاني']],
            'g3' => ['الثالث', ['الثالث']], 'g4' => ['الرابع', ['الرابع']], 'g5' => ['الخامس', ['الخامس']], 'g6' => ['السادس', ['السادس']], 'g7' => ['السابع', ['السابع']],
            'g8' => ['الثامن', ['الثامن']], 'g9' => ['التاسع', ['التاسع']], 'g10' => ['العاشر', ['العاشر']], 'g11' => ['الحادي عشر', ['الحادي عشر']],
            'g12' => ['الثاني عشر', ['الثاني عشر']], 'university' => ['جامعي', ['جامعي']], 'unknown' => ['غير معروف', []]], []],
        'achievement_levels' => ['مستوى التحصيل الدراسي', 'BD', ['excellent' => ['ممتاز', ['ممتاز']], 'very_good' => ['جيد جدًا', ['جيد جداً', 'جيد جدا']], 'good' => ['جيد', ['جيد']],
            'average' => ['متوسط', ['متوسط']], 'weak' => ['ضعيف', ['ضعيف']], 'not_assessed' => ['لم يُقيَّم بعد', ['لم يقيم بعد']]], []],
        'nonenrollment_reasons' => ['أسباب عدم الالتحاق بالتعليم', 'BB', ['economic' => ['اقتصادية', ['اقتصادية']], 'work' => ['العمل', ['العمل']], 'no_desire' => ['ليس لديه رغبة', ['ليس لديه رغبة']],
            'security' => ['أسباب أمنية', ['أسباب أمنية']], 'low_achievement' => ['ضعف التحصيل', ['ضعف التحصيل']], 'psychological' => ['نفسية', ['نفسية']], 'health' => ['صحية', ['صحية']],
            'repeated_displacement' => ['نزوح متكرر', ['نزوح متكرر']], 'other' => ['أخرى', ['أخرى']]], []],
        'learning_difficulties' => ['صعوبات التعلم', 'BE', ['academic_delay' => ['تأخر دراسي', ['تأخر دراسي']], 'writing' => ['صعوبة في الكتابة', ['صعوبة في الكتابة']],
            'reading' => ['صعوبة في القراءة', ['صعوبة في القراءة']], 'attention' => ['ضعف التركيز', ['ضعف التركيز']], 'language_delay' => ['تأخر لغوي', ['تأخر لغوي']],
            'none' => ['لا يوجد', ['لا يوجد']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'educational_needs' => ['الاحتياجات التعليمية', 'BF', ['stationery' => ['قرطاسية', ['قرطاسية']], 'uniform' => ['زي مدرسي', ['زي مدرسي']], 'transport' => ['مواصلات', ['مواصلات']],
            'tutoring' => ['دروس تقوية', ['دروس تقوية']], 'devices' => ['أجهزة', ['أجهزة']], 'electricity' => ['كهرباء', ['كهرباء']], 'other' => ['أخرى', []]], []],
        'household_needs' => ['احتياجات الأسرة', 'BG', ['psychological_support' => ['دعم نفسي', ['دعم نفسي']], 'food' => ['غذاء', ['غذاء']], 'housing' => ['مسكن', ['مسكن']],
            'educational_support' => ['دعم تعليمي', ['دعم تعليمي']], 'physiotherapy' => ['علاج طبيعي', ['علاج طبيعي']], 'financial_aid' => ['مساعدات مالية', ['مساعدات مالية']],
            'health_supplies' => ['أدوات صحية', ['أدوات صحية']], 'housing_improvement' => ['تحسين المسكن', ['تحسين المسكن']], 'clothing' => ['ملابس', ['ملابس']],
            'bedding' => ['مراتب وأغطية', ['مراتب وأغطية']], 'medicines' => ['أدوية', ['أدوية']], 'other' => ['أخرى', ['أخرى']]], []],
        'injury_severities' => ['درجة الإصابة', 'BI', ['mild' => ['بسيطة', ['بسيطة']], 'moderate' => ['متوسطة', ['متوسطة']], 'severe' => ['صعبة', ['صعبة']],
            'needs_follow_up' => ['تحتاج متابعة', ['تحتاج متابعة']], 'needs_treatment' => ['تحتاج علاج', ['تحتاج علاج']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'health_follow_up_types' => ['نوع المتابعة الصحية', 'BJ', ['physiotherapy' => ['تحتاج علاج طبيعي', ['تحتاج علاج طبيعي']], 'cosmetic_surgery' => ['تحتاج عملية تجميل', ['تحتاج عملية تجميل']],
            'surgery' => ['تحتاج عمليات طبية', ['تحتاج عمليات طبية']], 'medicines_available' => ['أدوية متوفرة', ['أدوية متوفرة']],
            'medicines_hard' => ['أدوية صعبة التوفير', ['أدوية ولكن صعبة التوفير']], 'travel_abroad' => ['تحتاج سفر للخارج', ['تحتاج سفر للخارج']], 'other' => ['أخرى', []]], []],
        'psych_general_states' => ['الحالة النفسية العامة', 'V', ['good' => ['جيدة', ['جيدة']], 'average' => ['متوسطة', ['متوسطة']], 'poor' => ['سيئة', ['سيئة']], 'stable' => ['مستقرة', ['مستقرة']],
            'disturbed' => ['مضطربة', ['مضطربة']], 'unclear' => ['غير واضحة', ['غير واضحة']]], []],
        'behavioral_changes' => ['التغيرات السلوكية', 'X', ['sleep_disorders' => ['اضطرابات نوم', ['اضطرابات نوم']], 'nightmares' => ['كوابيس', ['كوابيس']],
            'frequent_crying' => ['بكاء متكرر', ['بكاء متكرر']], 'low_confidence' => ['انخفاض الثقة بالنفس', ['انخفاض الثقة بالنفس']], 'depression' => ['اكتئاب', ['اكتئاب']],
            'anxiety' => ['قلق', ['قلق']], 'irritability' => ['عصبية زائدة', ['عصبية زائدة']], 'bedwetting' => ['تبول لاإرادي', ['تبول لاإرادي']], 'mutism' => ['لا يتحدث', ['لا يتحدث']],
            'concentration' => ['مشاكل في التركيز', ['مشاكل في التركيز']], 'aggression' => ['عدوانية', ['عدوانية']], 'stubbornness' => ['عناد', ['عناد']],
            'nail_biting' => ['قضم الأظافر', ['قضم الأظافر']], 'clinginess' => ['التعلق الشديد', ['التعلق الشديد']], 'hair_pulling' => ['نتف شعر الرأس', ['نتف شعر الرأس']],
            'withdrawal' => ['الانطواء', ['الانطواء']], 'not_noticeable' => ['غير ملحوظ', ['غير ملحوظ']], 'stuttering' => ['التأتأة', ['التأتأة']],
            'hyperactivity' => ['الحركات الزائدة', ['الحركات الزائدة']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'psych_symptoms' => ['الأعراض النفسية والجسدية', 'AQ', ['stomach_pain' => ['آلام المعدة', ['آلام المعدة']], 'breathing' => ['ضيق في التنفس', ['ضيق في التنفس']],
            'hair_loss' => ['تساقط الشعر', ['تساقط الشعر']], 'digestive' => ['مشاكل الجهاز الهضمي', ['مشاكل الجهاز الهضمي']], 'skin' => ['مشاكل جلدية', ['مشاكل جلدية']],
            'headache' => ['صداع', ['صداع']], 'poor_concentration' => ['ضعف التركيز', ['ضعف التركيز']], 'sleep' => ['اضطرابات النوم', ['اضطرابات النوم']],
            'oversleeping' => ['النوم المفرط', ['النوم المفرط']], 'fatigue' => ['إرهاق دائم', ['ارهاق دائم']], 'palpitations' => ['تسارع ضربات القلب', ['تسارع ضربات القلب']],
            'muscle_tension' => ['توتر عضلي', ['توتر عضلي']], 'low_immunity' => ['ضعف المناعة', ['ضعف المناعة']], 'weight_loss' => ['فقدان الوزن', ['فقدان الوزن']],
            'menstrual' => ['اضطرابات الدورة الشهرية', ['اضطرابات الدورة الشهرية']], 'chest_pain' => ['ألم في الصدر', ['ألم في الصدر']],
            'nervous_tension' => ['توتر عصبي', ['توتر عصبي']], 'other' => ['أخرى', []]], []],
        'child_worries' => ['أكثر ما يقلق الأطفال', 'Y', ['future_fear' => ['الخوف من المستقبل', ['الخوف من المستقبل']], 'longing' => ['الحنين للمتوفى', ['الحنين للمتوفي']],
            'discrimination' => ['التمييز عن الآخرين', ['التمييز عن الآخرين']], 'expressing_feelings' => ['صعوبة التعبير عن المشاعر', ['صعوبة التعبير عن المشاعر']],
            'other' => ['أخرى', ['أخرى']]], []],
        'loss_coping' => ['التعامل مع الفقد', 'Z', ['accepted' => ['تقبلوا الأمر', ['تقبلوا الأمر']], 'persistent_grief' => ['حزن مستمر', ['حزن مستمر']],
            'behavioral_disorders' => ['اضطرابات سلوكية', ['اضطرابات سلوكية']], 'unclear' => ['غير واضح', ['غير واضح']]], []],
        'family_relations' => ['طبيعة العلاقات الأسرية', 'AA', ['stable' => ['مستقرة', ['مستقرة']], 'cooperative' => ['متعاونة', ['متعاونة']], 'tense' => ['متوترة', ['متوترة']],
            'ongoing_conflict' => ['نزاع مستمر', ['نزاع مستمر']], 'needs_support' => ['تحتاج دعم', ['تحتاج دعم']]], []],
        'daily_caregivers' => ['المسؤول عن الرعاية اليومية', 'AB', ['mother' => ['الأم', ['الأم']], 'sibling' => ['أخ أو أخت', ['أخ/أخت']], 'grandparent' => ['الجد أو الجدة', ['الجد/الجدة']],
            'paternal_relative' => ['العم أو العمة', ['العم/ العمة']], 'maternal_relative' => ['الخال أو الخالة', ['الخال/ الخالة']], 'neighbors' => ['جيران', ['جيران']],
            'relatives' => ['أقارب', ['أقارب']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'community_integration' => ['مدى الاندماج في المجتمع', 'AC', ['good' => ['جيد', ['جيد']], 'acceptable' => ['مقبول', ['مقبول']], 'weak' => ['ضعيف', ['ضعيف']], 'isolated' => ['منعزلة', ['منعزلة']]], []],
        'conflict_types' => ['نوع الصراعات الأسرية', 'AD', ['financial' => ['مالية', ['مالية']], 'educational' => ['تربوية', ['تربوية']], 'psychological' => ['نفسية', ['نفسية']], 'other' => ['أخرى', ['أخرى']]], []],
        'supporter_types' => ['الجهة الداعمة', 'AE', ['friends' => ['أصدقاء', ['أصدقاء']], 'relatives' => ['أقارب', ['أقارب']], 'institutions' => ['مؤسسات', ['مؤسسات']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'received_service_types' => ['نوع الخدمات المتلقاة', 'AF', ['psychological' => ['نفسية', ['نفسية']], 'social' => ['اجتماعية', ['اجتماعية']], 'relief' => ['إغاثية', ['اغاثية']],
            'legal' => ['قانونية', ['قانونية']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'child_activities' => ['الأنشطة المجتمعية للأطفال', 'AG', ['sports' => ['رياضية', ['رياضية']], 'religious' => ['دينية', ['دينية']], 'cultural' => ['ثقافية', ['ثقافية']],
            'artistic' => ['فنية', ['فنية']], 'recreational' => ['ترفيهية', ['ترفيهية']], 'none' => ['لا يشارك', ['لا يشارك']]], []],
        'training_interest_fields' => ['مجال التدريب المرغوب', 'AH', ['vocational' => ['مهنية', ['مهنية']], 'educational' => ['تربوية', ['تربوية']], 'psychological' => ['نفسية', ['نفسية']],
            'other' => ['أخرى', []]], []],
        'health_ratings' => ['تقدير الوضع الصحي', 'AI', ['good' => ['جيد', ['جيد']], 'average' => ['متوسط', ['متوسط']], 'weak' => ['ضعيف', ['ضعيف']], 'poor' => ['سيئ', ['سيئ']]], []],
        'treatment_availability' => ['درجة توفر العلاج', 'AL', ['always' => ['متوفر دائمًا', ['متوفر دائماً']], 'hard' => ['صعب التوفير', ['صعب التوفير']], 'unavailable' => ['غير متوفر', ['غير متوفر']]], []],
        'chronic_diseases' => ['الأمراض المزمنة', 'AM', ['hypertension' => ['ضغط الدم', ['ضغط']], 'diabetes' => ['سكري', ['سكري']], 'cancer' => ['سرطان', ['سرطان']],
            'kidney' => ['أمراض الكلى', ['كِلى', 'كلى']], 'anemia' => ['أنيميا (فقر الدم)', ['أنيميا']], 'asthma' => ['الربو', ['الربو']], 'heart' => ['أمراض القلب', ['أمراض القلب']],
            'hepatitis' => ['التهاب الكبد', ['التهاب الكبد']], 'joints' => ['أمراض المفاصل', ['أمراض المفاصل']], 'bones' => ['أمراض العظام', ['أمراض العظام']], 'other' => ['أخرى', []]], []],
        'illness_states' => ['حالة المرض', 'AN', ['stable' => ['مستقرة', ['مستقرة']], 'unstable' => ['مضطربة', ['مضطربة']], 'poor' => ['سيئة', ['سيئة']]], []],
        'health_aids' => ['الأدوات الصحية المساعدة', 'AO', ['wheelchair' => ['كرسي متحرك', ['كرسي متحرك']], 'hearing_aid' => ['سماعات أذن', ['سماعات أذن']], 'glasses' => ['نظارات طبية', ['نظارات طبية']],
            'prosthesis' => ['أطراف صناعية', ['أطراف صناعية']], 'walker' => ['مشاية (ووكر)', ['ووكر']], 'crutches' => ['عكازات', ['عكازات']],
            'anti_bedsore_mattress' => ['مراتب ضد التقرحات', ['مراتب ضد التقرحات']], 'none' => ['لا يستخدم', ['لا يستخدم']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'expense_burdens' => ['أبرز النفقات المرهقة', 'AU', ['rent' => ['إيجار سكن', ['إيجار سكن']], 'food' => ['غذاء', ['غذاء']], 'clothing' => ['ملابس', ['ملابس']], 'treatment' => ['علاج', ['علاج']],
            'debts' => ['ديون', ['ديون']], 'education' => ['تعليم', ['تعليم']], 'disability_needs' => ['احتياجات ذوي الإعاقة', ['احتياجات ذوي الاعاقة']],
            'diapers_milk' => ['حفاضات وحليب', ['بامبرز وحليب']], 'cleaning' => ['مواد تنظيف', ['مواد تنظيف']], 'other' => ['أخرى', []]], []],
        'productive_assets' => ['الأصول الإنتاجية', 'AV', ['farmland' => ['أرض زراعية', ['أرض زراعية']], 'rental_property' => ['عقارات للإيجار', ['عقارات للإيجار']], 'livestock' => ['مواشي', ['مواشي']],
            'shops' => ['محلات تجارية', ['محلات تجارية']], 'craft' => ['حرفة', ['حرفة']], 'commercial_projects' => ['مشاريع تجارية', ['مشاريع تجارية']],
            'industrial_projects' => ['مشاريع صناعية', ['مشاريع صناعية']], 'handicrafts' => ['أشغال يدوية', ['أشغال يدوية']], 'other' => ['أخرى', ['غير ذلك']]], []],
        'vocational_fields' => ['مجالات التدريب المهني', 'AW', ['technical_engineering' => ['التقنية والهندسية', ['التقنية والهندسية']], 'industrial' => ['الصناعية والإنتاجية', ['الصناعية والانتاجية']],
            'crafts' => ['الحرفية والمهنية', ['الحرفية والمهنية']], 'admin_commercial' => ['الإدارية والتجارية', ['الادارية والتجارية']], 'it' => ['تكنولوجيا المعلومات', ['تكنولوجيا المعلومات']],
            'health' => ['الصحية والطبية', ['الصحية والطبية']], 'hospitality' => ['الفندقية والسياحية', ['الفندقية والسياحية']], 'agriculture' => ['الزراعية والبيئية', ['الزراعية والبيئية']],
            'tailoring' => ['الخياطة وتصميم الأزياء', ['الخياطة وتصميم الأزياء']], 'beauty' => ['التجميل', ['التجميل']], 'other' => ['أخرى', ['غير ذلك']]], []],
    ];

    public function up(): void
    {
        $orphansGroup = (int) DB::table('master_data_groups')->where('code', 'orphans')->value('id');
        $sort = 200;
        foreach (self::LISTS as $typeCode => [$name, $column, $values, $aliases]) {
            $type = DB::table('master_data_types')->where('code', $typeCode)->first();
            if (! $type) {
                if ($name === null) {
                    continue; // an expected existing list is absent: nothing to extend
                }
                $typeId = DB::table('master_data_types')->insertGetId(['group_id' => $orphansGroup, 'code' => $typeCode, 'name_ar' => $name,
                    'description' => 'من ورقة «البيانات» في «تسجيل الايتام.xlsx»، العمود '.$column.'.', 'sort_order' => $sort++, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]);
            } else {
                $typeId = (int) $type->id;
            }
            $order = (int) DB::table('master_data_values')->where('type_id', $typeId)->max('sort_order');
            foreach ($values as $code => [$label, $words]) {
                $existing = DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->first();
                if ($existing) {
                    $this->addAliases($existing, $words, $column);

                    continue;
                }
                DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $code, 'name_ar' => $label, 'sort_order' => ($order += 10), 'is_active' => true,
                    'metadata' => json_encode(['aliases' => array_values(array_unique($words)), 'xlsx' => ['file' => 'تسجيل الايتام.xlsx', 'sheet' => 'البيانات', 'column' => $column],
                        'seeded_by' => self::SOURCE], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($aliases as $code => $words) {
                if ($existing = DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->first()) {
                    $this->addAliases($existing, $words, $column);
                }
            }
        }

        $value = fn (Blueprint $t, string $column) => $t->foreignId($column)->nullable()->constrained('master_data_values')->restrictOnDelete();
        Schema::table('persons', fn (Blueprint $t) => $t->string('id_missing_reason', 30)->nullable());
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_id_missing_reason CHECK (id_missing_reason IS NULL OR id_missing_reason IN ('not_issued', 'lost', 'not_available', 'unknown', 'other'))");
        Schema::table('orphan_guardianships', function (Blueprint $t) use ($value): void {
            $value($t, 'court_status_value_id');
            $t->string('kinship_note', 200)->nullable();
        });
        Schema::table('registry_addresses', function (Blueprint $t) use ($value): void {
            $value($t, 'housing_type_value_id');
            $value($t, 'dwelling_condition_value_id');
        });
        Schema::table('households', function (Blueprint $t): void {
            $t->string('location_missing_reason', 300)->nullable();
            $t->string('contact_unreachable_reason', 300)->nullable();
        });
        Schema::table('education_records', function (Blueprint $t) use ($value): void {
            $value($t, 'grade_value_id');
            $value($t, 'achievement_value_id');
        });

        Schema::create('household_case_studies', function (Blueprint $t): void {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('household_id')->constrained('households')->restrictOnDelete();
            $t->unsignedSmallInteger('schema_version');
            $t->string('status', 20)->default('draft');
            $t->text('answers')->nullable();            // encrypted JSON: social, economic, education, recommendations
            $t->text('sensitive_answers')->nullable();  // encrypted JSON: psychological and health (health capability only)
            $t->date('assessed_on')->nullable();
            $t->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE household_case_studies ADD CONSTRAINT household_case_studies_status CHECK (status IN ('draft', 'submitted', 'returned', 'reviewed'))");
        DB::statement("ALTER TABLE household_case_studies ADD CONSTRAINT household_case_studies_submitted CHECK (status NOT IN ('submitted', 'reviewed') OR (submitted_at IS NOT NULL AND assessed_on IS NOT NULL AND assessor_id IS NOT NULL))");
        DB::statement("ALTER TABLE household_case_studies ADD CONSTRAINT household_case_studies_reviewed CHECK (status <> 'reviewed' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND reviewed_by <> assessor_id))");
        // One open study per household; reviewed studies stay as history.
        DB::statement("CREATE UNIQUE INDEX household_case_studies_open ON household_case_studies (household_id) WHERE status <> 'reviewed'");
    }

    public function down(): void
    {
        if (DB::table('household_case_studies')->exists()) {
            throw new RuntimeException('توجد دراسات حالة مسجلة؛ لا يمكن التراجع.');
        }
        foreach ([['persons', 'id_missing_reason'], ['orphan_guardianships', 'court_status_value_id'], ['orphan_guardianships', 'kinship_note'],
            ['registry_addresses', 'housing_type_value_id'], ['registry_addresses', 'dwelling_condition_value_id'], ['households', 'location_missing_reason'],
            ['households', 'contact_unreachable_reason'], ['education_records', 'grade_value_id'], ['education_records', 'achievement_value_id']] as [$table, $column]) {
            if (DB::table($table)->whereNotNull($column)->exists()) {
                throw new RuntimeException("العمود {$table}.{$column} يحوي بيانات؛ لا يمكن التراجع.");
            }
        }
        Schema::drop('household_case_studies');
        Schema::table('education_records', fn (Blueprint $t) => $t->dropConstrainedForeignId('grade_value_id'));
        Schema::table('education_records', fn (Blueprint $t) => $t->dropConstrainedForeignId('achievement_value_id'));
        Schema::table('households', fn (Blueprint $t) => $t->dropColumn(['location_missing_reason', 'contact_unreachable_reason']));
        Schema::table('registry_addresses', fn (Blueprint $t) => $t->dropConstrainedForeignId('housing_type_value_id'));
        Schema::table('registry_addresses', fn (Blueprint $t) => $t->dropConstrainedForeignId('dwelling_condition_value_id'));
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropConstrainedForeignId('court_status_value_id'));
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropColumn('kinship_note'));
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_id_missing_reason');
        Schema::table('persons', fn (Blueprint $t) => $t->dropColumn('id_missing_reason'));
        // Seeded values are removed only while nothing refers to them (the foreign keys refuse otherwise).
        DB::table('master_data_values')->where('metadata->seeded_by', self::SOURCE)->delete();
        foreach (self::LISTS as $typeCode => [$name]) {
            if ($name !== null && ! DB::table('master_data_values')->whereIn('type_id', DB::table('master_data_types')->where('code', $typeCode)->select('id'))->exists()) {
                DB::table('master_data_types')->where('code', $typeCode)->delete();
            }
        }
    }

    /** Adds workbook wording to an existing value's aliases; the name and code stay as they are. */
    private function addAliases(object $value, array $words, string $column): void
    {
        $meta = json_decode((string) ($value->metadata ?? '{}'), true) ?: [];
        $merged = array_values(array_unique([...($meta['aliases'] ?? []), ...array_filter($words, fn ($w) => trim($w) !== '' && trim($w) !== $value->name_ar)]));
        if ($merged === ($meta['aliases'] ?? []) && isset($meta['xlsx'])) {
            return;
        }
        $meta['aliases'] = $merged;
        $meta['xlsx'] ??= ['file' => 'تسجيل الايتام.xlsx', 'sheet' => 'البيانات', 'column' => $column];
        DB::table('master_data_values')->where('id', $value->id)->update(['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
};
