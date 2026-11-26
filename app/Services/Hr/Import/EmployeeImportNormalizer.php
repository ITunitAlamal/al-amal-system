<?php

namespace App\Services\Hr\Import;

use Illuminate\Support\Carbon;

/**
 * Turns one staged row into typed AIMS values and issue codes. Nothing is guessed silently: every
 * transformation is recorded as an issue, an unclear value is left empty (never zero) and the source text
 * stays in the encrypted staging row.
 */
final class EmployeeImportNormalizer
{
    /** Compound name parts that belong to the next word (عبد الله، أبو شمالة) or to the previous one (نور الدين). */
    private const JOIN_NEXT = ['عبد', 'ابو', 'ابن', 'بن', 'بني', 'ال'];

    private const JOIN_PREVIOUS = ['الله', 'الدين', 'الاسلام'];

    public const MONEY_FIELDS = ['base_salary', 'spouse_allowance', 'children_allowance', 'total_salary'];

    public const RATE_FIELDS = ['cost_of_living_rate', 'nature_of_work_rate'];

    public const LEAVE_FIELDS = ['leave_annual', 'leave_carried', 'leave_accrued', 'leave_used', 'leave_balance'];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @param  array<int, array{value: string, kind: string, format: string, formula: bool, date: ?string}>  $cells
     * @param  array<int, string>  $columnMap  column index => field key or "ignore"
     * @param  array<string, mixed>  $options
     * @return array{values: array<string, mixed>, issues: list<array{field: string, code: string}>}
     */
    public function row(array $cells, array $columnMap, array $options, ImportReferences $references): array
    {
        $values = [];
        $issues = [];
        $flag = function (string $field, string $code) use (&$issues): void {
            foreach ($issues as $issue) {
                if ($issue['field'] === $field && $issue['code'] === $code) {
                    return;
                }
            }
            $issues[] = ['field' => $field, 'code' => $code];
        };

        foreach ($columnMap as $column => $field) {
            if ($field === 'ignore' || ! isset(EmployeeImportFields::FIELDS[$field])) {
                continue;
            }
            $cell = $cells[$column] ?? null;
            if ($cell === null || trim($cell['value']) === '') {
                continue;
            }
            if ($cell['formula']) {
                $flag($field, 'formula_value');
            }
            $this->field($field, $cell, $options, $references, $values, $flag);
        }

        $this->crossChecks($values, $options, $flag);

        return ['values' => $values, 'issues' => $issues];
    }

    /**
     * @param  array{value: string, kind: string, format: string, formula: bool, date: ?string}  $cell
     * @param  array<string, mixed>  $values
     */
    private function field(string $field, array $cell, array $options, ImportReferences $references, array &$values, callable $flag): void
    {
        $raw = $this->latinDigits(trim($cell['value']));
        $type = EmployeeImportFields::FIELDS[$field][2];

        switch ($type) {
            case 'serial':
                $values['source_serial'] = mb_substr($raw, 0, 20);
                break;

            case 'code':
                $code = preg_match('/^\d+\.0+$/', $raw) ? strstr($raw, '.', true) : $raw;
                if (preg_match('/^[A-Za-z0-9_-]{1,50}$/D', (string) $code)) {
                    $values[$field] = (string) $code;
                } else {
                    $flag($field, 'code_invalid');
                }
                break;

            case 'text':
                if (EmployeeImportFields::isPlaceholder($raw)) {
                    $flag($field, 'placeholder');
                    break;
                }
                $values[$field] = mb_substr((string) preg_replace('/\s+/u', ' ', $raw), 0, $field === 'address' ? 2000 : 255);
                break;

            case 'national_id':
                $digits = (string) preg_replace('/[\s\-]/u', '', $raw);
                if (EmployeeImportFields::isPlaceholder($digits)) {
                    $flag($field, 'placeholder');
                } elseif (! preg_match('/^\d{9}$/D', $digits)) {
                    $flag($field, 'national_id_invalid');
                } else {
                    $values['national_id'] = $digits;
                    if (! $this->checkDigitValid($digits)) {
                        $flag($field, 'national_id_checksum');
                    }
                }
                break;

            case 'gender':
                $gender = match (EmployeeImportFields::normalize($raw)) {
                    'ذكر', 'ذ', 'male', 'm' => 'male',
                    'انثي', 'ا', 'female', 'f' => 'female',
                    default => null,
                };
                if ($gender === null) {
                    $flag($field, 'gender_unknown');
                } else {
                    $values['gender'] = $gender;
                }
                break;

            case 'date':
                $this->date($field, $cell, $raw, $values, $flag);
                break;

            case 'int':
                if (preg_match('/^\d{1,2}(\.0+)?$/D', $raw) && (int) $raw <= 40) {
                    $values[$field] = (int) $raw;
                } else {
                    $flag($field, 'int_invalid');
                }
                break;

            case 'mobile':
                $this->mobile($field, $cell, $raw, $values, $flag);
                break;

            case 'landline':
                $digits = (string) preg_replace('/[^\d+]/', '', $raw);
                if (EmployeeImportFields::isPlaceholder($raw) || EmployeeImportFields::isPlaceholder($digits)) {
                    $flag($field, 'placeholder');
                } elseif (preg_match('/^\+?\d{7,15}$/D', $digits)) {
                    $values['landline_phone'] = $digits;
                } else {
                    $flag($field, 'phone_invalid');
                }
                break;

            case 'money':
                $amount = $this->number($raw);
                if ($amount === null || $amount < 0) {
                    $flag($field, EmployeeImportFields::isPlaceholder($raw) ? 'placeholder' : 'money_invalid');
                    break;
                }
                if (abs($amount * 100 - round($amount * 100)) > 0.0001) {
                    $flag($field, 'money_precision');
                }
                if ($amount == 0.0 && in_array($field, ['base_salary', 'total_salary'], true) && ($options['zero_salary_unknown'] ?? true)) {
                    $flag($field, 'zero_as_unknown');
                    break;
                }
                $values[$field] = number_format($amount, 2, '.', '');
                break;

            case 'percent':
                $this->percent($field, $cell, $raw, $options, $values, $flag);
                break;

            case 'days':
                $days = $this->number($raw);
                if ($days === null || $days > 9999 || ($days < 0 && $field !== 'leave_balance') || $days < -9999) {
                    $flag($field, 'days_invalid');
                    break;
                }
                if ($days < 0) {
                    $flag($field, 'negative_balance');
                }
                $values[$field] = number_format($days, 2, '.', '');
                break;

            case 'account':
                $digits = (string) preg_replace('/[\s\-]/u', '', $raw);
                if (EmployeeImportFields::isPlaceholder($digits)) {
                    $flag($field, 'placeholder');
                } elseif (! preg_match('/^[A-Za-z0-9]{4,34}$/D', $digits)) {
                    $flag($field, 'account_invalid');
                } else {
                    $values['bank_account'] = strtoupper($digits);
                }
                break;

            case 'reference':
                $this->reference($field, $raw, $references, $values, $flag);
                break;
        }
    }

    /**
     * @param  array{value: string, kind: string, format: string, formula: bool, date: ?string}  $cell
     * @param  array<string, mixed>  $values
     */
    private function date(string $field, array $cell, string $raw, array &$values, callable $flag): void
    {
        $date = $cell['date'];
        if ($date === null && $cell['kind'] === 'number' && is_numeric($raw) && (float) $raw >= 3000 && (float) $raw <= 73050) {
            $date = $this->reader->serialToDate((float) $raw);
            $flag($field, 'date_from_number');
        }
        if ($date === null && $cell['kind'] !== 'number') {
            $date = $this->textDate($raw);
        }
        if ($date === null) {
            $flag($field, EmployeeImportFields::isPlaceholder($raw) ? 'placeholder' : 'date_invalid');

            return;
        }
        $parsed = Carbon::parse($date);
        if ($field === 'date_of_birth') {
            if ($parsed->lt('1920-01-01')) {
                $flag($field, 'date_invalid');

                return;
            }
            if ($parsed->gt(now()->subYears(15))) {
                $flag($field, 'age_under_minimum');

                return;
            }
        } elseif ($parsed->lt('1950-01-01') || $parsed->gt(now()->addYear())) {
            $flag($field, 'date_invalid');

            return;
        }
        $values[$field] = $date;
    }

    /** Day-first text dates (31/12/1990, 31-12-1990, 31.12.1990) or ISO dates (1990-12-31, 1990/12/31). */
    private function textDate(string $raw): ?string
    {
        $text = trim($raw);
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/D', $text, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/D', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Palestinian mobiles are stored in the international form the profile uses (970 + 9 digits).
     *
     * @param  array{value: string, kind: string, format: string, formula: bool, date: ?string}  $cell
     * @param  array<string, mixed>  $values
     */
    private function mobile(string $field, array $cell, string $raw, array &$values, callable $flag): void
    {
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (EmployeeImportFields::isPlaceholder($raw) || EmployeeImportFields::isPlaceholder($digits)) {
            $flag($field, 'placeholder');

            return;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        $international = match (true) {
            (str_starts_with($digits, '970') || str_starts_with($digits, '972')) && strlen($digits) === 12 => $digits,
            str_starts_with($digits, '05') && strlen($digits) === 10 => '970'.substr($digits, 1),
            str_starts_with($digits, '5') && strlen($digits) === 9 => '970'.$digits,
            default => null,
        };
        if ($international === null) {
            $flag($field, 'phone_invalid');

            return;
        }
        if (strlen($digits) === 9) {
            $flag($field, 'leading_zero_restored');
        }
        $values[$field === 'mobile' ? 'phone' : 'alternative_phone'] = $international;
    }

    /**
     * Rates are stored as a percentage (15 means 15%). A value between 0 and 1 is ambiguous unless Excel formats the
     * cell as a percentage, so it follows the batch rule the reviewer chose, and stays empty until then.
     *
     * @param  array{value: string, kind: string, format: string, formula: bool, date: ?string}  $cell
     * @param  array<string, mixed>  $values
     */
    private function percent(string $field, array $cell, string $raw, array $options, array &$values, callable $flag): void
    {
        $number = $this->number(rtrim($raw, '%'));
        if ($number === null || $number < 0) {
            $flag($field, EmployeeImportFields::isPlaceholder($raw) ? 'placeholder' : 'percent_invalid');

            return;
        }
        if ($cell['format'] === 'percent') {
            $number *= 100;
            $flag($field, 'percent_from_format');
        } elseif (! str_ends_with($raw, '%') && $number > 0 && $number <= 1) {
            $rule = $options['percent_rule'] ?? 'review';
            if ($rule === 'fraction') {
                $number *= 100;
                $flag($field, 'percent_fraction_applied');
            } elseif ($rule === 'literal') {
                $flag($field, 'percent_literal_applied');
            } else {
                $flag($field, 'percent_ambiguous');

                return;
            }
        }
        if ($number > 100) {
            $flag($field, 'percent_invalid');

            return;
        }
        $values[$field] = number_format($number, 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function reference(string $field, string $raw, ImportReferences $references, array &$values, callable $flag): void
    {
        $text = mb_substr((string) preg_replace('/\s+/u', ' ', $raw), 0, 255);
        $placeholder = $field !== 'grade' && EmployeeImportFields::isPlaceholder($text);
        if ($placeholder) {
            $flag($field, 'placeholder');

            return;
        }
        $values[$field.'_source'] = $text;
        $resolved = $references->resolve($field, $text);
        $values[$field.'_id'] = $resolved['id'];
        if ($resolved['id'] === null && ! $resolved['decided']) {
            $flag($field, $field === 'department' ? 'department_unmapped' : 'reference_unmapped');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function crossChecks(array &$values, array $options, callable $flag): void
    {
        if (isset($values['full_name'])) {
            $values += $this->splitName($values['full_name'], $values['original_family_name'] ?? null);
            $flag('full_name', 'name_parts_suggested');
        }
        if (isset($values['phone'], $values['alternative_phone']) && $values['phone'] === $values['alternative_phone']) {
            unset($values['alternative_phone']);
            $flag('secondary_mobile', 'secondary_same_as_primary');
        }
        if (isset($values['date_of_birth'], $values['hire_date']) && Carbon::parse($values['hire_date'])->lt(Carbon::parse($values['date_of_birth'])->addYears(15))) {
            $flag('hire_date', 'hire_before_adulthood');
        }
        if (isset($values['hire_date']) && Carbon::parse($values['hire_date'])->isFuture()) {
            $flag('hire_date', 'hire_in_future');
        }
        if (isset($values['base_salary'], $values['total_salary']) && (float) $values['total_salary'] > 0 && (float) $values['total_salary'] < (float) $values['base_salary']) {
            $flag('total_salary', 'total_below_base');
        }

        $payFields = [...self::MONEY_FIELDS, ...self::RATE_FIELDS];
        $hasPay = array_intersect_key($values, array_flip($payFields)) !== [];
        $hasLeave = array_intersect_key($values, array_flip(self::LEAVE_FIELDS)) !== [];
        $hasBank = isset($values['bank_account']) || isset($values['bank_source']);
        if (($hasPay || $hasLeave || $hasBank) && empty($options['as_of_date'])) {
            foreach (array_keys(array_intersect_key($values, array_flip([...$payFields, ...self::LEAVE_FIELDS, 'bank_account']))) as $field) {
                unset($values[$field]);
            }
            unset($values['bank_source'], $values['bank_id']);
            $flag('options', 'baseline_without_date');
        } elseif ($hasPay && empty($options['currency'])) {
            foreach ($payFields as $field) {
                unset($values[$field]);
            }
            $flag('options', 'pay_without_currency');
        }

        $leave = array_map(fn (string $field): ?float => isset($values[$field]) ? (float) $values[$field] : null, array_combine(self::LEAVE_FIELDS, self::LEAVE_FIELDS));
        if (! in_array(null, $leave, true)) {
            $values['leave_consistency'] = abs($leave['leave_carried'] + $leave['leave_accrued'] - $leave['leave_used'] - $leave['leave_balance']) < 0.01 ? 'consistent' : 'inconsistent';
            if ($values['leave_consistency'] === 'inconsistent') {
                $flag('leave_balance', 'leave_inconsistent');
            }
        } elseif (array_filter($leave, fn (?float $value): bool => $value !== null) !== []) {
            $values['leave_consistency'] = 'incomplete';
        }
    }

    /**
     * A suggested split of the source name. The source family column is used as the family name; father and
     * grandfather are filled only when the name has exactly four parts ending with that family. HR reviews it.
     *
     * @return array{first_name_ar: string, father_name_ar: ?string, grandfather_name_ar: ?string, family_name_ar: string}
     */
    public function splitName(string $fullName, ?string $family): array
    {
        $tokens = preg_split('/\s+/u', trim($fullName)) ?: [];
        $parts = [];
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            $normalized = EmployeeImportFields::normalize($token);
            if (in_array($normalized, self::JOIN_NEXT, true) && isset($tokens[$index + 1])) {
                $parts[] = $token.' '.$tokens[++$index];

                continue;
            }
            if (in_array($normalized, self::JOIN_PREVIOUS, true) && $parts !== []) {
                $parts[count($parts) - 1] .= ' '.$token;

                continue;
            }
            $parts[] = $token;
        }
        $familyName = trim((string) $family) !== '' ? trim((string) $family) : (string) end($parts);
        $endsWithFamily = $parts !== [] && EmployeeImportFields::normalize((string) end($parts)) === EmployeeImportFields::normalize($familyName);
        $given = $endsWithFamily ? array_slice($parts, 0, -1) : $parts;

        return [
            'first_name_ar' => mb_substr((string) ($given[0] ?? $familyName), 0, 150),
            'father_name_ar' => $endsWithFamily && count($given) === 3 ? mb_substr($given[1], 0, 150) : null,
            'grandfather_name_ar' => $endsWithFamily && count($given) === 3 ? mb_substr($given[2], 0, 150) : null,
            'family_name_ar' => mb_substr($familyName, 0, 150),
        ];
    }

    /** The Palestinian identity number check digit (alternating weights 1 and 2, digit sum modulo 10). */
    public function checkDigitValid(string $digits): bool
    {
        $sum = 0;
        foreach (str_split($digits) as $index => $digit) {
            $product = (int) $digit * (($index % 2) + 1);
            $sum += $product > 9 ? $product - 9 : $product;
        }

        return $sum % 10 === 0;
    }

    public function number(string $raw): ?float
    {
        $text = str_replace(['٬', ',', ' ', "\u{00A0}"], '', $this->latinDigits(trim($raw)));
        $text = str_replace('٫', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
