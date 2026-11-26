<?php

namespace App\Services\Hr\Import;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the text of a reference column (department, job title, marital status, bank…) to an approved value.
 *
 * Order: the reviewer's decision in this batch, a remembered alias from earlier imports, then an exact match on
 * the value's name or code after Arabic normalisation. Anything else stays unmapped: the source text is kept and
 * the approved field stays empty. Departments are only matched, never created, and only active ones qualify.
 */
final class ImportReferences
{
    /** @var array<string, list<array{id: int, label: string, keys: list<string>, category_id?: int|null}>> */
    private array $options = [];

    /** @var array<string, array<string, int|null>> */
    private array $aliases = [];

    /**
     * @param  array<string, array<string, int|null>>  $decisions  field => normalized source => target id (null = keep text only)
     */
    public function __construct(private readonly array $decisions = [])
    {
        foreach (DB::table('employee_import_aliases')->get(['field_key', 'source_normalized', 'target_id']) as $alias) {
            $this->aliases[$alias->field_key][$alias->source_normalized] = $alias->target_id === null ? null : (int) $alias->target_id;
        }
    }

    /**
     * @return array{id: int|null, decided: bool, how: string}
     */
    public function resolve(string $field, string $text): array
    {
        $key = EmployeeImportFields::normalize($text);
        $valid = fn (?int $id): ?int => $id !== null && $this->option($field, $id) !== null ? $id : null;

        if (array_key_exists($key, $this->decisions[$field] ?? [])) {
            $id = $this->decisions[$field][$key];

            return ['id' => $valid($id === null ? null : (int) $id), 'decided' => true, 'how' => 'decision'];
        }
        if (array_key_exists($key, $this->aliases[$field] ?? [])) {
            return ['id' => $valid($this->aliases[$field][$key]), 'decided' => true, 'how' => 'alias'];
        }
        foreach ($this->options($field) as $option) {
            if (in_array($key, $option['keys'], true)) {
                return ['id' => $option['id'], 'decided' => false, 'how' => 'exact'];
            }
        }

        return ['id' => null, 'decided' => false, 'how' => 'none'];
    }

    /**
     * Selectable targets of a reference column.
     *
     * @return list<array{id: int, label: string, keys: list<string>, category_id?: int|null}>
     */
    public function options(string $field): array
    {
        if (isset($this->options[$field])) {
            return $this->options[$field];
        }
        $reference = EmployeeImportFields::REFERENCES[$field] ?? null;
        if ($reference === null) {
            return $this->options[$field] = [];
        }
        $today = now()->toDateString();
        $rows = match ($reference['source']) {
            'master' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', $reference['type'])->where('t.is_active', true)->where('v.is_active', true)
                ->where(fn ($query) => $query->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', $today))
                ->where(fn ($query) => $query->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $today))
                ->orderBy('v.sort_order')->orderBy('v.id')->get(['v.id', 'v.name_ar as label', 'v.code']),
            'job_titles' => DB::table('job_titles as t')->join('job_categories as c', 'c.id', '=', 't.job_category_id')
                ->where('t.is_active', true)->where('c.is_active', true)->orderBy('t.sort_order')
                ->get(['t.id', 't.name_ar as label', 't.code', 't.job_category_id as category_id', 'c.name_ar as category']),
            'departments' => DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name as label', 'code']),
            'job_grades' => DB::table('job_grades as g')->join('career_scales as s', 's.id', '=', 'g.career_scale_id')
                ->where('g.is_active', true)->where('s.is_active', true)->orderBy('s.sort_order')->orderBy('g.sort_order')
                ->get(['g.id', 'g.name_ar as label', 'g.code', 's.name_ar as scale']),
            default => collect(),
        };

        return $this->options[$field] = $rows->map(fn (object $row): array => array_filter([
            'id' => (int) $row->id,
            'label' => isset($row->category) ? $row->label.' — '.$row->category : (isset($row->scale) ? $row->scale.' — '.$row->label : $row->label),
            'keys' => array_values(array_unique(array_filter([EmployeeImportFields::normalize($row->label), EmployeeImportFields::normalize((string) $row->code)]))),
            'category_id' => isset($row->category_id) ? (int) $row->category_id : null,
        ], fn ($value) => $value !== null))->values()->all();
    }

    /**
     * @return array{id: int, label: string, keys: list<string>, category_id?: int|null}|null
     */
    public function option(string $field, int $id): ?array
    {
        foreach ($this->options($field) as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }

        return null;
    }
}
