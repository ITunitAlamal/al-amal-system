<?php

namespace App\Services\Orphans\Import;

use App\Services\Orphans\RegistryIdentity;
use App\Support\Spreadsheets\StreamingXlsxReader;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;

/**
 * Two streaming passes over «جميع المستفيدين»: pass 1 builds the cross-row aggregates (repeated child IDs,
 * provisional households from the father+mother ID pair, claimed guardians across households, name variants);
 * pass 2 yields each normalized row with its issues. Memory is bounded by the aggregates, not the sheet.
 *
 * The same engine serves the dry run (counts only) and the staging import, so both always agree.
 */
final class RegistryAnalyzer
{
    private const FATHER_DEATH_BEFORE_BIRTH_DAYS = 300;

    private StreamingXlsxReader $reader;

    /** @var array{map: array<string, int>, missing: list<string>, unknown: list<array{letter: string, header: string}>, collisions: list<string>, moved: list<array{key: string, expected: string, actual: string}>}|null */
    private ?array $headerMap = null;

    private int $headerRow = 0;

    /** @var array<string, array{rows: int, signatures: array<string, true>, households: array<string, true>}> */
    private array $children = [];

    /** @var array<string, array{rows: int, children: array<string, true>, guardians: array<string, true>}> */
    private array $households = [];

    /** @var array<string, array{rows: int, names: array<string, true>, households: array<string, true>, children: array<string, true>}> */
    private array $guardians = [];

    /** @var array<string, true> HMAC hashes of identities already in the approved registry */
    private array $existing;

    private ?CarbonImmutable $today = null;

    private RegistryRowNormalizer $normalizer;

    /** @param array<string, true> $existingChildHashes */
    public function __construct(private readonly string $path, array $existingChildHashes = [], ?CarbonImmutable $today = null)
    {
        $this->reader = new StreamingXlsxReader($path, RegistrySheet::SHEET_NAME);
        $this->normalizer = new RegistryRowNormalizer;
        $this->existing = $existingChildHashes;
        $this->today = $today ?? CarbonImmutable::now(config('orphans.timezone'));
    }

    /** @return array{map: array<string, int>, missing: list<string>, unknown: list<array{letter: string, header: string}>, collisions: list<string>, moved: list<array{key: string, expected: string, actual: string}>, header_row: int, headers: array<string, string>} */
    public function headers(): array
    {
        if ($this->headerMap === null) {
            $this->reader->open();
            foreach ($this->reader->rows() as $number => $cells) {
                $this->headerRow = $number;
                $headers = array_map(fn (array $cell): string => trim($cell['value']), $cells);
                $this->headerMap = RegistrySheet::mapHeaders($headers) + ['header_row' => $number,
                    'headers' => collect($headers)->mapWithKeys(fn (string $h, int $i): array => [StreamingXlsxReader::columnLetter($i) => $h])->all()];
                break;
            }
            if ($this->headerMap === null) {
                throw new RuntimeException('sheet_empty');
            }
        }

        return $this->headerMap;
    }

    /** Header problems that stop the import (a column that cannot be identified is never guessed). */
    public function headerBlockers(): array
    {
        $headers = $this->headers();
        $blockers = [];
        foreach ($headers['missing'] as $key) {
            $blockers[] = 'العمود «'.RegistrySheet::COLUMNS[$key][1].'» غير موجود';
        }
        foreach ($headers['collisions'] as $key) {
            $blockers[] = 'العمود «'.RegistrySheet::COLUMNS[$key][1].'» مكرر';
        }

        return $blockers;
    }

    /** Pass 1: aggregates only (no row kept in memory). */
    public function aggregate(): void
    {
        $map = $this->headers()['map'];
        $this->children = $this->households = $this->guardians = [];
        foreach ($this->dataRows() as $number => $cells) {
            $v = $this->normalizer->normalize($cells, $map)['values'];
            $child = $v['child_key'];
            if ($child !== null) {
                $c = &$this->children[$child];
                $c['rows'] = ($c['rows'] ?? 0) + 1;
                $c['signatures'][$this->signature($v)] = true;
                if ($v['household_key'] !== null) {
                    $c['households'][$v['household_key']] = true;
                }
                unset($c);
            }
            if ($v['household_key'] !== null) {
                $h = &$this->households[$v['household_key']];
                $h['rows'] = ($h['rows'] ?? 0) + 1;
                $h['children'][$child ?? 'row'.$number] = true;
                if ($v['guardian_key'] !== null) {
                    $h['guardians'][$v['guardian_key']] = true;
                }
                unset($h);
            }
            if ($v['guardian_key'] !== null) {
                $g = &$this->guardians[$v['guardian_key']];
                $g['rows'] = ($g['rows'] ?? 0) + 1;
                $g['names'][$v['guardian_name_search']] = true;
                if ($v['household_key'] !== null) {
                    $g['households'][$v['household_key']] = true;
                }
                if ($child !== null) {
                    $g['children'][$child] = true;
                }
                unset($g);
            }
        }
    }

    /**
     * Pass 2: every data row with its normalized values and issues, in sheet order.
     *
     * @return Generator<int, array{row: int, raw: array<string, string>, values: array<string, mixed>, flags: array<string, string>, fingerprint: string, issues: list<array{type: string, severity: string, details: array<string, mixed>}>}>
     */
    public function rows(): Generator
    {
        $map = $this->headers()['map'];
        foreach ($this->dataRows() as $number => $cells) {
            $row = $this->normalizer->normalize($cells, $map);
            yield $number => ['row' => $number] + $row + ['issues' => $this->issues($row['values'], $row['flags'])];
        }
    }

    /**
     * Counts for the dry-run report and the batch summary. No names, identities or addresses.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $this->aggregate();
        $counts = ['rows' => 0, 'child_id_format_ok' => 0, 'child_id_status' => [], 'sex' => ['male' => 0, 'female' => 0, 'missing' => 0],
            'both_parent_ids_ok' => 0, 'guardian_claims_mother' => 0, 'issues' => [], 'rows_with_issue' => [], 'severity_rows' => [],
            'flags' => [], 'lookup_distinct' => [], 'populated' => []];
        $lookups = [];
        foreach ($this->rows() as $row) {
            $v = $row['values'];
            $counts['rows']++;
            $counts['child_id_status'][$v['child_national_id_status']] = ($counts['child_id_status'][$v['child_national_id_status']] ?? 0) + 1;
            $counts['child_id_format_ok'] += $v['child_key'] !== null ? 1 : 0;
            $counts['sex'][$v['child_sex'] ?? 'missing']++;
            $counts['both_parent_ids_ok'] += $v['household_key'] !== null ? 1 : 0;
            $counts['guardian_claims_mother'] += $v['guardian_claims_mother'] ? 1 : 0;
            foreach ($row['raw'] as $key => $value) {
                if ($value !== '') {
                    $counts['populated'][$key] = ($counts['populated'][$key] ?? 0) + 1;
                }
            }
            foreach ($row['flags'] as $key => $flag) {
                $counts['flags'][$key.':'.$flag] = ($counts['flags'][$key.':'.$flag] ?? 0) + 1;
            }
            $worst = null;
            $seen = [];
            foreach ($row['issues'] as $issue) {
                $counts['issues'][$issue['type'].(isset($issue['details']['reason']) ? ':'.$issue['details']['reason'] : '')] =
                    ($counts['issues'][$issue['type'].(isset($issue['details']['reason']) ? ':'.$issue['details']['reason'] : '')] ?? 0) + 1;
                if (! isset($seen[$issue['type']])) {
                    $counts['rows_with_issue'][$issue['type']] = ($counts['rows_with_issue'][$issue['type']] ?? 0) + 1;
                    $seen[$issue['type']] = true;
                }
                $worst = self::worse($worst, $issue['severity']);
            }
            $counts['severity_rows'][$worst ?? 'none'] = ($counts['severity_rows'][$worst ?? 'none'] ?? 0) + 1;
            foreach (RegistrySheet::COLUMNS as $key => [, , $type]) {
                if ($type === 'lookup' && $v[$key.'_key'] !== null) {
                    $lookups[$key][$v[$key.'_key']] = true;
                }
            }
        }
        foreach ($lookups as $key => $values) {
            $counts['lookup_distinct'][$key] = count($values);
        }
        $multiHouseholdGuardians = count(array_filter($this->guardians, fn (array $g): bool => count($g['households'] ?? []) > 1));
        $counts['distinct'] = [
            'child_ids' => count($this->children),
            'repeated_child_ids' => count(array_filter($this->children, fn (array $c): bool => $c['rows'] > 1)),
            'extra_rows_for_repeated_ids' => array_sum(array_map(fn (array $c): int => $c['rows'] - 1, $this->children)),
            'conflicting_child_ids' => count(array_filter($this->children, fn (array $c): bool => count($c['signatures']) > 1)),
            'households' => count($this->households),
            'households_multiple_guardians' => count(array_filter($this->households, fn (array $h): bool => count($h['guardians'] ?? []) > 1)),
            'guardian_ids' => count($this->guardians),
            'guardians_multiple_households' => $multiHouseholdGuardians,
            'guardians_name_variants' => count(array_filter($this->guardians, fn (array $g): bool => count($g['names']) > 1)),
            'children_in_multiple_households' => count(array_filter($this->children, fn (array $c): bool => count($c['households'] ?? []) > 1)),
        ];
        ksort($counts['issues']);

        return $counts;
    }

    /** @return Generator<int, array<int, array{value: string, kind: string, date: ?string}>> */
    private function dataRows(): Generator
    {
        $this->headers();
        foreach ($this->reader->rows() as $number => $cells) {
            if ($number <= $this->headerRow) {
                continue;
            }
            yield $number => $cells;
        }
    }

    /** What must agree for two rows with the same child ID to describe the same child. */
    private function signature(array $v): string
    {
        return implode('|', [$v['child_dob'] ?? '', $v['child_name_search'], $v['father_national_id'] ?? '', $v['mother_national_id'] ?? '']);
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, string>  $flags
     * @return list<array{type: string, severity: string, details: array<string, mixed>}>
     */
    private function issues(array $v, array $flags): array
    {
        $issues = [];
        $add = function (string $type, array $details = [], ?string $severity = null) use (&$issues): void {
            $issues[] = ['type' => $type, 'severity' => $severity ?? RegistryIssueTypes::severity($type), 'details' => $details];
        };
        $child = $v['child_key'];
        match ($v['child_national_id_status']) {
            'format_ok' => null,
            'missing' => $add('child_id_missing'),
            default => $add('child_id_format', ['reason' => $v['child_national_id_status']]),
        };
        if ($child !== null) {
            $aggregate = $this->children[$child] ?? null;
            if ($aggregate && count($aggregate['signatures']) > 1) {
                $add('child_conflict', ['rows' => $aggregate['rows']]);
            } elseif ($aggregate && $aggregate['rows'] > 1) {
                $add('duplicate', ['rows' => $aggregate['rows']]);
            }
            if ($aggregate && count($aggregate['households'] ?? []) > 1) {
                $add('household_link', ['reason' => 'child_in_multiple_households'], 'high');
            }
            if ($this->existing !== [] && isset($this->existing[RegistryIdentity::hash($child)])) {
                $add('existing_record');
            }
        }
        if ($v['household_key'] === null) {
            $add('household_link', ['reason' => 'parent_id_unusable', 'fields' => array_values(array_filter(['father_national_id', 'mother_national_id'], fn (string $k): bool => $v[$k.'_status'] !== 'format_ok'))]);
        } elseif (count($this->households[$v['household_key']]['guardians'] ?? []) > 1) {
            $add('multiple_guardians', ['guardians' => count($this->households[$v['household_key']]['guardians'])]);
        }
        $guardian = $v['guardian_key'];
        // Compared on the IDs as written, whatever their format: «الأم» with another person's ID is a conflict.
        if ($v['guardian_claims_mother'] && $v['guardian_national_id'] !== null && $v['guardian_national_id'] !== $v['mother_national_id']) {
            $add('guardian_mother_mismatch');
        }
        if ($guardian === null) {
            $add('guardianship_unconfirmed', ['reason' => $v['guardian_national_id_status']]);
        } else {
            if ($guardian === $v['father_national_id']) {
                $add('guardian_is_father');
            }
            if (count($this->guardians[$guardian]['names'] ?? []) > 1) {
                $add('guardian_name_variant', ['names' => count($this->guardians[$guardian]['names'])]);
            }
        }
        foreach ($this->dateProblems($v, $flags) as [$reason, $field, $severity]) {
            $add('date_review', ['reason' => $reason, 'field' => $field], $severity);
        }
        if ($v['family_total'] !== null && $v['family_males'] !== null && $v['family_females'] !== null && $v['family_total'] !== $v['family_males'] + $v['family_females']) {
            $add('family_counts', ['reason' => 'total_differs']);
        } elseif (isset($flags['family_total']) || isset($flags['family_males']) || isset($flags['family_females'])) {
            $add('family_counts', ['reason' => 'not_a_count']);
        }
        if ($v['child_sex'] === null) {
            $add('sex_missing');
        }

        return $issues;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [reason, field, severity] */
    private function dateProblems(array $v, array $flags): array
    {
        $problems = [];
        $today = $this->today->toDateString();
        if ($v['child_dob'] !== null && $v['child_dob'] > $today) {
            $problems[] = ['future_birth', 'child_dob', 'high'];
        }
        if ($v['child_dob'] !== null && $v['father_death_date'] !== null
            && CarbonImmutable::parse($v['father_death_date'])->diffInDays(CarbonImmutable::parse($v['child_dob']), false) > self::FATHER_DEATH_BEFORE_BIRTH_DAYS) {
            $problems[] = ['father_death_long_before_birth', 'father_death_date', 'high'];
        }
        if ($v['registered_at'] !== null && $v['father_death_date'] !== null && $v['father_death_date'] > substr($v['registered_at'], 0, 10)) {
            $problems[] = ['father_death_after_registration', 'father_death_date', 'medium'];
        }
        foreach (['child_dob', 'father_dob', 'father_death_date', 'mother_dob', 'mother_death_date', 'registered_at'] as $field) {
            $flag = $flags[$field] ?? null;
            if (in_array($flag, ['unreadable_date', 'text_date_ambiguous', 'text_date_month_first'], true)) {
                $problems[] = [$flag, $field, $flag === 'unreadable_date' && $field === 'child_dob' ? 'high' : 'medium'];
            }
        }

        return $problems;
    }

    private static function worse(?string $current, string $candidate): string
    {
        $order = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

        return $current === null || $order[$candidate] > $order[$current] ? $candidate : $current;
    }
}
