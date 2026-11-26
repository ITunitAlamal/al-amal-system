<?php

namespace App\Services\Orphans\Import;

use App\Support\Spreadsheets\StreamingXlsxReader;

/**
 * The only accepted source: worksheet «جميع المستفيدين» and its 41 columns (A:AO). Columns are matched by their
 * normalized header label; the letter is a cross-check, never the key. Every column is acknowledged, including the
 * nearly empty AN/AO. Types drive parsing; `protected` columns are stored encrypted and shown only with the
 * «sensitive» capability.
 */
final class RegistrySheet
{
    public const SHEET_NAME = 'جميع المستفيدين';

    /**
     * key => [letter, exact source header, type, protected, master data type code or null, Arabic note].
     * Types: datetime, serial, name, sex, reference, date, age_snapshot, national_id, lookup, text, phone, count, raw.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: ?string, 5: string}>
     */
    public const COLUMNS = [
        'registered_at' => ['A', 'تاريخ التسجيل', 'datetime', false, null, 'تاريخ التسجيل في الكشف'],
        'source_serial' => ['B', 'م', 'serial', false, null, 'رقم تسلسلي في الكشف فقط، ليس رقم ملف'],
        'child_name' => ['C', 'اسم الطفل رباعي', 'name', false, null, 'اسم الطفل كما ورد'],
        'child_sex' => ['D', 'الجنس', 'sex', false, null, 'يبقى «غير محدد» إذا كان فارغًا'],
        'child_photo_ref' => ['E', 'أضف صورة اليتيم', 'reference', true, null, 'رابط مرجعي فقط، لا يُنزَّل تلقائيًا'],
        'child_dob' => ['F', 'تاريخ ميلاد الطفل', 'date', false, null, 'تاريخ الميلاد'],
        'source_age' => ['G', 'ألعمر', 'age_snapshot', false, null, 'عمر وقت التسجيل؛ العمر الحالي يُحسب من تاريخ الميلاد'],
        'child_national_id' => ['H', 'رقم هوية الطفل', 'national_id', false, null, 'نص كما هو؛ 9 أرقام = صيغة متوافقة فقط'],
        'school_grade' => ['I', 'الصف الدراسي', 'lookup', false, 'education_stages', 'الصف الدراسي'],
        'academic_performance' => ['J', 'المستوي الدراسي', 'lookup', false, 'academic_grades', 'المستوى الدراسي (تقدير/انقطاع)، ليس الصف'],
        'health_status' => ['K', 'الحالة الصحية للطفل اليتيم', 'lookup', true, 'health_statuses', 'بيانات صحية سرية'],
        'health_note' => ['L', 'اكتب نوع المرض أو الإعاقة', 'text', true, null, 'وصف صحي سري'],
        'father_name' => ['M', 'اسم الأب رباعي', 'name', false, null, 'اسم الأب'],
        'father_national_id' => ['N', 'رقم هوية الأب', 'national_id', false, null, 'هوية الأب'],
        'father_dob' => ['O', 'تاريخ ميلاد الأب', 'date', false, null, 'ميلاد الأب'],
        'father_death_date' => ['P', 'تاريخ وفاة الأب', 'date', false, null, 'ادعاء من المصدر، غير متحقق'],
        'father_death_cause' => ['Q', 'سبب الوفاة', 'lookup', false, 'death_causes', 'سبب وفاة الأب، غير متحقق'],
        'father_occupation' => ['R', 'عمل الأب قبل الوفاة', 'text', false, null, 'عمل الأب'],
        'mother_name' => ['S', 'اسم الأم رباعي', 'name', false, null, 'اسم الأم'],
        'mother_national_id' => ['T', 'رقم هوية الأم', 'national_id', false, null, 'هوية الأم'],
        'mother_dob' => ['U', 'تاريخ ميلاد الأم', 'date', false, null, 'ميلاد الأم'],
        'mother_status' => ['V', 'الحالة الاجتماعية للأم', 'lookup', false, 'marital_statuses', 'كما أوردها المصدر'],
        'mother_death_cause' => ['W', 'سبب الوفاة الام', 'lookup', false, 'death_causes', 'سبب وفاة الأم (عند الوجود)'],
        'mother_death_date' => ['X', 'تاريخ الوفاة', 'date', false, null, 'تاريخ وفاة الأم'],
        'contact_phone_1' => ['Y', 'رقم تواصل 1', 'phone', true, null, 'رقم تواصل'],
        'contact_phone_2' => ['Z', 'رقم تواصل2', 'phone', true, null, 'رقم تواصل إضافي'],
        'mother_education' => ['AA', 'المستوى التعليمي للأم', 'lookup', false, 'education_levels', 'تعليم الأم'],
        'mother_specialization' => ['AB', 'ما هو التخصص الجامعي؟', 'text', false, null, 'تخصص الأم الجامعي'],
        'family_total' => ['AC', 'عدد أفراد الأسرة الكلي', 'count', false, null, 'كما أورده المصدر، لا يُعاد حسابه'],
        'family_males' => ['AD', 'عدد الذكور', 'count', false, null, 'عدد الذكور'],
        'family_females' => ['AE', 'عدد الإناث', 'count', false, null, 'عدد الإناث'],
        'guardian_name' => ['AF', 'اسم الوصي الشرعي', 'name', false, null, 'اسم الوصي المدّعى، ليس إثباتًا للوصاية'],
        'guardian_national_id' => ['AG', 'رقم هوية الوصي', 'national_id', false, null, 'هوية الوصي المدّعى'],
        'guardian_relation' => ['AH', 'صلة قرابة الوصي باليتيم', 'lookup', false, 'kinship_types', 'صلة مدّعاة'],
        'governorate' => ['AI', 'المحافظة', 'lookup', false, 'governorates', 'المحافظة'],
        'city' => ['AJ', 'المدينة', 'lookup', false, null, 'المدينة/البلدة'],
        'address_detail' => ['AK', 'الحي/ الشارع/ أقرب مسجد', 'text', true, null, 'عنوان تفصيلي خاص'],
        'is_displaced' => ['AL', 'هل أنت نازح؟', 'lookup', false, null, 'ادعاء نزوح'],
        'displacement_address' => ['AM', 'ما هو عنوان النزوح؟', 'text', true, null, 'عنوان نزوح خاص'],
        'extra_1' => ['AN', 'العمود 1', 'raw', true, null, 'عمود إضافي غير معرّف'],
        'extra_2' => ['AO', 'العمود 2', 'raw', true, null, 'عمود إضافي غير معرّف'],
    ];

    /** Keys whose values are stored only encrypted. */
    public static function protectedKeys(): array
    {
        return array_keys(array_filter(self::COLUMNS, fn (array $column): bool => $column[3]));
    }

    /** Header comparison form: no tatweel/diacritics, unified alef/ya/ta-marbuta, no spaces or punctuation. */
    public static function headerKey(string $label): string
    {
        return (string) preg_replace('/[\s\p{P}]+/u', '', ArabicText::normalize($label));
    }

    /**
     * Maps the actual header row to column keys. Reports missing columns, unknown headers, duplicate headers and
     * letters that differ from the documented schema; nothing is guessed.
     *
     * @param  array<int, string>  $headers  column index (1 = A) => header text
     * @return array{map: array<string, int>, missing: list<string>, unknown: list<array{letter: string, header: string}>, collisions: list<string>, moved: list<array{key: string, expected: string, actual: string}>}
     */
    public static function mapHeaders(array $headers): array
    {
        $expected = [];
        foreach (self::COLUMNS as $key => $column) {
            $expected[self::headerKey($column[1])] = $key;
        }
        $map = [];
        $unknown = [];
        $collisions = [];
        $moved = [];
        foreach ($headers as $index => $header) {
            $normalized = self::headerKey($header);
            if ($normalized === '') {
                continue;
            }
            $key = $expected[$normalized] ?? null;
            if ($key === null) {
                $unknown[] = ['letter' => StreamingXlsxReader::columnLetter($index), 'header' => $header];

                continue;
            }
            if (isset($map[$key])) {
                $collisions[] = $key;

                continue;
            }
            $map[$key] = $index;
            $letter = StreamingXlsxReader::columnLetter($index);
            if ($letter !== self::COLUMNS[$key][0]) {
                $moved[] = ['key' => $key, 'expected' => self::COLUMNS[$key][0], 'actual' => $letter];
            }
        }
        $missing = array_values(array_diff(array_keys(self::COLUMNS), array_keys($map)));

        return ['map' => $map, 'missing' => $missing, 'unknown' => $unknown, 'collisions' => array_values(array_unique($collisions)), 'moved' => $moved];
    }
}
