<?php

namespace App\Services\Orphans\Import;

use App\Support\Spreadsheets\StreamingXlsxReader;

/**
 * One source row → its raw text (exactly as stored) and normalized candidate values. Nothing is invented: an ID is
 * never padded, truncated or converted; a missing sex stays null («غير محدد»); a date that cannot be read stays null
 * with its raw text kept and a flag for review.
 */
final class RegistryRowNormalizer
{
    /** Claimed relationship keys that mean the mother. */
    public const MOTHER_RELATIONS = ['الام', 'ام', 'امه', 'والدته', 'الوالده', 'امهم', 'امي'];

    /**
     * @param  array<int, array{value: string, kind: string, date: ?string}>  $cells
     * @param  array<string, int>  $map  column key => column index
     * @return array{raw: array<string, string>, values: array<string, mixed>, flags: array<string, string>, fingerprint: string}
     */
    public function normalize(array $cells, array $map): array
    {
        $raw = [];
        $values = [];
        $flags = [];
        foreach (RegistrySheet::COLUMNS as $key => [, , $type]) {
            $cell = isset($map[$key]) ? ($cells[$map[$key]] ?? null) : null;
            $text = $cell === null ? '' : trim($cell['value']);
            $raw[$key] = $text;
            [$value, $flag] = match ($type) {
                'datetime' => $this->dateValue($cell, $text, true),
                'date' => $this->dateValue($cell, $text, false),
                'national_id' => $this->identity($cell, $text),
                'sex' => [match (ArabicText::key($text)) {
                    'ذكر' => 'male', 'انثي', 'انثى' => 'female', default => null
                }, $text !== '' && ! in_array(ArabicText::key($text), ['ذكر', 'انثي'], true) ? 'unknown_value' : null],
                'count' => [$text !== '' && preg_match('/^\d{1,3}$/', $text) ? (int) $text : null, $text !== '' && ! preg_match('/^\d{1,3}$/', $text) ? 'not_a_count' : null],
                'age_snapshot' => [$text !== '' && is_numeric($text) ? $text : null, $cell !== null && $cell['kind'] === 'error' ? 'source_error' : null],
                default => [$text === '' ? null : $text, null],
            };
            $values[$key] = $value;
            if ($type === 'national_id') {
                $values[$key.'_status'] = $flag;

                continue;
            }
            if ($flag !== null) {
                $flags[$key] = $flag;
            }
        }
        foreach (['child_name', 'father_name', 'mother_name', 'guardian_name'] as $key) {
            $values[$key.'_search'] = ArabicText::normalize($raw[$key]);
        }
        foreach (RegistrySheet::COLUMNS as $key => [, , $type]) {
            if ($type === 'lookup') {
                $values[$key.'_key'] = ArabicText::key($raw[$key]) ?: null;
            }
        }
        $values['is_displaced'] = match ($values['is_displaced_key']) {
            'نعم' => true, 'لا' => false, default => null
        };
        $values['guardian_claims_mother'] = in_array($values['guardian_relation_key'], self::MOTHER_RELATIONS, true);
        $ok = fn (string $key): bool => ($values[$key.'_status'] ?? null) === 'format_ok';
        $values['household_key'] = $ok('father_national_id') && $ok('mother_national_id') ? $values['father_national_id'].'|'.$values['mother_national_id'] : null;
        $values['guardian_key'] = $ok('guardian_national_id') ? $values['guardian_national_id'] : null;
        $values['child_key'] = $ok('child_national_id') ? $values['child_national_id'] : null;

        return ['raw' => $raw, 'values' => $values, 'flags' => $flags, 'fingerprint' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE))];
    }

    /**
     * Text kept as stored. Status: format_ok (exactly 9 digits — format only, not a registry check), missing,
     * short (fewer digits; a numeric cell may have dropped a leading zero), long, not_digits.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function identity(?array $cell, string $text): array
    {
        if ($text === '') {
            return [null, 'missing'];
        }
        if (! preg_match('/^\d+$/', $text)) {
            return [$text, 'not_digits'];
        }
        $length = strlen($text);

        return [$text, $length === 9 ? 'format_ok' : ($length < 9 ? ($cell !== null && $cell['kind'] === 'number' && $length === 8 ? 'short_numeric' : 'short') : 'long')];
    }

    /**
     * Excel date cell, an unformatted Excel serial, or text d/m/Y (also Y-m-d). Text with both parts ≤ 12 is read
     * day-first and flagged ambiguous; a second part above 12 is read month-first and flagged.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateValue(?array $cell, string $text, bool $withTime): array
    {
        if ($text === '' || $cell === null) {
            return [null, null];
        }
        if ($cell['date'] !== null) {
            return [$withTime ? $cell['date'] : substr($cell['date'], 0, 10), null];
        }
        if ($cell['kind'] === 'number' && is_numeric($text) && (float) $text > 366 && (float) $text < 73051) {
            $date = StreamingXlsxReader::serialToDateTime((float) $text);

            return [$withTime ? $date : substr((string) $date, 0, 10), $withTime ? null : 'serial_unformatted'];
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), 'text_date'];
        }
        if (preg_match('/^(\d{1,2})\s*[-\/.\\\\]\s*(\d{1,2})\s*[-\/.\\\\]\s*(\d{4})$/', $text, $m)) {
            [$a, $b, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($a > 12 && checkdate($b, $a, $year)) {
                return [sprintf('%04d-%02d-%02d', $year, $b, $a), 'text_date'];
            }
            if ($b > 12 && checkdate($a, $b, $year)) {
                return [sprintf('%04d-%02d-%02d', $year, $a, $b), 'text_date_month_first'];
            }
            if (checkdate($b, $a, $year)) {
                return [sprintf('%04d-%02d-%02d', $year, $b, $a), $a === $b ? 'text_date' : 'text_date_ambiguous'];
            }
        }

        return [null, 'unreadable_date'];
    }
}
