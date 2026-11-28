<?php

declare(strict_types=1);

namespace App\Services\Orphans;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «روابط الأبوين المشكوك فيها»: father/mother links in the registry that cannot all be true, mostly left by earlier
 * imports that matched a parent's identity number to the wrong person (DB-01). Read only: nothing here changes a
 * record. Each case is corrected from the orphan file through «التعديلات الحساسة», decided by the head of the Orphans
 * department; a father and mother entered in the wrong columns are swapped together on the file and on every sibling
 * file with the same pair (Registry::linkParent); a conflict on another file is refused with that file's reference.
 * Nothing is merged or deleted automatically. Identity numbers are never shown.
 */
final class ParentLinkAnomalies
{
    public const KINDS = [
        'both_relations' => 'شخص مربوط أبًا ليتيم وأمًّا ليتيم آخر',
        'sex_mismatch' => 'جنس الشخص يخالف صفته (أب أنثى أو أم ذكر)',
        'orphan_as_parent' => 'طفل له ملف يتيم مربوط أبًا أو أمًّا ليتيم آخر',
        'birth_after_death' => 'ميلاد اليتيم بعد وفاة الأب المسجلة بأكثر من 300 يوم',
    ];

    private const PER_PAGE = 25;

    private const RELATIONS = ['father' => 'أب', 'mother' => 'أم'];

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $counts[$kind] = $kind === 'birth_after_death' ? $this->lateBirths()->count() : DB::query()->fromSub($this->personIds($kind), 'x')->count();
        }

        return $counts;
    }

    public function page(string $kind): LengthAwarePaginator
    {
        if ($kind === 'birth_after_death') {
            return $this->lateBirths()->orderBy('o.id')
                ->paginate(self::PER_PAGE, ['o.public_id', 'o.reference_no', 'o.status', 'c.full_name as child_name', 'f.full_name as father_name',
                    DB::raw('(c.date_of_birth - e.death_date) AS days_after')])
                ->withQueryString()
                ->through(fn (object $r): array => ['kind' => $kind, 'orphan' => ['public_id' => $r->public_id, 'reference_no' => $r->reference_no, 'name' => $r->child_name, 'status' => RegistryQueries::ORPHAN_STATUS[(string) $r->status] ?? $r->status],
                    'father' => $r->father_name, 'days_after' => (int) $r->days_after]);
        }
        $people = DB::table('persons as p')->whereIn('p.id', $this->personIds($kind))->orderBy('p.id')
            ->paginate(self::PER_PAGE, ['p.id', 'p.public_id', 'p.full_name', 'p.sex', 'p.vital_status'])->withQueryString();
        $ids = collect($people->items())->pluck('id')->all();
        $links = DB::table('orphan_parent_links as l')->join('orphans as o', 'o.id', '=', 'l.orphan_id')->join('persons as c', 'c.id', '=', 'o.person_id')
            ->whereIn('l.person_id', $ids)->orderBy('l.relation')->orderBy('o.id')
            ->get(['l.person_id', 'l.relation', 'l.basis', 'o.public_id', 'o.reference_no', 'o.status', 'o.registration_source', 'c.full_name'])->groupBy('person_id');
        $ownFiles = DB::table('orphans')->whereIn('person_id', $ids)->get(['person_id', 'public_id', 'reference_no'])->keyBy('person_id');

        return $people->through(fn (object $p): array => [
            'kind' => $kind,
            'person' => ['name' => $p->full_name, 'sex' => Persons::SEX_LABELS[(string) $p->sex] ?? 'غير محدد', 'vital' => Persons::VITAL_LABELS[(string) ($p->vital_status ?? 'unknown')] ?? 'غير محدد'],
            'own_file' => isset($ownFiles[$p->id]) ? ['public_id' => $ownFiles[$p->id]->public_id, 'reference_no' => $ownFiles[$p->id]->reference_no] : null,
            'links' => collect($links[$p->id] ?? [])->map(fn (object $l): array => ['relation' => self::RELATIONS[$l->relation] ?? $l->relation, 'by_import' => $l->basis === 'import' || $l->registration_source === 'import',
                'orphan' => ['public_id' => $l->public_id, 'reference_no' => $l->reference_no, 'name' => $l->full_name, 'status' => RegistryQueries::ORPHAN_STATUS[(string) $l->status] ?? $l->status]])->values()->all(),
        ]);
    }

    /** Person ids of one person-level kind. */
    private function personIds(string $kind): Builder
    {
        return match ($kind) {
            'both_relations' => DB::table('orphan_parent_links')->select('person_id')->groupBy('person_id')->havingRaw('count(DISTINCT relation) > 1'),
            'sex_mismatch' => DB::table('orphan_parent_links as l')->join('persons as p', 'p.id', '=', 'l.person_id')->distinct()->select('l.person_id')
                ->where(fn ($q) => $q->where(fn ($f) => $f->where('l.relation', 'father')->where('p.sex', 'female'))->orWhere(fn ($m) => $m->where('l.relation', 'mother')->where('p.sex', 'male'))),
            'orphan_as_parent' => DB::table('orphan_parent_links as l')->join('orphans as o', 'o.person_id', '=', 'l.person_id')->distinct()->select('l.person_id'),
        };
    }

    /** Orphans born more than 300 days after the earliest recorded death of their linked father. */
    private function lateBirths(): Builder
    {
        $deaths = DB::table('person_status_events')->where('status', 'deceased')->whereNotNull('event_date')->groupBy('person_id')->select('person_id', DB::raw('min(event_date) AS death_date'));

        return DB::table('orphans as o')->join('persons as c', 'c.id', '=', 'o.person_id')
            ->join('orphan_parent_links as l', fn ($j) => $j->on('l.orphan_id', '=', 'o.id')->where('l.relation', 'father'))
            ->join('persons as f', 'f.id', '=', 'l.person_id')
            ->joinSub($deaths, 'e', 'e.person_id', '=', 'l.person_id')
            ->whereRaw('c.date_of_birth > e.death_date + 300');
    }
}
