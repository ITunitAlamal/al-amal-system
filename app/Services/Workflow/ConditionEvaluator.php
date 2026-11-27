<?php

namespace App\Services\Workflow;

use Carbon\CarbonImmutable;

/**
 * Evaluates transition conditions against a request's data.
 *
 * A condition set is an object of field => expectation, all of which must hold:
 *   {"stock_status": "available"}                 equals
 *   {"stock_status": ["unavailable", "partial"]}  one of
 *   {"amount": {"gt": 1000}}                       operator form
 *   {"needed_by": {"lt": "2026-12-01"}}             dates compare as dates (YYYY-MM-DD, optionally with a time)
 * Operators are a fixed whitelist; no expressions are ever executed.
 *
 * A field the request does not carry, or carries empty, satisfies only "empty" (or "filled": false): it is never
 * "not equal to" or "not in" a value. Numbers compare exactly as decimals, dates as points in time, and a yes/no
 * value matches only true/false (or 1/0, "true"/"false"), never any other text.
 */
final class ConditionEvaluator
{
    public const OPERATORS = [
        'eq' => 'يساوي',
        'neq' => 'لا يساوي',
        'gt' => 'أكبر من',
        'gte' => 'أكبر من أو يساوي',
        'lt' => 'أصغر من',
        'lte' => 'أصغر من أو يساوي',
        'in' => 'ضمن القيم',
        'not_in' => 'ليس ضمن القيم',
        'filled' => 'معبأ',
        'empty' => 'فارغ',
    ];

    /**
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>  $context
     */
    public function passes(?array $conditions, array $context): bool
    {
        foreach ($conditions ?? [] as $field => $expectation) {
            // A missing field is the same as an empty one: it holds only "empty" / "filled": false.
            if (! $this->check(array_key_exists($field, $context) ? $context[$field] : null, $expectation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The form of every condition (operators and their values) and, when the fields the request carries are given,
     * that each tested field exists and is compared with a value of its kind (a list field with codes of its list).
     * A condition that can never hold as meant (a mistyped field, "gt" with text) is refused, not published.
     * $fields maps each field code to its type (number, boolean, date, datetime, text, textarea, select) and, for a
     * list, its value codes; null when the fields are not known, which checks the form only.
     *
     * @param  mixed  $conditions  decoded JSON from the designer
     * @param  array<string, array{type: string, values: list<string>|null}>|null  $fields
     * @return list<string> problems, empty when valid
     */
    public function problems(mixed $conditions, ?array $fields = null): array
    {
        if ($conditions === null) {
            return [];
        }
        if (! is_array($conditions) || array_is_list($conditions)) {
            return ['يجب أن تكون الشروط على شكل حقل ← قيمة.'];
        }

        $problems = [];
        foreach ($conditions as $field => $expectation) {
            if (! is_string($field) || ! preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
                $problems[] = "اسم الحقل «{$field}» غير صالح في الشروط.";

                continue;
            }
            $known = $fields[$field] ?? null;
            if ($fields !== null && $known === null) {
                $problems[] = "الحقل «{$field}» في الشروط غير معروف في هذا المسار، فلن يتحقق الشرط كما يُقصد.";

                continue;
            }
            if (is_array($expectation) && ! array_is_list($expectation)) {
                foreach ($expectation as $operator => $expected) {
                    if (! array_key_exists($operator, self::OPERATORS)) {
                        $problems[] = "العامل «{$operator}» غير مدعوم في شرط الحقل {$field}.";

                        continue;
                    }
                    array_push($problems, ...$this->operatorProblems($field, (string) $operator, $expected, $known));
                }

                continue;
            }
            array_push($problems, ...$this->valueProblems($field, is_array($expectation) ? $expectation : [$expectation], $known));
        }

        return $problems;
    }

    /**
     * @param  array{type: string, values: list<string>|null}|null  $known
     * @return list<string>
     */
    private function operatorProblems(string $field, string $operator, mixed $expected, ?array $known): array
    {
        $label = self::OPERATORS[$operator];

        return match ($operator) {
            'gt', 'gte', 'lt', 'lte' => match (true) {
                is_bool($expected) || (! is_numeric($expected) && ! $this->isDate($expected)) => ["العامل «{$label}» في شرط الحقل {$field} يحتاج إلى رقم أو تاريخ بصيغة YYYY-MM-DD."],
                $known !== null && is_numeric($expected) && $known['type'] !== 'number' => ["العامل «{$label}» يقارن هنا رقمًا، والحقل {$field} ليس رقمًا."],
                $known !== null && ! is_numeric($expected) && ! in_array($known['type'], ['date', 'datetime'], true) => ["العامل «{$label}» يقارن هنا تاريخًا، والحقل {$field} ليس تاريخًا."],
                default => [],
            },
            'filled', 'empty' => is_bool($expected) ? [] : ["العامل «{$label}» في شرط الحقل {$field} يأخذ true أو false."],
            'in', 'not_in' => is_array($expected) && array_is_list($expected)
                ? $this->valueProblems($field, $expected, $known)
                : ["العامل «{$label}» في شرط الحقل {$field} يحتاج إلى قائمة قيم."],
            default => $this->valueProblems($field, [$expected], $known),
        };
    }

    /**
     * Values a field is compared with for equality.
     *
     * @param  array<mixed>  $values
     * @param  array{type: string, values: list<string>|null}|null  $known
     * @return list<string>
     */
    private function valueProblems(string $field, array $values, ?array $known): array
    {
        if ($values === []) {
            return ["شرط الحقل {$field} يحتاج إلى قيمة واحدة على الأقل."];
        }
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                return ["قيمة شرط الحقل {$field} يجب أن تكون نصًا أو رقمًا أو true/false."];
            }
        }
        $type = $known['type'] ?? null;
        if ($type === 'boolean' && array_filter($values, fn ($value): bool => ! is_bool($value)) !== []) {
            return ["الحقل {$field} نعم/لا: قارنه بـ true أو false."];
        }
        if ($type === 'number' && array_filter($values, fn ($value): bool => is_bool($value) || ! is_numeric($value)) !== []) {
            return ["الحقل {$field} رقمي: قارنه برقم."];
        }
        $allowed = $known['values'] ?? null;
        if ($allowed !== null) {
            $unknown = array_values(array_diff(array_map(fn ($value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $values), $allowed));
            if ($unknown !== []) {
                return ['القيمة «'.implode('، ', $unknown)."» ليست من قيم قائمة الحقل {$field}."];
            }
        }

        return [];
    }

    private function check(mixed $actual, mixed $expectation): bool
    {
        if (is_array($expectation) && array_is_list($expectation)) {
            return $this->inList($actual, $expectation);
        }

        if (is_array($expectation)) {
            foreach ($expectation as $operator => $expected) {
                if (! $this->apply((string) $operator, $actual, $expected)) {
                    return false;
                }
            }

            return true;
        }

        return $this->equals($actual, $expectation);
    }

    private function apply(string $operator, mixed $actual, mixed $expected): bool
    {
        if (! $this->isFilled($actual)) {
            // Missing or empty: only "empty" (or "not filled") holds; "neq" / "not_in" never pass on an absent value.
            return match ($operator) {
                'filled' => ! $expected,
                'empty' => (bool) $expected,
                default => false,
            };
        }

        return match ($operator) {
            'eq' => $this->equals($actual, $expected),
            'neq' => ! $this->equals($actual, $expected),
            'gt' => ($order = $this->compare($actual, $expected)) !== null && $order > 0,
            'gte' => ($order = $this->compare($actual, $expected)) !== null && $order >= 0,
            'lt' => ($order = $this->compare($actual, $expected)) !== null && $order < 0,
            'lte' => ($order = $this->compare($actual, $expected)) !== null && $order <= 0,
            'in' => $this->inList($actual, (array) $expected),
            'not_in' => ! $this->inList($actual, (array) $expected),
            'filled' => (bool) $expected,
            'empty' => ! $expected,
            default => false,
        };
    }

    /**
     * @param  list<mixed>  $list
     */
    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->equals($actual, $item)) {
                return true;
            }
        }

        return false;
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if (! $this->isFilled($actual)) {
            return false;
        }
        if (is_bool($expected) || is_bool($actual)) {
            $left = $this->boolean($actual);
            $right = $this->boolean($expected);

            return $left !== null && $right !== null && $left === $right;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return $this->compareNumbers($actual, $expected) === 0;
        }

        return (string) $actual === (string) $expected;
    }

    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /** A yes/no value: true/false, 1/0 or "true"/"false"/"1"/"0"; anything else is not a yes/no value. */
    private function boolean(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === 1.0, $value === '1', $value === 'true' => true,
            $value === 0, $value === 0.0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    /** -1, 0 or 1; null when the two cannot be compared (not both numbers, not both dates). */
    private function compare(mixed $actual, mixed $expected): ?int
    {
        if (is_bool($actual) || is_bool($expected)) {
            return null;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return $this->compareNumbers($actual, $expected);
        }
        $left = $this->date($actual);
        $right = $this->date($expected);

        return $left !== null && $right !== null ? $left <=> $right : null;
    }

    /** Exact decimal comparison (no float rounding): "1000.10" equals 1000.1 and is greater than 1000.09. */
    private function compareNumbers(mixed $left, mixed $right): ?int
    {
        $a = $this->decimal($left);
        $b = $this->decimal($right);
        if ($a === null || $b === null) {
            return null;
        }
        if ($a[0] !== $b[0]) {
            return $a[0] ? -1 : 1;
        }
        $order = (strlen($a[1]) <=> strlen($b[1])) ?: (strcmp($a[1], $b[1]) <=> 0);
        if ($order === 0) {
            $length = max(strlen($a[2]), strlen($b[2]));
            $order = strcmp(str_pad($a[2], $length, '0'), str_pad($b[2], $length, '0')) <=> 0;
        }

        return $a[0] ? -$order : $order;
    }

    /** @return array{0: bool, 1: string, 2: string}|null negative, integer digits, fraction digits (no padding zeros) */
    private function decimal(mixed $value): ?array
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return null;
        }
        $text = is_float($value) ? sprintf('%.10F', $value) : trim((string) $value);
        if (stripos($text, 'e') !== false) {
            $text = sprintf('%.10F', (float) $text);
        }
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $match) !== 1) {
            return null;
        }
        $integer = ltrim($match[2], '0');
        $fraction = rtrim($match[3] ?? '', '0');

        return [$match[1] === '-' && ($integer !== '' || $fraction !== ''), $integer, $fraction];
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && $this->date($value) !== null;
    }

    /** A date or date-time in ISO form (YYYY-MM-DD, optionally with a time) as a timestamp; null otherwise. */
    private function date(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', trim($value)) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse(trim($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
