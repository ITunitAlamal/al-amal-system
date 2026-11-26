<?php

namespace App\Services\Orphans\Registration;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Required, conditional and optional fields of family registration (docs/ORPHAN_REGISTRATION_FIELD_MATRIX.md).
 *
 * The rules here are plain data, so the form page receives the same conditions the server applies: the browser
 * shows the required markers and the list of missing fields, and the server refuses the submission. Stages:
 * R required on submission, C required on submission when the condition holds, O optional, L required later
 * (verification or approval, never at submission). A draft accepts everything partial.
 *
 * Conditions: ['filled', path] ['blank', path] ['true', path] ['eq', path, value] ['in', path, [values]]
 * ['code', path, list, [codes]] (the chosen reference value's code), ['all', ...] ['any', ...] ['not', cond].
 * A «*» in a path is the row of the field being checked; in a field without «*» it means «any row».
 * Checks: filled (default), name (first and family name, or the full name), true (a ticked box), rows (a non-empty list).
 *
 * 'by' => 'spec' rules are enforced here; 'by' => 'form' rules are enforced by RegistrationForm::issues() with the
 * same meaning and are listed so the page can mark them.
 */
final class RegistrationFieldSpec
{
    private const PHONE_CHANNELS = ['phone', 'sms', 'whatsapp'];

    /** @return array<string, array{label: string, req: string, by: string, when?: array, check?: string, message?: string, sources?: list<string>}> */
    public static function fields(): array
    {
        $deadOrMissing = fn (string $p): array => ['in', $p.'.vital_status', ['deceased', 'missing']];
        $motherGuardian = ['eq', 'guardians.*.role', 'mother'];

        return [
            // Request and declaration.
            'request.applicant.first_name' => ['label' => 'اسم مقدم الطلب', 'req' => 'R', 'by' => 'form', 'check' => 'name', 'sources' => ['staff']],
            'request.relationship_value_id' => ['label' => 'صلة مقدم الطلب بالأسرة', 'req' => 'R', 'by' => 'form'],
            'request.applicant.phone' => ['label' => 'جوال مقدم الطلب', 'req' => 'C', 'by' => 'spec', 'sources' => ['staff'],
                'when' => ['in', 'request.applicant.contact_channel', self::PHONE_CHANNELS], 'message' => 'طريقة التواصل المختارة تحتاج رقم جوال.'],
            'declaration.truth' => ['label' => 'الإقرار بصحة البيانات', 'req' => 'R', 'by' => 'form', 'check' => 'true'],
            'declaration.consent' => ['label' => 'الموافقة على المراجعة', 'req' => 'R', 'by' => 'form', 'check' => 'true'],
            'declaration.name' => ['label' => 'اسم المُقر', 'req' => 'R', 'by' => 'form'],

            // Parents: the status is always stated («غير محدد» is an answer); a name is needed when it supports the orphan status.
            'father.vital_status' => ['label' => 'حالة الأب', 'req' => 'R', 'by' => 'form'],
            'mother.vital_status' => ['label' => 'حالة الأم', 'req' => 'R', 'by' => 'form'],
            'father.first_name' => ['label' => 'اسم الأب', 'req' => 'C', 'by' => 'form', 'check' => 'name', 'when' => $deadOrMissing('father')],
            'mother.first_name' => ['label' => 'اسم الأم', 'req' => 'C', 'by' => 'form', 'check' => 'name', 'when' => ['any', $deadOrMissing('mother'), $motherGuardian]],
            'father.death.date' => ['label' => 'تاريخ وفاة الأب', 'req' => 'C', 'by' => 'form', 'when' => ['all', ['eq', 'father.vital_status', 'deceased'], ['not', ['true', 'father.death.date_unknown']]]],
            'mother.death.date' => ['label' => 'تاريخ وفاة الأم', 'req' => 'C', 'by' => 'form', 'when' => ['all', ['eq', 'mother.vital_status', 'deceased'], ['not', ['true', 'mother.death.date_unknown']]]],
            'father.missing.last_seen_on' => ['label' => 'آخر ظهور للأب', 'req' => 'C', 'by' => 'form', 'when' => ['all', ['eq', 'father.vital_status', 'missing'], ['not', ['true', 'father.missing.date_unknown']]]],
            'mother.missing.last_seen_on' => ['label' => 'آخر ظهور للأم', 'req' => 'C', 'by' => 'form', 'when' => ['all', ['eq', 'mother.vital_status', 'missing'], ['not', ['true', 'mother.missing.date_unknown']]]],
            'father.death.cause_note' => ['label' => 'توضيح سبب وفاة الأب', 'req' => 'C', 'by' => 'spec', 'when' => ['code', 'father.death.cause_value_id', 'death_causes', ['other']],
                'message' => 'اختيار «سبب آخر» يحتاج توضيحًا قصيرًا.'],
            'mother.death.cause_note' => ['label' => 'توضيح سبب وفاة الأم', 'req' => 'C', 'by' => 'spec', 'when' => ['code', 'mother.death.cause_value_id', 'death_causes', ['other']],
                'message' => 'اختيار «سبب آخر» يحتاج توضيحًا قصيرًا.'],

            // Guardians.
            'guardians' => ['label' => 'وصي واحد على الأقل', 'req' => 'R', 'by' => 'form', 'check' => 'rows'],
            'guardians.*.role' => ['label' => 'من هو الوصي', 'req' => 'R', 'by' => 'form'],
            'guardians.*.first_name' => ['label' => 'اسم الوصي', 'req' => 'C', 'by' => 'form', 'check' => 'name', 'when' => ['eq', 'guardians.*.role', 'other']],
            'guardians.*.id_missing_reason' => ['label' => 'سبب عدم توفر هوية الوصي', 'req' => 'C', 'by' => 'spec',
                'when' => ['all', ['eq', 'guardians.*.role', 'other'], ['blank', 'guardians.*.id_number']], 'message' => 'رقم هوية الوصي غير مدخل: اختر سبب عدم توفره، ولا تكتب رقمًا تقديريًا.'],
            'guardians.*.phone' => ['label' => 'جوال الوصي', 'req' => 'C', 'by' => 'spec', 'when' => ['all', ['eq', 'guardians.*.role', 'other'], ['in', 'guardians.*.contact_channel', self::PHONE_CHANNELS]],
                'message' => 'طريقة التواصل المختارة تحتاج رقم جوال.'],

            // Household contact and location: an actionable channel, or a documented reason for field follow-up.
            'household.contact_unreachable_reason' => ['label' => 'سبب تعذر التواصل', 'req' => 'C', 'by' => 'spec', 'sources' => ['staff'],
                'when' => ['all', ['blank', 'request.applicant.phone'], ['blank', 'guardians.*.phone'], ['blank', 'mother.phone'], ['blank', 'household.contact_phone']],
                'message' => 'لا يوجد أي رقم تواصل: اكتب رقمًا واحدًا على الأقل، أو سبب تعذر التواصل ليُتابع ميدانيًا.'],
            'residence.location_missing_reason' => ['label' => 'سبب عدم تحديد السكن', 'req' => 'C', 'by' => 'spec',
                'when' => ['all', ['blank', 'residence.current.governorate_value_id'], ['blank', 'residence.current.address_details'],
                    ['blank', 'residence.original.governorate_value_id'], ['blank', 'residence.original.address_details']],
                'message' => 'لا يوجد عنوان: حدد السكن الحالي أو الأصلي، أو اكتب سبب عدم تحديده ليُتابع ميدانيًا.'],
            'residence.current.governorate_value_id' => ['label' => 'مكان السكن الحالي للنازحين', 'req' => 'C', 'by' => 'form', 'when' => ['eq', 'residence.displaced', 'yes']],
            'residence.host_note' => ['label' => 'لدى من تقيم الأسرة', 'req' => 'C', 'by' => 'form', 'when' => ['eq', 'residence.hosted', 'yes']],

            // Children: each card is its own record with its own guardian link.
            'children' => ['label' => 'يتيم واحد على الأقل', 'req' => 'R', 'by' => 'form', 'check' => 'rows'],
            'children.*.first_name' => ['label' => 'اسم اليتيم', 'req' => 'R', 'by' => 'form', 'check' => 'name'],
            'children.*.guardian_key' => ['label' => 'وصي اليتيم', 'req' => 'R', 'by' => 'form'],
            'children.*.kinship_value_id' => ['label' => 'صلة الوصي باليتيم', 'req' => 'R', 'by' => 'form'],
            'children.*.kinship_note' => ['label' => 'توضيح صلة القرابة', 'req' => 'C', 'by' => 'spec', 'when' => ['code', 'children.*.kinship_value_id', 'kinship', ['other']],
                'message' => 'اختيار «أخرى» يحتاج توضيح صلة القرابة.'],
            'children.*.guardianship.court_status_value_id' => ['label' => 'توثيق الوصاية في المحكمة', 'req' => 'R', 'by' => 'spec',
                'message' => 'حدد هل الوصاية موثقة في المحكمة (نعم / لا / قيد الإخراج / غير معروف). هذا ليس اعتمادًا قانونيًا.'],
            'children.*.id_missing_reason' => ['label' => 'سبب عدم توفر هوية اليتيم', 'req' => 'C', 'by' => 'spec', 'when' => ['blank', 'children.*.id_number'],
                'message' => 'رقم هوية الطفل غير مدخل: اختر سبب عدم توفره، ولا تكتب رقمًا تقديريًا.'],
            'children.*.education.grade_value_id' => ['label' => 'الصف الدراسي', 'req' => 'C', 'by' => 'spec',
                'when' => ['code', 'children.*.education.status_value_id', 'enrollment', ['enrolled', 'intermittent']], 'message' => 'الطفل ملتحق بالتعليم: اختر الصف الدراسي أو «غير معروف».'],
            'children.*.education.nonattendance_reason' => ['label' => 'سبب عدم الالتحاق', 'req' => 'C', 'by' => 'form',
                'when' => ['code', 'children.*.education.status_value_id', 'enrollment', ['dropped_out', 'never_attended', 'not_enrolled']]],
            'children.*.sponsorship.organization' => ['label' => 'الجهة الكافلة', 'req' => 'C', 'by' => 'form', 'when' => ['eq', 'children.*.sponsorship.status', 'yes']],
        ];
    }

    /** Errors of the 'spec' rules on submission. @param  'staff'|'portal'  $source @return array<string, string> */
    public static function errors(array $data, string $source): array
    {
        $errors = [];
        foreach (self::fields() as $key => $field) {
            if ($field['by'] !== 'spec' || ! self::appliesTo($field, $source)) {
                continue;
            }
            foreach (self::expand($key, $data) as [$concrete, $index]) {
                if ((! isset($field['when']) || self::holds($field['when'], $data, $index)) && ! self::satisfied($field, $concrete, $data)) {
                    $errors[$concrete] = $field['message'] ?? ('هذا الحقل مطلوب: '.$field['label'].'.');
                }
            }
        }

        return $errors;
    }

    /** The rules the form page evaluates for its markers and missing-field list. */
    public static function forClient(string $source): array
    {
        $out = [];
        foreach (self::fields() as $key => $field) {
            if (self::appliesTo($field, $source)) {
                $out[$key] = array_intersect_key($field, array_flip(['label', 'req', 'when', 'check']));
            }
        }

        return $out;
    }

    public static function holds(array $cond, array $data, ?int $index): bool
    {
        $op = $cond[0];

        return match ($op) {
            'all' => collect(array_slice($cond, 1))->every(fn (array $c): bool => self::holds($c, $data, $index)),
            'any' => collect(array_slice($cond, 1))->contains(fn (array $c): bool => self::holds($c, $data, $index)),
            'not' => ! self::holds($cond[1], $data, $index),
            'filled' => self::values($cond[1], $data, $index)->contains(fn ($v): bool => self::present($v)),
            'blank' => ! self::values($cond[1], $data, $index)->contains(fn ($v): bool => self::present($v)),
            'true' => self::values($cond[1], $data, $index)->contains(fn ($v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true'),
            'eq' => self::values($cond[1], $data, $index)->contains(fn ($v): bool => (string) $v === (string) $cond[2]),
            'in' => self::values($cond[1], $data, $index)->contains(fn ($v): bool => in_array((string) $v, array_map('strval', $cond[2]), true)),
            'code' => self::values($cond[1], $data, $index)->contains(fn ($v): bool => in_array(self::code($v), $cond[3], true)),
            default => false,
        };
    }

    private static function appliesTo(array $field, string $source): bool
    {
        return ! isset($field['sources']) || in_array($source, $field['sources'], true);
    }

    /** Concrete keys of a field: one per row for a «*» field. @return list<array{0: string, 1: ?int}> */
    private static function expand(string $key, array $data): array
    {
        if (! str_contains($key, '*')) {
            return [[$key, null]];
        }
        [$list] = explode('.*.', $key, 2);

        return array_map(fn (int $i): array => [str_replace('*', (string) $i, $key), $i], array_keys(array_values((array) data_get($data, $list, []))));
    }

    /** Values at a path: the field's own row for «*», every row otherwise. */
    private static function values(string $path, array $data, ?int $index): Collection
    {
        if (! str_contains($path, '*')) {
            return collect([data_get($data, $path)]);
        }
        [$list, $rest] = explode('.*.', $path, 2);
        $rows = array_values((array) data_get($data, $list, []));
        if ($index !== null) {
            return collect([data_get($rows[$index] ?? [], $rest)]);
        }

        return collect($rows)->map(fn ($row) => data_get($row, $rest));
    }

    private static function satisfied(array $field, string $key, array $data): bool
    {
        $value = data_get($data, $key);

        return match ($field['check'] ?? 'filled') {
            'name' => RegistrationForm::hasName((array) data_get($data, substr($key, 0, strrpos($key, '.')), [])),
            'true' => $value === true || $value === 1 || $value === '1',
            'rows' => is_array($value) && $value !== [],
            default => self::present($value),
        };
    }

    private static function present(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === ''));
    }

    private static function code(mixed $valueId): ?string
    {
        static $codes = [];
        if (! is_numeric($valueId)) {
            return null;
        }

        return $codes[(int) $valueId] ??= DB::table('master_data_values')->where('id', (int) $valueId)->value('code');
    }
}
