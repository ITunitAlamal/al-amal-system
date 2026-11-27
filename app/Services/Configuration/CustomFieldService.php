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
        'datetime' => 'تاريخ ووقت',
        'boolean' => 'نعم / لا',
        'select' => 'قائمة من الثوابت',
    ];

    public const VISIBILITY_OPERATORS = ['equals', 'not_equals', 'filled', 'empty'];

    /** How a «تاريخ ووقت» value is stored in value_text: the wall-clock time as entered, to the minute. */
    public const DATETIME_FORMAT = 'Y-m-d H:i';

    /** Forms accepted for a «تاريخ ووقت» value: what <input type="datetime-local"> sends, and the stored form. */
    private const DATETIME_INPUT_FORMATS = 'Y-m-d\TH:i,Y-m-d H:i,Y-m-d\TH:i:s,Y-m-d H:i:s';

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
     * Fields hidden by their visibility rule are ignored, and so are fields that belong to other actions
     * ("action_codes" in visible_when) than the one being taken.
     *
     * @param  Collection<int, object>  $definitions
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing  values already stored, used by visibility rules
     * @param  bool  $enforceRequired  false when sending a request back, so required step fields may stay empty
     * @param  ?string  $actionCode  the action being taken on the step; null on a form without actions
     * @return array<string, mixed>
     */
    public function validate(Collection $definitions, array $input, array $existing = [], bool $enforceRequired = true, ?string $actionCode = null): array
    {
        $context = [...$existing, ...$input];
        $rules = [];
        $attributes = [];
        $visible = $definitions->filter(fn (object $definition): bool => $this->isVisible($definition, $context, $actionCode));

        foreach ($visible as $definition) {
            $key = 'fields.'.$definition->code;
            // A field tied to this very action is required with it, also when the action sends the request back.
            $rules[$key] = $this->rulesFor($definition, $enforceRequired || $this->actionCodes($definition) !== []);
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

            // "not_future": a time that already happened (e.g. when the work was actually finished). A few minutes
            // are allowed for a workstation clock that runs ahead of the server.
            if ($definition->field_type === 'datetime' && $clean[$definition->code] !== null && ! empty($definition->validation['not_future'])
                && $clean[$definition->code] > now()->addMinutes(5)->format(self::DATETIME_FORMAT)) {
                throw ValidationException::withMessages([
                    'fields.'.$definition->code => 'لا يُقبل وقت في المستقبل في '.$definition->label_ar.'.',
                ]);
            }
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
     * "type" is the field type, so a page can format a date-time ("Y-m-d H:i") in its own locale.
     *
     * A field filled with one action of a step ("action_codes") is a record of that action, not a property of the
     * request: once a timeline event of the request carries it (see actionRecord()), it is shown under that event
     * and left out here. A value no event carries (a sensitive one, or one stored before events carried them)
     * is still shown here.
     *
     * @return list<array{code: string, label: string, value: ?string, step_code: ?string, type: string}>
     */
    public function display(string $entityType, int $entityId, bool $includeSensitive): array
    {
        $rows = $this->storedValues($entityType, $entityId)
            ->filter(fn (object $row): bool => $includeSensitive || ! $row->is_sensitive);
        $scoped = $rows->filter(fn (object $row): bool => $this->actionCodes((object) ['visible_when' => $this->decode($row->visible_when)]) !== []);
        if ($scoped->isNotEmpty() && $entityType === CustomFieldEntities::AIMS_REQUEST) {
            $onEvents = $this->codesOnEvents($entityId);
            $rows = $rows->reject(fn (object $row): bool => $scoped->contains('code', $row->code) && isset($onEvents[$row->code]));
        }

        return $rows
            ->map(fn (object $row): array => [
                'code' => $row->code,
                'label' => $row->label_ar,
                'value' => $this->displayValue($row),
                'step_code' => $row->step_code,
                'type' => $row->field_type,
            ])
            ->values()
            ->all();
    }

    /**
     * Stored values of the given definitions in the form their inputs take, keyed by code, to pre-fill a form
     * that is opened again. Sensitive values are left out unless allowed; the stored value is kept either way.
     * A field of one action ("action_codes") is never pre-filled: it records that action and is entered anew
     * each time the action is taken (e.g. the worker and the completion time of a second field job).
     *
     * @param  Collection<int, object>  $definitions
     * @return array<string, mixed>
     */
    public function formValues(string $entityType, int $entityId, Collection $definitions, bool $includeSensitive = false): array
    {
        $allowed = $definitions
            ->filter(fn (object $definition): bool => ($includeSensitive || ! $definition->is_sensitive) && $this->actionCodes($definition) === [])
            ->pluck('code')->flip();

        return array_filter(
            array_intersect_key($this->context($entityType, $entityId), $allowed->all()),
            fn ($value): bool => $value !== null,
        );
    }

    /**
     * What an action recorded in its own fields, to keep with its timeline event (workflow_events.metadata.fields):
     * code => {label, value (as displayed), type}. Only fields tied to this action ("action_codes"), only values
     * that were submitted, and never a sensitive field.
     *
     * @param  Collection<int, object>  $definitions  the step's definitions
     * @param  array<string, mixed>  $values  output of validate() for this action
     * @return array<string, array{label: string, value: string, type: string}>
     */
    public function actionRecord(Collection $definitions, array $values, string $actionCode): array
    {
        $record = [];
        foreach ($definitions as $definition) {
            $value = $values[$definition->code] ?? null;
            if ($value === null || $definition->is_sensitive || ! in_array($actionCode, $this->actionCodes($definition), true)) {
                continue;
            }
            $row = (object) [
                ...$this->columnsFor($definition->field_type, $value),
                'field_type' => $definition->field_type,
                'master_label' => $definition->field_type === 'select' ? DB::table('master_data_values')->where('id', (int) $value)->value('name_ar') : null,
            ];
            $shown = $this->displayValue($row);
            if ($shown !== null && $shown !== '') {
                $record[$definition->code] = ['label' => (string) $definition->label_ar, 'value' => $shown, 'type' => (string) $definition->field_type];
            }
        }

        return $record;
    }

    /**
     * The fields an event recorded (metadata.fields written from actionRecord()), in a list for a page.
     *
     * @return list<array{code: string, label: string, value: string, type: string}>
     */
    public static function eventFields(mixed $metadata): array
    {
        $decoded = is_string($metadata) && $metadata !== '' ? json_decode($metadata, true) : $metadata;
        $fields = is_array($decoded) && is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
        $list = [];
        foreach ($fields as $code => $field) {
            if (is_string($code) && is_array($field) && is_scalar($field['value'] ?? null) && is_scalar($field['label'] ?? null)) {
                $list[] = ['code' => $code, 'label' => (string) $field['label'], 'value' => (string) $field['value'], 'type' => (string) ($field['type'] ?? 'text')];
            }
        }

        return $list;
    }

    /**
     * Clears the current value of the given fields of a request, keeping the row (the value columns become null).
     * Used when a pass is cancelled or sent back: what it recorded stays in its timeline events and is no longer
     * presented as the request's current state. Must run inside the caller's transaction.
     *
     * @param  list<string>  $codes
     * @return list<string> the codes that held a value and were cleared
     */
    public function clearValues(string $entityType, int $entityId, string $scopeKey, array $codes, int $actorId): array
    {
        if ($codes === []) {
            return [];
        }
        $rows = DB::table('custom_field_values as v')
            ->join('custom_field_definitions as d', 'd.id', '=', 'v.definition_id')
            ->where('v.entity_type', $entityType)->where('v.entity_id', $entityId)
            ->where('d.entity_type', $entityType)->whereIn('d.scope_key', array_values(array_unique(['', $scopeKey])))
            ->whereIn('d.code', $codes)
            ->where(fn (Builder $query) => $query->whereNotNull('v.value_text')->orWhereNotNull('v.value_number')->orWhereNotNull('v.value_date')
                ->orWhereNotNull('v.value_boolean')->orWhereNotNull('v.value_master_id'))
            ->lockForUpdate()
            ->get(['v.id', 'd.code']);
        if ($rows->isEmpty()) {
            return [];
        }
        DB::table('custom_field_values')->whereIn('id', $rows->pluck('id'))->update([
            ...$this->columnsFor('text', null), 'updated_by' => $actorId, 'updated_at' => now(),
        ]);

        return $rows->pluck('code')->unique()->values()->all();
    }

    /**
     * Codes of the fields that timeline events of a generic request carry.
     *
     * @return array<string, true>
     */
    private function codesOnEvents(int $requestId): array
    {
        $codes = [];
        $metadata = DB::table('workflow_events as e')
            ->join('workflow_instances as i', 'i.id', '=', 'e.workflow_instance_id')
            ->where('i.subject_type', CustomFieldEntities::AIMS_REQUEST)->where('i.subject_id', $requestId)
            ->whereNotNull('e.workflow_transition_id')->whereNotNull('e.metadata')
            ->pluck('e.metadata');
        foreach ($metadata as $json) {
            foreach (self::eventFields($json) as $field) {
                $codes[$field['code']] = true;
            }
        }

        return $codes;
    }

    /**
     * The action codes a field belongs to ("action_codes" in visible_when); empty when it belongs to the whole step.
     *
     * @return list<string>
     */
    public function actionCodes(object $definition): array
    {
        $codes = is_array($definition->visible_when ?? null) ? ($definition->visible_when['action_codes'] ?? null) : null;

        return is_array($codes) ? array_values(array_filter($codes, fn ($code): bool => is_string($code) && $code !== '')) : [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  ?string  $actionCode  the action being taken; a field tied to actions is hidden when none of them is taken
     */
    public function isVisible(object $definition, array $context, ?string $actionCode = null): bool
    {
        $rule = is_array($definition->visible_when ?? null) ? $definition->visible_when : null;
        $actionCodes = $this->actionCodes($definition);
        if ($actionCodes !== [] && ! in_array($actionCode, $actionCodes, true)) {
            return false;
        }
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
            'datetime' => [$required ? 'required' : 'nullable', 'date_format:'.self::DATETIME_INPUT_FORMATS],
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
            // Validated as "Y-m-d", a "T" or a space, then "H:i": stored as DATETIME_FORMAT, seconds dropped.
            'datetime' => str_replace('T', ' ', substr(trim((string) $value), 0, 16)),
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
                'd.code', 'd.label_ar', 'd.field_type', 'd.is_sensitive', 'd.step_code', 'd.visible_when',
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
