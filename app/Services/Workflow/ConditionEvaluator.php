<?php

namespace App\Services\Workflow;

/**
 * Evaluates transition conditions against a request's data.
 *
 * A condition set is an object of field => expectation, all of which must hold:
 *   {"stock_status": "available"}                 equals
 *   {"stock_status": ["unavailable", "partial"]}  one of
 *   {"amount": {"gt": 1000}}                       operator form
 * Operators are a fixed whitelist; no expressions are ever executed.
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
            if (! $this->check($context[$field] ?? null, $expectation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $conditions  decoded JSON from the designer
     * @return list<string> problems, empty when valid
     */
    public function problems(mixed $conditions): array
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
            }
            if (is_array($expectation) && ! array_is_list($expectation)) {
                foreach (array_keys($expectation) as $operator) {
                    if (! array_key_exists($operator, self::OPERATORS)) {
                        $problems[] = "العامل «{$operator}» غير مدعوم في شرط الحقل {$field}.";
                    }
                }
            }
        }

        return $problems;
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
        $filled = $actual !== null && $actual !== '';

        return match ($operator) {
            'eq' => $this->equals($actual, $expected),
            'neq' => ! $this->equals($actual, $expected),
            'gt' => $filled && is_numeric($actual) && (float) $actual > (float) $expected,
            'gte' => $filled && is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt' => $filled && is_numeric($actual) && (float) $actual < (float) $expected,
            'lte' => $filled && is_numeric($actual) && (float) $actual <= (float) $expected,
            'in' => $this->inList($actual, (array) $expected),
            'not_in' => ! $this->inList($actual, (array) $expected),
            'filled' => $expected ? $filled : ! $filled,
            'empty' => $expected ? ! $filled : $filled,
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
        if (is_bool($expected) || is_bool($actual)) {
            return (bool) $actual === (bool) $expected && $actual !== null;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected && $actual !== null;
    }
}
