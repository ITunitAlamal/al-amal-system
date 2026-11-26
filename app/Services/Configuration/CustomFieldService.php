<?php

namespace App\Services\Configuration;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads, validates and stores dynamic field values.
 *
 * Input arrives keyed by field code. Validation errors are reported under
 * "fields.<code>" so forms can show them next to the right input.
 */
final class CustomFieldService
{
    public const TYPES = [
        'text' => 'نص قصير',
        'textarea' => 'نص طويل',
        'number' => 'رقم',
        'date' => 'تاريخ',
        'boolean' => 'نعم / لا',
        'select' => 'قائمة من الثوابت',
    ];

    public const VISIBILITY_OPERATORS = ['equals', 'not_equals', 'filled', 'empty'];

    public function __construct(private readonly MasterDataLookup $masterData) {}

    /**
     * Active definitions for a form. A null step means the submission form.
     *
     * @return Collection<int, object>
     */
    public function definitions(string $entityType, string $scopeKey = '', ?string $stepCode = null, ?CarbonInterface $on = null): Collection
    {
        $date = ($on ?? now())->toDateString();

        return DB::table('custom_field_definitions')
            ->where('entity_type', $entityType)
            ->whereIn('scope_key', array_values(array_unique(['', $scopeKey])))
            ->when($stepCode === null, fn (Builder $query) => $query->whereNull('step_code'), fn (Builder $query) => $query->where('step_code', $stepCode))
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (object $definition): object {
                $definition->validation = $this->decode($definition->validation);
                $definition->visible_when = $this->decode($definition->visible_when);

                return $definition;
            });
    }

    /**
     * Shape definitions for the DynamicFields Vue component.
     *
     * @param  Collection<int, object>  $definitions
     * @return list<array<string, mixed>>
     */
    public function forForm(Collection $definitions): array
    {
        return $definitions->map(fn (object $definition): array => [
            'code' => $definition->code,
            'label' => $definition->label_ar,
            'help' => $definition->help_text,
            'type' => $definition->field_type,
            'required' => (bool) $definition->is_required,
            'validation' => $definition->validation ?? (object) [],
            'visible_when' => $definition->visible_when,
            'options' => $definition->field_type === 'select' && $definition->master_data_type_id
                ? $this->masterData->options((int) $definition->master_data_type_id)
                    ->map(fn (object $value): array => ['value' => $value->id, 'label' => $value->name_ar])->all()
                : [],
        ])->values()->all();
    }

    /**
     * Validate submitted values and return them normalized, keyed by code.
     * Fields hidden by their visibility rule are ignored.
     *
     * @param  Collection<int, object>  $definitions
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing  values already stored, used by visibility rules
     * @param  bool  $enforceRequired  false when sending a request back, so required step fields may stay empty
     * @return array<string, mixed>
     */
    public function validate(Collection $definitions, array $input, array $existing = [], bool $enforceRequired = true): array
    {
        $context = [...$existing, ...$input];
        $rules = [];
        $attributes = [];
        $visible = $definitions->filter(fn (object $definition): bool => $this->isVisible($definition, $context));

        foreach ($visible as $definition) {
            $key = 'fields.'.$definition->code;
            $rules[$key] = $this->rulesFor($definition, $enforceRequired);
            $attributes[$key] = $definition->label_ar;
        }

        $validated = Validator::make(['fields' => $input], $rules, [], $attributes)->validate();
        $values = $validated['fields'] ?? [];

        $clean = [];
        foreach ($visible as $definition) {
            $value = $values[$definition->code] ?? null;

            if ($definition->field_type === 'select' && $value !== null
                && ! $this->masterData->isSelectable((int) $value, (int) $definition->master_data_type_id)) {
                throw ValidationException::withMessages([
                    'fields.'.$definition->code => 'القيمة المختارة لم تعد متاحة في '.$definition->label_ar.'.',
                ]);
            }

            $clean[$definition->code] = $this->normalize($definition->field_type, $value);
        }

        return $clean;
    }

    /**
     * Store values. Must run inside the caller's transaction.
     *
     * @param  Collection<int, object>  $definitions
     * @param  array<string, mixed>  $values  output of validate()
     */
    public function save(string $entityType, int $entityId, Collection $definitions, array $values, int $actorId): void
    {
        $now = now();

        foreach ($definitions as $definition) {
            if (! array_key_exists($definition->code, $values)) {
                continue;
            }

            $columns = $this->columnsFor($definition->field_type, $values[$definition->code]);
            $existing = DB::table('custom_field_values')
                ->where('definition_id', $definition->id)
                ->where('entity_id', $entityId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('custom_field_values')->where('id', $existing->id)->update([
                    ...$columns, 'updated_by' => $actorId, 'updated_at' => $now,
                ]);
            } else {
                DB::table('custom_field_values')->insert([
                    ...$columns,
                    'definition_id' => $definition->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Raw stored values keyed by code, for workflow conditions and visibility rules.
     *
     * @return array<string, mixed>
     */
    public function context(string $entityType, int $entityId): array
    {
        return $this->storedValues($entityType, $entityId)
            ->mapWithKeys(fn (object $row): array => [$row->code => $this->rawValue($row)])
            ->all();
    }

    /**
     * Stored values keyed by code for workflow conditions. List fields give the
     * reference value's code (e.g. "available") so conditions stay readable.
     *
     * @return array<string, mixed>
     */
    public function conditionContext(string $entityType, int $entityId): array
    {
        return $this->storedValues($entityType, $entityId)
            ->mapWithKeys(fn (object $row): array => [
                $row->code => $row->field_type === 'select' ? $row->master_code : $this->rawValue($row),
            ])
            ->all();
    }

    /**
     * Human-readable values for display. Sensitive fields are left out unless allowed.
     *
     * @return list<array{code: string, label: string, value: ?string, step_code: ?string}>
     */
    public function display(string $entityType, int $entityId, bool $includeSensitive): array
    {
        return $this->storedValues($entityType, $entityId)
            ->filter(fn (object $row): bool => $includeSensitive || ! $row->is_sensitive)
            ->map(fn (object $row): array => [
                'code' => $row->code,
                'label' => $row->label_ar,
                'value' => $this->displayValue($row),
                'step_code' => $row->step_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function isVisible(object $definition, array $context): bool
    {
        $rule = is_array($definition->visible_when ?? null) ? $definition->visible_when : null;
        if (! $rule || empty($rule['field'])) {
            return true;
        }

        $actual = $context[$rule['field']] ?? null;
        $filled = $actual !== null && $actual !== '' && $actual !== false;

        return match ($rule['operator'] ?? 'equals') {
            'filled' => $filled,
            'empty' => ! $filled,
            'not_equals' => (string) $actual !== (string) ($rule['value'] ?? ''),
            default => (string) $actual === (string) ($rule['value'] ?? ''),
        };
    }

    /**
     * @return list<mixed>
     */
    private function rulesFor(object $definition, bool $enforceRequired): array
    {
        $validation = $definition->validation ?? [];
        $required = $enforceRequired && (bool) $definition->is_required;

        return match ($definition->field_type) {
            'text' => [$required ? 'required' : 'nullable', 'string', 'max:'.min((int) ($validation['max_length'] ?? 255), 1000)],
            'textarea' => [$required ? 'required' : 'nullable', 'string', 'max:'.min((int) ($validation['max_length'] ?? 5000), 20000)],
            'number' => array_values(array_filter([
                $required ? 'required' : 'nullable', 'numeric',
                isset($validation['min']) ? 'min:'.$validation['min'] : null,
                isset($validation['max']) ? 'max:'.$validation['max'] : null,
            ])),
            'date' => [$required ? 'required' : 'nullable', 'date'],
            // A required yes/no field is a confirmation the user must tick.
            'boolean' => [$required ? 'accepted' : 'nullable', 'boolean'],
            'select' => [$required ? 'required' : 'nullable', 'integer'],
            default => ['prohibited'],
        };
    }

    private function normalize(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $type === 'boolean' ? false : null;
        }

        return match ($type) {
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'select' => (int) $value,
            'date' => substr((string) $value, 0, 10),
            default => trim((string) $value),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsFor(string $type, mixed $value): array
    {
        $columns = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_boolean' => null, 'value_master_id' => null];
        $column = match ($type) {
            'number' => 'value_number',
            'date' => 'value_date',
            'boolean' => 'value_boolean',
            'select' => 'value_master_id',
            default => 'value_text',
        };
        $columns[$column] = $value;

        return $columns;
    }

    /**
     * @return Collection<int, object>
     */
    private function storedValues(string $entityType, int $entityId): Collection
    {
        return DB::table('custom_field_values as v')
            ->join('custom_field_definitions as d', 'd.id', '=', 'v.definition_id')
            ->leftJoin('master_data_values as m', 'm.id', '=', 'v.value_master_id')
            ->where('v.entity_type', $entityType)
            ->where('v.entity_id', $entityId)
            ->orderBy('d.sort_order')
            ->orderBy('d.id')
            ->get([
                'd.code', 'd.label_ar', 'd.field_type', 'd.is_sensitive', 'd.step_code',
                'v.value_text', 'v.value_number', 'v.value_date', 'v.value_boolean', 'v.value_master_id',
                'm.name_ar as master_label', 'm.code as master_code',
            ]);
    }

    private function rawValue(object $row): mixed
    {
        return match ($row->field_type) {
            'number' => $row->value_number === null ? null : (float) $row->value_number,
            'date' => $row->value_date === null ? null : substr((string) $row->value_date, 0, 10),
            'boolean' => $row->value_boolean === null ? null : (bool) $row->value_boolean,
            'select' => $row->value_master_id === null ? null : (int) $row->value_master_id,
            default => $row->value_text,
        };
    }

    private function displayValue(object $row): ?string
    {
        $raw = $this->rawValue($row);

        return match (true) {
            $raw === null => null,
            $row->field_type === 'boolean' => $raw ? 'نعم' : 'لا',
            $row->field_type === 'select' => $row->master_label,
            $row->field_type === 'number' => rtrim(rtrim(number_format((float) $raw, 6, '.', ''), '0'), '.'),
            default => (string) $raw,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }
}
