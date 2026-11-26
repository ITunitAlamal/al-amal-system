<?php

namespace App\Services\Orphans;

use App\Services\Configuration\GazaGeography;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read side of «إدارة الأيتام والأوصياء»: one filter vocabulary shared by the registry, the dashboard drill-down
 * and the reports, so a chart slice and the filtered list count the same rows.
 *
 * Counting rule: one row per orphan file (orphans.id) or per guardian (orphan_guardians.id). Joins that could fan
 * out (addresses, links) are 1:1 by construction (one open current address per person) or wrapped in EXISTS.
 */
final class RegistryQueries
{
    public const ORPHAN_STATUS = ['draft' => 'مسودة', 'provisional' => 'معتمد مبدئيًا', 'active' => 'معتمد', 'suspended' => 'موقوف', 'archived' => 'مؤرشف', 'ended' => 'منتهٍ'];

    public const ISSUES = [
        'id_missing' => 'رقم الهوية غير متوفر', 'id_invalid' => 'رقم هوية غير صحيح الصيغة', 'id_placeholder' => 'رقم هوية وهمي',
        'no_approved_guardian' => 'لا توجد وصاية معتمدة', 'no_parents' => 'الأب أو الأم غير مربوط', 'no_address' => 'لا يوجد عنوان حالي',
        'no_dob' => 'تاريخ الميلاد غير متوفر', 'no_sex' => 'الجنس غير محدد',
    ];

    public const FILTER_KEYS = ['q', 'first_name', 'father_name', 'grandfather_name', 'family_name', 'governorate', 'locality', 'area', 'sex', 'age_band',
        'class', 'status', 'completeness', 'guardianship', 'displaced', 'education', 'issue', 'portal', 'linked', 'assigned', 'sponsorship', 'benefit', 'sort', 'view', 'layout'];

    /** @return array<string, mixed> */
    public static function filters(array $input): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value)) {
                $value = trim(mb_substr($value, 0, 120));
            }
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    public function orphans(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->orphanQuery($filters)->select(['o.id', 'o.public_id', 'o.reference_no', 'o.status', 'o.completeness', 'o.registration_source', 'o.created_at',
            'p.id as person_id', 'p.full_name', 'p.sex', 'p.date_of_birth', 'p.id_last4', 'p.id_format', 'a.governorate_value_id', 'a.locality_value_id',
            'gv.name_ar as governorate', 'lv.name_ar as locality', 'cv.name_ar as orphan_class']);
        $query->selectSub(DB::table('orphan_guardianships as g')->join('orphan_guardians as gg', 'gg.id', '=', 'g.guardian_id')->join('persons as gp', 'gp.id', '=', 'gg.person_id')
            ->whereColumn('g.orphan_id', 'o.id')->whereIn('g.status', ['claimed', 'pending', 'approved'])->orderByDesc('g.is_primary')->orderBy('g.id')->limit(1)->select('gp.full_name'), 'guardian_name');
        $query->selectSub(DB::table('orphan_guardianships as g')->whereColumn('g.orphan_id', 'o.id')->whereIn('g.status', ['claimed', 'pending', 'approved'])->orderByDesc('g.is_primary')->orderBy('g.id')->limit(1)->select('g.status'), 'guardian_status');
        $this->sort($query, $filters['sort'] ?? 'recent', 'orphans');

        return $query->paginate($perPage)->withQueryString();
    }

    public function guardians(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->guardianQuery($filters)->select(['gg.id', 'gg.public_id', 'gg.reference_no', 'gg.status', 'gg.portal_account_id', 'gg.created_at',
            'p.id as person_id', 'p.full_name', 'p.phone', 'p.id_last4', 'p.id_format', 'gv.name_ar as governorate', 'lv.name_ar as locality']);
        $query->selectSub(DB::table('orphan_guardianships as g')->whereColumn('g.guardian_id', 'gg.id')->whereIn('g.status', ['claimed', 'pending', 'approved'])->selectRaw('count(*)'), 'children_count');
        $query->selectSub(DB::table('orphan_guardianships as g')->whereColumn('g.guardian_id', 'gg.id')->where('g.status', 'approved')->selectRaw('count(*)'), 'approved_count');
        $this->sort($query, $filters['sort'] ?? 'recent', 'guardians');

        return $query->paginate($perPage)->withQueryString();
    }

    /** Base orphan query with every filter applied; used for the list, counts and exports. */
    public function orphanQuery(array $filters): Builder
    {
        $query = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')
            ->leftJoin('registry_addresses as a', fn ($j) => $j->on('a.person_id', '=', 'p.id')->where('a.kind', 'current')->whereNull('a.ends_on'))
            ->leftJoin('master_data_values as gv', 'gv.id', '=', 'a.governorate_value_id')
            ->leftJoin('master_data_values as lv', 'lv.id', '=', 'a.locality_value_id')
            ->leftJoin('master_data_values as cv', 'cv.id', '=', 'o.orphan_class_value_id');
        $this->applyPerson($query, $filters, 'o.reference_no');
        $this->applyGeography($query, $filters, 'a');
        $status = $filters['status'] ?? null;
        $status && array_key_exists($status, self::ORPHAN_STATUS) ? $query->where('o.status', $status) : $query->whereNotIn('o.status', ['ended', 'archived']);
        if (! empty($filters['sex'])) {
            $filters['sex'] === 'unknown' ? $query->whereNull('p.sex') : $query->where('p.sex', $filters['sex']);
        }
        if (! empty($filters['assigned'])) {
            $filters['assigned'] === 'none' ? $query->whereNull('o.assigned_to') : $query->where('o.assigned_to', (int) $filters['assigned']);
        }
        if (! empty($filters['age_band'])) {
            $this->applyAgeBand($query, $filters['age_band']);
        }
        if (! empty($filters['class'])) {
            $filters['class'] === 'unknown' ? $query->whereNull('o.orphan_class_value_id') : $query->where('o.orphan_class_value_id', (int) $filters['class']);
        }
        if (($filters['completeness'] ?? '') === 'complete') {
            $query->where('o.completeness', 100);
        } elseif (($filters['completeness'] ?? '') === 'incomplete') {
            $query->where('o.completeness', '<', 100);
        }
        $live = fn (array $statuses) => fn ($q) => $q->from('orphan_guardianships as g')->whereColumn('g.orphan_id', 'o.id')->whereIn('g.status', $statuses);
        match ($filters['guardianship'] ?? null) {
            'approved' => $query->whereExists($live(['approved'])),
            'unverified' => $query->whereNotExists($live(['approved'])),
            default => null,
        };
        if (($filters['displaced'] ?? '') !== '') {
            $exists = fn ($q) => $q->from('registry_addresses as d')->whereColumn('d.person_id', 'p.id')->where('d.kind', 'displacement')->whereNull('d.ends_on');
            $filters['displaced'] === 'yes' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }
        if (! empty($filters['education'])) {
            $latest = '(select e.status_value_id from education_records e where e.orphan_id = o.id order by e.id desc limit 1)';
            $filters['education'] === 'unknown' ? $query->whereRaw("$latest is null") : $query->whereRaw("$latest = ?", [(int) $filters['education']]);
        }
        match ($filters['issue'] ?? null) {
            'id_missing' => $query->where('p.id_format', 'missing'),
            'id_invalid' => $query->where('p.id_format', 'invalid_format'),
            'id_placeholder' => $query->where('p.id_format', 'placeholder'),
            'no_approved_guardian' => $query->whereNotExists($live(['approved'])),
            'no_parents' => $query->whereRaw('(select count(*) from orphan_parent_links l where l.orphan_id = o.id) < 2'),
            'no_address' => $query->whereNull('a.id'),
            'no_dob' => $query->whereNull('p.date_of_birth'),
            'no_sex' => $query->whereNull('p.sex'),
            default => null,
        };
        $this->applyModuleFilters($query, $filters);

        return $query;
    }

    public function guardianQuery(array $filters): Builder
    {
        $query = DB::table('orphan_guardians as gg')->join('persons as p', 'p.id', '=', 'gg.person_id')
            ->leftJoin('registry_addresses as a', fn ($j) => $j->on('a.person_id', '=', 'p.id')->where('a.kind', 'current')->whereNull('a.ends_on'))
            ->leftJoin('master_data_values as gv', 'gv.id', '=', 'a.governorate_value_id')
            ->leftJoin('master_data_values as lv', 'lv.id', '=', 'a.locality_value_id');
        $this->applyPerson($query, $filters, 'gg.reference_no');
        $this->applyGeography($query, $filters, 'a');
        if (! empty($filters['status']) && in_array($filters['status'], ['active', 'inactive'], true)) {
            $query->where('gg.status', $filters['status']);
        }
        $links = fn (array $statuses) => fn ($q) => $q->from('orphan_guardianships as g')->whereColumn('g.guardian_id', 'gg.id')->whereIn('g.status', $statuses);
        match ($filters['linked'] ?? null) {
            'none' => $query->whereNotExists($links(['claimed', 'pending', 'approved'])),
            'pending' => $query->whereExists($links(['claimed', 'pending'])),
            'approved' => $query->whereExists($links(['approved'])),
            default => null,
        };
        match ($filters['portal'] ?? null) {
            'yes' => $query->whereNotNull('gg.portal_account_id'),
            'no' => $query->whereNull('gg.portal_account_id'),
            default => null,
        };
        match ($filters['issue'] ?? null) {
            'id_missing' => $query->where('p.id_format', 'missing'),
            'id_invalid' => $query->where('p.id_format', 'invalid_format'),
            'id_placeholder' => $query->where('p.id_format', 'placeholder'),
            'no_address' => $query->whereNull('a.id'),
            default => null,
        };

        return $query;
    }

    /** Quick search (exact ID, reference or partial name) and the four-part advanced name. */
    private function applyPerson(Builder $query, array $filters, string $referenceColumn): void
    {
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $clean = Identity::clean($term);
            if ($clean !== '' && ctype_digit($clean)) {
                // An identity number matches exactly, as typed (leading zeros kept); never by prefix.
                $query->where(fn ($q) => $q->where('p.id_hash', Identity::hash($clean))->orWhere('p.phone', $clean));
            } elseif (preg_match('/^(ORP|GRD|HH)-/i', $term)) {
                $query->where($referenceColumn, 'ilike', addcslashes($term, '%_\\').'%');
            } else {
                $normalized = ArabicText::normalize($term);
                $query->where('p.name_search', 'like', '%'.addcslashes($normalized, '%_\\').'%');
            }
        }
        foreach (Persons::NAME_PARTS as $field => $column) {
            if (! empty($filters[$field])) {
                $query->where('p.'.$column, ArabicText::normalize($filters[$field]));
            }
        }
    }

    private function applyGeography(Builder $query, array $filters, string $alias): void
    {
        foreach (['governorate' => 'governorate_value_id', 'locality' => 'locality_value_id', 'area' => 'area_value_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $filters[$key] === 'unknown' ? $query->whereNull($alias.'.'.$column) : $query->where($alias.'.'.$column, (int) $filters[$key]);
            }
        }
    }

    /** Age bands from «ثوابت النظام» (metadata min_age/max_age, full years today); "unknown" = no birth date. */
    private function applyAgeBand(Builder $query, string $band): void
    {
        if ($band === 'unknown') {
            $query->whereNull('p.date_of_birth');

            return;
        }
        $meta = json_decode((string) DB::table('master_data_values')->where('id', (int) $band)->value('metadata'), true) ?: [];
        $query->whereNotNull('p.date_of_birth');
        if (isset($meta['min_age'])) {
            $query->where('p.date_of_birth', '<=', today()->subYears((int) $meta['min_age'])->toDateString());
        }
        if (isset($meta['max_age'])) {
            $query->where('p.date_of_birth', '>', today()->subYears((int) $meta['max_age'] + 1)->toDateString());
        }
    }

    /** Filters that read later modules (sponsorships, benefits, campaigns) once their tables exist. */
    private function applyModuleFilters(Builder $query, array $filters): void
    {
        foreach (app()->tagged('orphans.registry_filters') as $extension) {
            $extension->apply($query, $filters);
        }
    }

    private function sort(Builder $query, string $sort, string $view): void
    {
        match ($sort) {
            'name' => $query->orderBy('p.full_name')->orderBy('p.id'),
            'reference' => $query->orderBy($view === 'orphans' ? 'o.reference_no' : 'gg.reference_no'),
            'age' => $query->orderByRaw('p.date_of_birth DESC NULLS LAST')->orderBy('p.id'),
            default => $query->orderByDesc($view === 'orphans' ? 'o.id' : 'gg.id'),
        };
    }

    /** Filter pickers built from «ثوابت النظام» and the real data, unknown values kept visible. */
    public function options(): array
    {
        return cache()->remember('orphans.registry.options', 600, fn (): array => [
            'governorates' => app(GazaGeography::class)->children(null),
            'age_bands' => $this->values('orphan_age_bands'),
            'classes' => $this->values('orphan_classifications'),
            'education' => $this->values('enrollment_statuses'),
            'kinship' => $this->values('kinship_types'),
            'authority' => $this->values('guardian_authority_kinds'),
            'id_types' => $this->values('identity_document_types'),
            'nationalities' => $this->values('nationalities'),
            'stages' => $this->values('education_stages'),
            'health' => $this->values('health_statuses'),
            'disability' => $this->values('disability_types'),
            'shelter' => $this->values('shelter_types'),
            'tenure' => $this->values('housing_tenures'),
            'employment' => $this->values('employment_statuses'),
            'income_bands' => $this->values('income_bands'),
            'income_sources' => $this->values('income_sources'),
            'economic' => $this->values('economic_statuses'),
            'needs' => $this->values('needs_levels'),
            'documents' => $this->values('orphan_document_types'),
            'issues' => self::ISSUES,
            'statuses' => self::ORPHAN_STATUS,
        ]);
    }

    /** @return list<array{id: int, name: string, code: string}> */
    public function values(string $type): array
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.is_active', true)
            ->orderBy('v.sort_order')->orderBy('v.name_ar')->get(['v.id', 'v.name_ar', 'v.code'])
            ->map(fn ($v): array => ['id' => (int) $v->id, 'name' => $v->name_ar, 'code' => $v->code])->all();
    }

    public static function forgetOptions(): void
    {
        cache()->forget('orphans.registry.options');
    }

    public static function hasTable(string $table): bool
    {
        static $known = [];

        return $known[$table] ??= Schema::hasTable($table);
    }
}
