<?php

namespace App\Services\Orphans\Import;

use App\Services\Orphans\ArabicText;

/**
 * Target fields per import purpose, with Arabic/English header aliases. Sensitivity drives who may map a column:
 * "health" needs the «health» capability, "identity" is stored only as a registry identity (never logged).
 * A header is suggested only on an exact alias match; anything ambiguous stays unmapped for a person to choose.
 */
final class ImportFields
{
    public const PURPOSES = [
        'registry' => 'بيانات الأيتام والأوصياء والأب والأم (جديدة أو تحديث)',
        'campaign_candidates' => 'كشف مرشحين أو حصص مناطق أو كميات مقاسات لحملة',
        'delivery_reconciliation' => 'كشف مطابقة تسليم (تقرير فقط، لا يثبت تسليمًا)',
        'donor_recipients' => 'كشف مستفيدين مقترح من مانح (مقترحات للمطابقة)',
    ];

    /** key => [label, type, sensitivity, aliases] */
    private const REGISTRY = [
        'registered_at' => ['تاريخ التسجيل', 'date', 'normal', ['تاريخ التسجيل', 'registered at', 'registration date']],
        'source_serial' => ['الرقم التسلسلي في المصدر', 'text', 'normal', ['م', 'الرقم', 'serial', 'no']],
        'child_full_name' => ['اسم اليتيم كاملًا', 'text', 'normal', ['اسم الطفل رباعي', 'اسم اليتيم', 'اسم اليتيم رباعي', 'child name', 'orphan name']],
        'child_first_name' => ['اسم اليتيم الأول', 'text', 'normal', ['الاسم الأول لليتيم', 'child first name']],
        'child_father_name' => ['اسم أب اليتيم', 'text', 'normal', ['child father name']],
        'child_grandfather_name' => ['اسم جد اليتيم', 'text', 'normal', ['child grandfather name']],
        'child_family_name' => ['عائلة اليتيم', 'text', 'normal', ['اسم العائلة لليتيم', 'child family name']],
        'child_id' => ['رقم هوية اليتيم', 'identity', 'identity', ['رقم هوية الطفل', 'رقم هوية اليتيم', 'child id', 'orphan id']],
        'child_dob' => ['تاريخ ميلاد اليتيم', 'date', 'normal', ['تاريخ ميلاد الطفل', 'تاريخ ميلاد اليتيم', 'child dob', 'date of birth']],
        'child_sex' => ['جنس اليتيم', 'sex', 'normal', ['الجنس', 'gender', 'sex']],
        'school_grade' => ['الصف الدراسي', 'text', 'normal', ['الصف الدراسي', 'grade']],
        'academic_performance' => ['المستوى الدراسي', 'text', 'normal', ['المستوي الدراسي', 'المستوى الدراسي', 'performance']],
        'health_status' => ['الحالة الصحية', 'text', 'health', ['الحالة الصحية للطفل اليتيم', 'الحالة الصحية', 'health status']],
        'health_note' => ['وصف المرض أو الإعاقة', 'text', 'health', ['اكتب نوع المرض أو الإعاقة', 'health note']],
        'father_full_name' => ['اسم الأب كاملًا', 'text', 'normal', ['اسم الأب رباعي', 'اسم الأب', 'father name']],
        'father_id' => ['رقم هوية الأب', 'identity', 'identity', ['رقم هوية الأب', 'father id']],
        'father_dob' => ['تاريخ ميلاد الأب', 'date', 'normal', ['تاريخ ميلاد الأب', 'father dob']],
        'father_death_date' => ['تاريخ وفاة الأب', 'date', 'normal', ['تاريخ وفاة الأب', 'father death date']],
        'father_death_cause' => ['سبب وفاة الأب', 'text', 'health', ['سبب الوفاة', 'father death cause']],
        'father_occupation' => ['عمل الأب قبل الوفاة', 'text', 'normal', ['عمل الأب قبل الوفاة', 'father occupation']],
        'mother_full_name' => ['اسم الأم كاملًا', 'text', 'normal', ['اسم الأم رباعي', 'اسم الأم', 'mother name']],
        'mother_id' => ['رقم هوية الأم', 'identity', 'identity', ['رقم هوية الأم', 'mother id']],
        'mother_dob' => ['تاريخ ميلاد الأم', 'date', 'normal', ['تاريخ ميلاد الأم', 'mother dob']],
        'mother_marital' => ['الحالة الاجتماعية للأم', 'text', 'normal', ['الحالة الاجتماعية للأم', 'mother marital status']],
        'mother_death_cause' => ['سبب وفاة الأم', 'text', 'health', ['سبب الوفاة الام', 'سبب وفاة الأم']],
        'mother_death_date' => ['تاريخ وفاة الأم', 'date', 'normal', ['تاريخ الوفاة', 'تاريخ وفاة الأم', 'mother death date']],
        'contact_phone' => ['رقم التواصل', 'phone', 'normal', ['رقم تواصل 1', 'رقم التواصل', 'phone', 'mobile']],
        'contact_phone_2' => ['رقم تواصل إضافي', 'phone', 'normal', ['رقم تواصل2', 'رقم تواصل 2']],
        'mother_education' => ['المستوى التعليمي للأم', 'text', 'normal', ['المستوى التعليمي للأم']],
        'mother_specialization' => ['تخصص الأم الجامعي', 'text', 'normal', ['ما هو التخصص الجامعي؟']],
        'family_total' => ['عدد أفراد الأسرة', 'count', 'normal', ['عدد أفراد الأسرة الكلي', 'family size']],
        'family_males' => ['عدد الذكور', 'count', 'normal', ['عدد الذكور']],
        'family_females' => ['عدد الإناث', 'count', 'normal', ['عدد الإناث']],
        'guardian_full_name' => ['اسم الوصي كاملًا', 'text', 'normal', ['اسم الوصي الشرعي', 'اسم الوصي', 'guardian name']],
        'guardian_id' => ['رقم هوية الوصي', 'identity', 'identity', ['رقم هوية الوصي', 'guardian id']],
        'guardian_relation' => ['صلة الوصي باليتيم', 'text', 'normal', ['صلة قرابة الوصي باليتيم', 'relation', 'guardian relation']],
        'governorate' => ['المحافظة', 'text', 'normal', ['المحافظة', 'governorate']],
        'city' => ['المدينة', 'text', 'normal', ['المدينة', 'city']],
        'address_detail' => ['العنوان التفصيلي', 'text', 'normal', ['الحي/ الشارع/ أقرب مسجد', 'العنوان', 'address']],
        'is_displaced' => ['هل نازح', 'bool', 'normal', ['هل أنت نازح؟', 'نازح', 'displaced']],
        'displacement_address' => ['عنوان النزوح', 'text', 'normal', ['ما هو عنوان النزوح؟', 'عنوان النزوح']],
    ];

    private const CAMPAIGN = [
        'child_id' => ['رقم هوية اليتيم', 'identity', 'identity', ['رقم هوية الطفل', 'رقم هوية اليتيم', 'child id']],
        'orphan_reference' => ['رقم ملف اليتيم', 'text', 'normal', ['رقم الملف', 'orphan reference']],
        'household_reference' => ['رقم الأسرة', 'text', 'normal', ['رقم الأسرة', 'household']],
        'guardian_id' => ['رقم هوية الوصي', 'identity', 'identity', ['رقم هوية الوصي', 'guardian id']],
        'full_name' => ['الاسم (للمطابقة فقط)', 'text', 'normal', ['الاسم', 'name', 'اسم المستفيد']],
        'variant' => ['المقاس/الصنف', 'text', 'normal', ['المقاس', 'size', 'الصنف']],
        'quantity' => ['الكمية', 'count', 'normal', ['الكمية', 'quantity']],
        'governorate' => ['المحافظة', 'text', 'normal', ['المحافظة', 'governorate']],
        'delivered' => ['تم التسليم', 'bool', 'normal', ['تم التسليم', 'delivered', 'استلم']],
        'delivered_on' => ['تاريخ التسليم', 'date', 'normal', ['تاريخ التسليم', 'delivery date']],
        'recipient' => ['اسم المستلم', 'text', 'normal', ['المستلم', 'recipient']],
    ];

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}> */
    public static function for(string $purpose): array
    {
        return $purpose === 'registry' ? self::REGISTRY : self::CAMPAIGN;
    }

    public static function headerKey(string $label): string
    {
        return (string) preg_replace('/[\s\p{P}]+/u', '', ArabicText::normalize($label));
    }

    /** Exact alias matches only; a header matching two fields, or two headers matching one field, stays unmapped. */
    public static function suggest(string $purpose, array $headers): array
    {
        $aliases = [];
        foreach (self::for($purpose) as $key => [, , , $list]) {
            foreach ($list as $alias) {
                $aliases[self::headerKey($alias)][] = $key;
            }
        }
        $mapping = [];
        $used = [];
        foreach ($headers as $index => $header) {
            $candidates = array_unique($aliases[self::headerKey((string) $header)] ?? []);
            if (count($candidates) === 1 && ! isset($used[$candidates[0]])) {
                $mapping[(string) $index] = $candidates[0];
                $used[$candidates[0]] = true;
            }
        }

        return $mapping;
    }
}
