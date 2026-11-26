<?php

namespace App\Services\Orphans\Import;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\RegistryIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read and review side of the staged registry (provisional source claims). Every list is server-side paginated and
 * scoped by the caller's capabilities: identities are masked and health/address data hidden without «sensitive».
 * Decisions are recorded on the staged row only — nothing here changes an approved file, guardianship, portal
 * access, sponsorship, score or payment.
 */
final class RegistryStaging
{
    public const PER_PAGE = 50;

    public const DECISIONS = ['supported' => 'مدعوم بدليل — بانتظار التحقق الرسمي', 'rejected' => 'غير صحيح', 'needs_evidence' => 'يحتاج دليلًا'];

    public const REVIEW_STATUSES = ['pending_review' => 'بانتظار التدقيق', 'in_review' => 'قيد المراجعة', 'needs_followup' => 'يحتاج متابعة', 'reviewed' => 'تمت المراجعة'];

    /** Category fields offered in «التصنيفات»: key => [column, Arabic label, sensitive]. */
    public const CATEGORY_FIELDS = [
        'governorate' => ['governorate_key', 'المحافظة', false],
        'city' => ['city_key', 'المدينة', false],
        'guardian_relation' => ['guardian_relation_key', 'صلة قرابة الوصي', false],
        'school_grade' => ['school_grade_key', 'الصف الدراسي', false],
        'academic_performance' => ['academic_performance_key', 'المستوى الدراسي', false],
        'mother_status' => ['mother_status_key', 'الحالة الاجتماعية للأم', false],
        'mother_education' => ['mother_education_key', 'المستوى التعليمي للأم', false],
        'health_status' => ['health_status_key', 'الحالة الصحية', true],
        'child_sex' => ['child_sex', 'الجنس', false],
        'is_displaced' => ['is_displaced', 'النزوح', false],
        'child_id_status' => ['child_id_status', 'صيغة هوية الطفل', false],
    ];

    /** Filter value meaning «غير محدد» (the source cell is empty). */
    public const MISSING = '__missing';

    /** Governorate filter value: source text not linked to «ثوابت النظام». */
    public const UNMAPPED = '__unmapped';

    /** A staged identity that is one digit repeated (000000000, 111111111 …): a placeholder, not an identity. */
    public static function isPlaceholderId(?string $identity): bool
    {
        return $identity !== null && preg_match('/^(\d)\1+$/', $identity) === 1;
    }

    /** SQL form of isPlaceholderId() (a back-reference regex is ~20× slower in PostgreSQL). */
    private static function placeholderSql(string $column): string
    {
        return "(length($column) > 1 and $column ~ '^[0-9]+$' and replace($column, left($column, 1), '') = '')";
    }

    /** Whose name the main search reads: the child view searches children, the guardian view guardians. */
    public const NAME_SCOPES = [
        'child' => ['r.child_name_search'],
        'guardian' => ['r.guardian_name_search'],
        'any' => ['r.child_name_search', 'r.guardian_name_search', 'r.father_name_search', 'r.mother_name_search'],
    ];

    /** Child list orders («ترتيب»), each ending with the row id so paging is stable. */
    public const CHILD_SORTS = [
        'row' => 'r.source_row_number asc',
        'name' => 'r.child_name_search asc nulls last',
        'youngest' => 'r.child_dob desc nulls last',
        'oldest' => 'r.child_dob asc nulls last',
        'issues' => 'r.issue_count desc',
    ];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit, private readonly RegistryImports $imports) {}

    public function sensitive(User $user): bool
    {
        return $this->access->allows($user, 'sensitive');
    }

    public static function mask(?string $identity): ?string
    {
        if ($identity === null || $identity === '') {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($identity) - 4)).mb_substr($identity, -4);
    }

    /** URL-safe reference of a household or guardian key (base64url). */
    public static function ref(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    public static function unref(string $ref): string
    {
        return (string) base64_decode(strtr($ref, '-_', '+/'), true);
    }

    /** The batch to show: the requested one, else the latest staged one. */
    public function batch(?string $publicId): ?object
    {
        $query = DB::table('orphan_import_batches');

        return $publicId ? $query->where('public_id', $publicId)->first() : $query->where('status', 'staged')->orderByDesc('id')->first();
    }

    /**
     * Counters of «لوحة شؤون الأيتام». Each counter states its denominator: source rows, distinct provisional children,
     * provisional households and approved files are different things and are never added together.
     *
     * @return array<string, mixed>
     */
    public function dashboard(?object $batch): array
    {
        $approved = DB::table('orphan_children')->where('status', 'active')->count();
        $batches = DB::table('orphan_import_batches')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        if (! $batch) {
            return ['approved_files' => $approved, 'batches' => $batches, 'batch' => null];
        }
        $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id);
        $issues = DB::table('orphan_import_issues')->where('batch_id', $batch->id);

        return [
            'batch' => ['public_id' => $batch->public_id, 'file_name' => $batch->file_name, 'status' => $batch->status, 'completed_at' => $batch->completed_at],
            'source_rows' => (clone $rows)->count(),
            'provisional_children' => (clone $rows)->whereNotNull('child_key')->distinct()->count('child_key'),
            'rows_without_usable_id' => (clone $rows)->whereNull('child_key')->count(),
            'approved_files' => $approved,
            'pending_review' => (clone $rows)->where('review_status', 'pending_review')->count(),
            'guardianship_conflicts' => (clone $issues)->whereIn('type', ['guardian_mother_mismatch', 'guardian_is_father', 'multiple_guardians', 'guardian_name_variant'])->where('status', 'open')->distinct()->count('row_id'),
            'provisional_households' => (clone $rows)->whereNotNull('household_key')->distinct()->count('household_key'),
            'claimed_guardians' => (clone $rows)->whereNotNull('guardian_key')->distinct()->count('guardian_key'),
            'batches' => $batches,
            'severity' => (clone $rows)->selectRaw("coalesce(max_severity, 'none') as s, count(*) as n")->groupBy('s')->pluck('n', 's'),
            'issue_types' => (clone $issues)->where('status', 'open')->selectRaw('type, count(*) as n')->groupBy('type')->orderByDesc('n')->get()
                ->map(fn (object $r): array => ['type' => $r->type, 'label' => RegistryIssueTypes::label($r->type), 'severity' => RegistryIssueTypes::severity($r->type), 'count' => (int) $r->n]),
        ];
    }

    /**
     * The staged tier's share of «تحتاج إجراء» on the department home: open issues (information notes excluded)
     * by type with the unassigned part, the reviewer's own items and placeholder identities. Counts only.
     *
     * @return array<string, mixed>|null
     */
    public function workload(User $user): ?array
    {
        $batch = $this->batch(null);
        if (! $batch || ! $this->access->allows($user, 'view')) {
            return null;
        }
        $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id);
        $issues = DB::table('orphan_import_issues')->where('batch_id', $batch->id)->whereIn('status', ['open', 'follow_up'])->where('severity', '<>', 'info');

        return [
            'staged_rows' => (clone $rows)->count(),
            'pending_review' => (clone $rows)->where('review_status', 'pending_review')->count(),
            'high_open' => (clone $issues)->where('severity', 'high')->count(),
            'mine' => (clone $issues)->where('assigned_to', $user->id)->count(),
            'placeholders' => (clone $rows)->whereRaw(self::placeholderSql('child_national_id').' or '.self::placeholderSql('guardian_national_id'))->count(),
            'types' => (clone $issues)->selectRaw('type, severity, count(*) as n, count(*) filter (where assigned_to is null) as unassigned')->groupBy('type', 'severity')
                ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderByDesc('n')->get()
                ->map(fn (object $t): array => ['type' => $t->type, 'label' => RegistryIssueTypes::label($t->type), 'severity' => $t->severity, 'count' => (int) $t->n,
                    'unassigned' => (int) $t->unassigned, 'recommendation' => RegistryIssueTypes::TYPES[$t->type][2] ?? '']),
        ];
    }

    /**
     * Staged child rows («الأيتام») with the shared filters; stable order by Excel row number.
     *
     * @param  array<string, mixed>  $filters
     */
    public function children(User $user, object $batch, array $filters): array
    {
        $page = $this->filtered($user, $batch, $filters)->orderByRaw(self::CHILD_SORTS[$filters['sort'] ?? 'row'] ?? self::CHILD_SORTS['row'])->orderBy('r.id')
            ->select(['r.id', 'r.source_row_number', 'r.child_name', 'r.child_national_id', 'r.child_id_status', 'r.child_sex', 'r.child_dob', 'r.guardian_name',
                'r.guardian_relation_key', 'r.governorate_key', 'r.city_key', 'r.household_key', 'r.guardian_key', 'r.issue_count', 'r.max_severity', 'r.review_status', 'r.assigned_to',
                'r.guardianship_decision'])
            ->paginate(self::PER_PAGE);
        $sensitive = $this->sensitive($user);
        $page->getCollection()->transform(fn (object $r): array => [
            'id' => $r->id, 'row' => $r->source_row_number, 'child_name' => $r->child_name, 'national_id' => $sensitive ? $r->child_national_id : self::mask($r->child_national_id),
            'id_status' => $r->child_id_status, 'sex' => $r->child_sex, 'dob' => $r->child_dob, 'age' => $r->child_dob ? (int) floor(CarbonImmutable::parse($r->child_dob)->diffInYears(now(config('orphans.timezone')))) : null,
            'guardian_name' => $r->guardian_name, 'relation' => $r->guardian_relation_key, 'governorate' => $r->governorate_key, 'city' => $r->city_key,
            'household' => $r->household_key ? substr(hash('sha256', $r->household_key), 0, 10) : null, 'household_key_ref' => $r->household_key ? self::ref($r->household_key) : null,
            'guardian_ref' => $r->guardian_key ? self::ref($r->guardian_key) : null,
            'issues' => $r->issue_count, 'severity' => $r->max_severity, 'review_status' => $r->review_status, 'assigned_to' => $r->assigned_to,
            'claim' => $r->guardianship_decision ?? 'pending', 'placeholder_id' => self::isPlaceholderId($r->child_national_id),
        ]);

        return $this->paged($page);
    }

    /**
     * Filter choices taken from the staged data itself (never a list in code): governorates and the cities seen
     * under each, the mother's status and guardian relation values, with row counts. A governorate value mapped in
     * «التصنيفات» shows the «ثوابت النظام» name next to the source text.
     *
     * @return array<string, mixed>
     */
    public function options(User $user, object $batch): array
    {
        $this->access->assert($user, 'view');
        $sensitive = $this->sensitive($user);
        // Corrections affect filter choices while the original source remains immutable.
        $key = 'orphan-staging-options:'.$batch->id.':'.(DB::table('orphan_import_rows')->where('batch_id', $batch->id)->max('updated_at') ?? '0').':'.(DB::table('orphan_import_aliases')->max('updated_at') ?? '0').':'.($sensitive ? 's' : 'v');

        return Cache::remember($key, now()->addMinutes(30), function () use ($batch, $sensitive): array {
            $rows = fn () => DB::table('orphan_import_rows')->where('batch_id', $batch->id);
            $aliases = DB::table('orphan_import_aliases as a')->join('master_data_values as v', 'v.id', '=', 'a.master_data_value_id')
                ->where('a.field_key', 'governorate')->pluck('v.name_ar', 'a.source_key');
            $pairs = $rows()->selectRaw('governorate_key as g, city_key as c, count(*) as n')->groupBy('g', 'c')->get();
            $value = fn (?string $v): string => $v ?? self::MISSING;
            $counted = fn (string $column) => $rows()->selectRaw("$column as v, count(*) as n")->groupBy('v')->orderByDesc('n')->limit(150)->get()
                ->map(fn (object $r): array => ['value' => $value($r->v), 'label' => $r->v ?? 'غير محدد', 'count' => (int) $r->n])->values();

            return [
                'governorates' => $pairs->groupBy(fn (object $p): string => $value($p->g))->map(fn ($group, string $g): array => [
                    'value' => $g, 'label' => $g === self::MISSING ? 'غير محدد' : $g, 'master' => $aliases[$g] ?? null, 'count' => (int) $group->sum('n'),
                ])->sortByDesc('count')->values(),
                'cities' => $pairs->map(fn (object $p): array => ['governorate' => $value($p->g), 'value' => $value($p->c), 'label' => $p->c ?? 'غير محدد', 'count' => (int) $p->n])
                    ->sortByDesc('count')->values(),
                'mother_statuses' => $counted('mother_status_key'),
                'relations' => $counted('guardian_relation_key'),
                'grades' => $counted('school_grade_key'),
                'performances' => $counted('academic_performance_key'),
                'health' => $sensitive ? $counted('health_status_key') : [],
                'sorts' => array_keys(self::CHILD_SORTS),
            ];
        });
    }

    /** Provisional households («الأسر»): father+mother ID pairs, never merged on a shared guardian. */
    public function households(User $user, object $batch, array $filters): array
    {
        $base = $this->filtered($user, $batch, $filters)->whereNotNull('r.household_key');
        // Two steps: order/page on (household, guardian) pairs, then aggregate only the 50 households of the page.
        $order = DB::query()->fromSub((clone $base)->select(['r.household_key', 'r.guardian_key'])->groupBy('r.household_key', 'r.guardian_key'), 'x')
            ->groupBy('household_key')->select(['household_key', DB::raw('count(guardian_key) as k')]);
        if (! empty($filters['multiple_guardians'])) {
            $order->havingRaw('count(guardian_key) > 1');
        }
        $page = DB::query()->fromSub($order, 'h')->orderByDesc('k')->orderBy('household_key')->paginate(self::PER_PAGE);
        $keys = $page->getCollection()->pluck('household_key');
        $details = (clone $base)->whereIn('r.household_key', $keys)->groupBy('r.household_key')
            ->select(['r.household_key', DB::raw('min(r.father_name) as father_name'), DB::raw('min(r.mother_name) as mother_name'), DB::raw('count(*) as rows'),
                DB::raw('count(distinct r.child_key) as children'), DB::raw('count(distinct r.guardian_key) as guardians'), DB::raw('min(r.governorate_key) as governorate'),
                DB::raw('sum(r.issue_count) as issues')])->get()->keyBy('household_key');
        $page->setCollection($keys->map(fn (string $key): array => ['ref' => self::ref($key), 'father_name' => $details[$key]->father_name, 'mother_name' => $details[$key]->mother_name,
            'rows' => (int) $details[$key]->rows, 'children' => (int) $details[$key]->children, 'guardians' => (int) $details[$key]->guardians,
            'governorate' => $details[$key]->governorate, 'issues' => (int) $details[$key]->issues]));

        return $this->paged($page);
    }

    /** One provisional household: «الأسرة ← الأب والأم ← الأطفال ← الأوصياء المدّعى بهم». */
    public function household(User $user, object $batch, string $ref): array
    {
        $key = self::unref($ref);
        $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->where('household_key', $key)->orderBy('child_dob')->get();
        abort_if($rows->isEmpty(), 404);
        $sensitive = $this->sensitive($user);
        $first = $rows->first();

        return [
            'father' => ['name' => $first->father_name, 'national_id' => $sensitive ? $first->father_national_id : self::mask($first->father_national_id), 'death_date' => $first->father_death_date],
            'mother' => ['name' => $first->mother_name, 'national_id' => $sensitive ? $first->mother_national_id : self::mask($first->mother_national_id), 'status' => $first->mother_status_key],
            'children' => $rows->map(fn (object $r): array => $this->brief($r, $sensitive))->values(),
            'guardians' => $rows->groupBy(fn (object $r): string => (string) ($r->guardian_key ?? 'unusable:'.$r->guardian_national_id))->map(fn ($group, string $key): array => [
                'ref' => $group->first()->guardian_key ? self::ref($group->first()->guardian_key) : null,
                'national_id' => $sensitive ? $group->first()->guardian_national_id : self::mask($group->first()->guardian_national_id),
                'names' => $group->pluck('guardian_name')->filter()->unique()->values(), 'relations' => $group->pluck('guardian_relation_key')->filter()->unique()->values(),
                'children' => $group->count(), 'status' => 'claimed',
            ])->values(),
        ];
    }

    /** Claimed guardians («الأوصياء»): each guardian ID once, with its households and children counts. */
    public function guardians(User $user, object $batch, array $filters): array
    {
        $base = $this->filtered($user, $batch, $filters)->whereNotNull('r.guardian_key');
        // «أكثر من أسرة» / «أسماء مختلفة»: count(distinct x) > 1 is min(x) <> max(x), which needs no sort.
        foreach (['multiple_households' => 'r.household_key', 'name_variants' => 'r.guardian_name_search'] as $flag => $column) {
            if (! empty($filters[$flag])) {
                $base->whereIn('r.guardian_key', (clone $base)->groupBy('r.guardian_key')->havingRaw("min($column) <> max($column)")->select('r.guardian_key'));
            }
        }
        // Two steps: order/page on light (guardian, value) pairs, then aggregate only the 50 guardians of the page.
        $sort = $filters['sort'] ?? 'households';
        if ($sort === 'name') {
            $order = (clone $base)->groupBy('r.guardian_key')->select(['r.guardian_key', DB::raw('min(r.guardian_name) as k')]);
        } else {
            $column = $sort === 'children' ? 'r.child_key' : 'r.household_key';
            $order = DB::query()->fromSub((clone $base)->select(['r.guardian_key', DB::raw("$column as v")])->groupBy('r.guardian_key', DB::raw($column)), 'x')
                ->groupBy('guardian_key')->select(['guardian_key', DB::raw('count(v) as k')]);
        }
        $page = DB::query()->fromSub($order, 'g')->orderByRaw($sort === 'name' ? 'k asc nulls last' : 'k desc')->orderBy('guardian_key')->paginate(self::PER_PAGE);
        $keys = $page->getCollection()->pluck('guardian_key');
        $details = (clone $base)->whereIn('r.guardian_key', $keys)->groupBy('r.guardian_key')
            ->select(['r.guardian_key', DB::raw('min(r.guardian_name) as name'), DB::raw('count(distinct r.guardian_name_search) as name_variants'),
                DB::raw('count(distinct r.household_key) as households'), DB::raw('count(distinct r.child_key) as children'), DB::raw('count(*) as rows'),
                DB::raw("string_agg(distinct r.guardian_relation_key, '، ') as relations"), DB::raw('min(r.governorate_key) as governorate'),
                DB::raw("count(*) filter (where r.guardianship_decision = 'supported') as supported"), DB::raw("count(*) filter (where r.guardianship_decision = 'rejected') as rejected"),
                DB::raw("count(*) filter (where r.guardianship_decision = 'needs_evidence') as needs_evidence")])->get()->keyBy('guardian_key');
        $page->setCollection($keys->map(fn (string $key): object => $details[$key]));
        $sensitive = $this->sensitive($user);
        // Approved links live in the registry (orphan_guardianships), matched by the identity hash — never by name.
        $hashes = $page->getCollection()->mapWithKeys(fn (object $g): array => [$g->guardian_key => RegistryIdentity::hash($g->guardian_key)]);
        $approved = DB::table('orphan_guardianships as og')->join('guardians as gd', 'gd.id', '=', 'og.guardian_id')->whereNull('og.ended_reason')->where('og.status', 'approved')
            ->whereIn('gd.national_id_hash', $hashes->values())->selectRaw('gd.national_id_hash as h, count(*) as n')->groupBy('gd.national_id_hash')->pluck('n', 'h');
        $page->getCollection()->transform(fn (object $g): array => ['ref' => self::ref($g->guardian_key), 'name' => $g->name,
            'national_id' => $sensitive ? $g->guardian_key : self::mask($g->guardian_key), 'name_variants' => (int) $g->name_variants,
            'households' => (int) $g->households, 'children' => (int) $g->children, 'rows' => (int) $g->rows, 'relations' => $g->relations, 'governorate' => $g->governorate,
            'claims' => ['supported' => (int) $g->supported, 'rejected' => (int) $g->rejected, 'needs_evidence' => (int) $g->needs_evidence,
                'pending' => (int) $g->rows - (int) $g->supported - (int) $g->rejected - (int) $g->needs_evidence],
            'approved_links' => (int) ($approved[$hashes[$g->guardian_key]] ?? 0)]);

        return $this->paged($page);
    }

    /** The relationship tree «الوصي ← الأسر المرتبطة به ← الأيتام», every node a provisional claim. */
    public function guardianTree(User $user, object $batch, string $ref): array
    {
        $key = self::unref($ref);
        $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->where('guardian_key', $key)->orderBy('household_key')->orderBy('child_dob')->get();
        abort_if($rows->isEmpty(), 404);
        $sensitive = $this->sensitive($user);

        return [
            'guardian' => ['national_id' => $sensitive ? $key : self::mask($key), 'names' => $rows->pluck('guardian_name')->filter()->unique()->values(),
                'relations' => $rows->pluck('guardian_relation_key')->filter()->countBy()->all(), 'status' => 'claimed'],
            'households' => $rows->groupBy(fn (object $r): string => (string) ($r->household_key ?? '—'))->map(fn ($group, string $household): array => [
                'ref' => $household !== '—' ? self::ref($household) : null,
                'father_name' => $group->first()->father_name, 'mother_name' => $group->first()->mother_name,
                'other_guardians' => $household !== '—' ? DB::table('orphan_import_rows')->where('batch_id', $batch->id)->where('household_key', $household)
                    ->whereNotNull('guardian_key')->where('guardian_key', '<>', $key)->distinct()->count('guardian_key') : 0,
                'children' => $group->map(fn (object $r): array => $this->brief($r, $sensitive))->values(),
            ])->values(),
        ];
    }

    /**
     * «التصنيفات»: distinct source values of one field with counts of source rows AND distinct provisional children,
     * the reviewed rows among them, and the alias mapped to «ثوابت النظام» when one exists.
     */
    public function categories(User $user, object $batch, string $field, array $filters): array
    {
        abort_unless(isset(self::CATEGORY_FIELDS[$field]), 404);
        [$column, $label, $sensitive] = self::CATEGORY_FIELDS[$field];
        abort_if($sensitive && ! $this->sensitive($user), 403, 'هذا التصنيف يحتاج صلاحية الاطلاع على البيانات الحساسة.');
        $values = $this->filtered($user, $batch, $filters)->groupBy("r.$column")
            ->selectRaw("r.$column as value, count(*) as rows, count(distinct r.child_key) as children, count(*) filter (where r.review_status = 'reviewed') as reviewed")
            ->orderByDesc('rows')->limit(300)->get();
        $aliases = DB::table('orphan_import_aliases as a')->join('master_data_values as v', 'v.id', '=', 'a.master_data_value_id')
            ->where('a.field_key', $field)->pluck('v.name_ar', 'a.source_key');
        $type = RegistrySheet::COLUMNS[$field][4] ?? null;

        return [
            'field' => $field, 'label' => $label, 'master_type' => $type,
            'options' => $type ? DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.is_active', true)
                ->orderBy('v.sort_order')->get(['v.id', 'v.name_ar']) : [],
            'values' => $values->map(fn (object $v): array => ['value' => is_bool($v->value) ? ($v->value ? 'نعم' : 'لا') : $v->value, 'raw' => $v->value,
                'rows' => (int) $v->rows, 'children' => (int) $v->children, 'reviewed' => (int) $v->reviewed, 'alias' => is_string($v->value) ? ($aliases[$v->value] ?? null) : null]),
            'denominators' => 'عدد الصفوف يعدّ كل صف في الكشف؛ عدد الأطفال يعدّ هويات الطفل المميزة متوافقة الصيغة فقط؛ لا يُجمع أحدهما مع الآخر.',
        ];
    }

    /** Maps one source value variant to a «ثوابت النظام» value (the source text is kept as is). */
    public function alias(User $user, string $field, string $sourceKey, ?int $valueId): void
    {
        $this->access->assert($user, 'imports');
        abort_unless(isset(RegistrySheet::COLUMNS[$field]) && RegistrySheet::COLUMNS[$field][4] !== null, 422, 'هذا الحقل لا يرتبط بثوابت النظام.');
        $before = DB::table('orphan_import_aliases')->where('field_key', $field)->where('source_key', $sourceKey)->first();
        if ($valueId === null) {
            DB::table('orphan_import_aliases')->where('field_key', $field)->where('source_key', $sourceKey)->delete();
        } else {
            $valid = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('v.id', $valueId)->where('t.code', RegistrySheet::COLUMNS[$field][4])->exists();
            abort_unless($valid, 422, 'القيمة ليست من ثوابت هذا الحقل.');
            DB::table('orphan_import_aliases')->updateOrInsert(['field_key' => $field, 'source_key' => $sourceKey],
                ['master_data_value_id' => $valueId, 'updated_by' => $user->id, 'created_by' => $before->created_by ?? $user->id, 'updated_at' => now(), 'created_at' => $before->created_at ?? now()]);
        }
        $this->audit->recordRow('orphans.import.alias', 'orphan_import_aliases', $before->id ?? 0, $before ? (array) $before : null, ['field' => $field, 'source_key' => $sourceKey, 'value_id' => $valueId], (int) $user->id, request()?->ip());
    }

    /** «تدقيق البيانات»: the exception inbox, highest risk first. */
    public function issues(User $user, object $batch, array $filters): array
    {
        $query = DB::table('orphan_import_issues as i')->join('orphan_import_rows as r', 'r.id', '=', 'i.row_id')->where('i.batch_id', $batch->id)
            ->where('i.status', $filters['status'] ?? 'open');
        foreach (['type', 'severity'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('i.'.$field, $filters[$field]);
            }
        }
        if (empty($filters['severity']) && empty($filters['type']) && empty($filters['include_info'])) {
            $query->where('i.severity', '<>', 'info');
        }
        if (($filters['assigned'] ?? '') === 'me') {
            $query->where('i.assigned_to', $user->id);
        } elseif (($filters['assigned'] ?? '') === 'none') {
            $query->whereNull('i.assigned_to');
        }
        // Search and row filters of the toolbar narrow the issues to the matching rows (they were ignored before).
        $rowFilters = array_filter(array_intersect_key($filters, array_flip(['q', 'national_id', 'governorate', 'city', 'sex', 'displaced', 'age_min', 'age_max', 'age_unknown'])),
            fn ($v): bool => $v !== null && $v !== '');
        if ($rowFilters !== []) {
            $query->whereIn('i.row_id', $this->filtered($user, $batch, $rowFilters)->select('r.id'));
        }
        $page = $query->orderByRaw("CASE i.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END")->orderBy('i.id')
            ->select(['i.id', 'i.type', 'i.reason', 'i.severity', 'i.status', 'i.assigned_to', 'i.details', 'r.id as row_id', 'r.source_row_number', 'r.child_name', 'r.child_national_id'])
            ->paginate(self::PER_PAGE);
        $sensitive = $this->sensitive($user);
        $names = DB::table('users')->whereIn('id', $page->getCollection()->pluck('assigned_to')->filter()->unique())->pluck('name', 'id');
        $page->getCollection()->transform(fn (object $i): array => ['id' => $i->id, 'type' => $i->type, 'label' => RegistryIssueTypes::label($i->type), 'reason' => $i->reason,
            'severity' => $i->severity, 'status' => $i->status, 'assigned_to' => $i->assigned_to, 'assignee' => $names[$i->assigned_to] ?? null,
            'recommendation' => RegistryIssueTypes::TYPES[$i->type][2] ?? '', 'row_id' => $i->row_id, 'row' => $i->source_row_number, 'child_name' => $i->child_name,
            'national_id' => $sensitive ? $i->child_national_id : self::mask($i->child_national_id)]);

        return $this->paged($page);
    }

    /** Safe bulk action: assign issues (and their rows) to a reviewer who holds «review». Never confirms anything. */
    public function assign(User $user, object $batch, array $issueIds, ?int $assigneeId): int
    {
        $this->access->assert($user, 'review');
        if ($assigneeId !== null && ! in_array($assigneeId, $this->reviewers(), true)) {
            throw ValidationException::withMessages(['assignee' => 'المكلف يجب أن يحمل صلاحية المراجعة في القسم.']);
        }
        $ids = DB::table('orphan_import_issues')->where('batch_id', $batch->id)->whereIn('id', array_slice($issueIds, 0, 500))->whereIn('status', ['open', 'follow_up'])->pluck('id');
        DB::transaction(function () use ($ids, $assigneeId, $batch, $user): void {
            DB::table('orphan_import_issues')->whereIn('id', $ids)->update(['assigned_to' => $assigneeId, 'updated_at' => now()]);
            $rowIds = DB::table('orphan_import_issues')->whereIn('id', $ids)->distinct()->pluck('row_id');
            DB::table('orphan_import_rows')->whereIn('id', $rowIds)->update(['assigned_to' => $assigneeId, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.assigned', 'orphan_import_batches', $batch->id, null, ['issues' => $ids->count(), 'assignee' => $assigneeId], (int) $user->id, request()?->ip());
        });

        return $ids->count();
    }

    /** Resolves, dismisses or marks one issue for follow-up, with a documented note. */
    public function settleIssue(User $user, int $issueId, string $status, string $note): void
    {
        $this->access->assert($user, 'review');
        if (! in_array($status, ['resolved', 'dismissed', 'follow_up', 'open'], true)) {
            abort(422);
        }
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'اكتب ملاحظة القرار (10 أحرف على الأقل).']);
        }
        DB::transaction(function () use ($user, $issueId, $status, $note): void {
            $issue = DB::table('orphan_import_issues')->lockForUpdate()->find($issueId);
            abort_unless($issue, 404);
            DB::table('orphan_import_issues')->where('id', $issueId)->update(['status' => $status, 'resolution_note' => $note,
                'resolved_by' => in_array($status, ['resolved', 'dismissed'], true) ? $user->id : null, 'resolved_at' => in_array($status, ['resolved', 'dismissed'], true) ? now() : null, 'updated_at' => now()]);
            $open = DB::table('orphan_import_issues')->where('row_id', $issue->row_id)->whereIn('status', ['open', 'follow_up'])->where('severity', '<>', 'info')->count();
            DB::table('orphan_import_rows')->where('id', $issue->row_id)->update(['review_status' => $status === 'follow_up' ? 'needs_followup' : ($open === 0 ? 'reviewed' : 'in_review'), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.issue', 'orphan_import_issues', $issueId, ['status' => $issue->status], ['status' => $status, 'note' => $note], (int) $user->id, request()?->ip());
        });
    }

    /**
     * One staged row («ملف اليتيم» of the source): provenance, the other rows with the same child ID (compare), the
     * household and the claimed guardian, issues, decisions and the approved record it may match. Logged as a read.
     *
     * @return array<string, mixed>
     */
    public function row(User $user, int $id): array
    {
        $this->access->assert($user, 'view');
        $row = DB::table('orphan_import_rows')->find($id);
        abort_unless($row, 404);
        $sensitive = $this->sensitive($user);
        $this->access->logRead($user, 'import_row', $id);
        $raw = $this->imports->raw($row);
        $effective = RegistryCorrections::values($row);
        $corrections = app(RegistryCorrections::class);
        $editable = $corrections->available() ? $corrections->fields($user) : [];
        $columns = [];
        foreach (RegistrySheet::COLUMNS as $key => [$letter, $header, $type, $protected]) {
            $value = $effective[$key] ?? '';
            $original = $raw[$key] ?? '';
            if (! $sensitive && ($protected || $type === 'national_id' || $key === 'health_status')) {
                $value = $type === 'national_id' ? (string) self::mask($value) : ($value === '' ? '' : 'محجوب');
                $original = $type === 'national_id' ? (string) self::mask($original) : ($original === '' ? '' : 'محجوب');
            }
            $columns[] = ['key' => $key, 'letter' => $letter, 'header' => $header, 'value' => $value, 'original' => $original, 'type' => $type, 'editable' => isset($editable[$key]), 'flag' => json_decode((string) $row->parse_flags, true)[$key] ?? null, 'protected' => $protected];
        }
        $same = $row->child_key ? DB::table('orphan_import_rows')->where('batch_id', $row->batch_id)->where('child_key', $row->child_key)->where('id', '<>', $id)->get() : collect();
        $decisionNames = DB::table('users')->whereIn('id', array_filter([$row->identity_decided_by, $row->household_decided_by, $row->guardianship_decided_by, $row->assigned_to]))->pluck('name', 'id');
        $existing = $row->child_key ? DB::table('orphan_children')->where('national_id_hash', RegistryIdentity::hash($row->child_key))->first(['public_id', 'full_name', 'date_of_birth', 'verification_status', 'status']) : null;

        return [
            'id' => $row->id, 'row' => $row->source_row_number, 'batch' => DB::table('orphan_import_batches')->where('id', $row->batch_id)->value('public_id'),
            'columns' => $columns, 'sensitive' => $sensitive, 'review_status' => $row->review_status, 'assigned_to' => $decisionNames[$row->assigned_to] ?? null,
            'can_edit' => $editable !== [], 'correction_version' => (int) ($row->correction_version ?? 0),
            'statuses' => [
                'personal' => $row->identity_decision ?? 'pending', 'household' => $row->household_decision ?? 'pending',
                'claimed_guardian' => $row->guardianship_decision ?? 'pending', 'legal_guardianship' => 'not_verified', 'portal' => 'none',
            ],
            'decisions' => collect(['identity', 'household', 'guardianship'])->mapWithKeys(fn (string $kind): array => [$kind => [
                'decision' => $row->{$kind.'_decision'}, 'note' => $row->{$kind.'_note'}, 'by' => $decisionNames[$row->{$kind.'_decided_by'}] ?? null, 'at' => $row->{$kind.'_decided_at'},
            ]]),
            'issues' => DB::table('orphan_import_issues')->where('row_id', $id)->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END")->get()
                ->map(fn (object $i): array => ['id' => $i->id, 'type' => $i->type, 'label' => RegistryIssueTypes::label($i->type), 'reason' => $i->reason, 'severity' => $i->severity,
                    'status' => $i->status, 'note' => $i->resolution_note, 'recommendation' => RegistryIssueTypes::TYPES[$i->type][2] ?? '']),
            'same_child' => $same->map(fn (object $r): array => $this->brief($r, $sensitive) + ['father_name' => $r->father_name, 'mother_name' => $r->mother_name])->values(),
            'household_ref' => $row->household_key ? self::ref($row->household_key) : null,
            'guardian_ref' => $row->guardian_key ? self::ref($row->guardian_key) : null,
            'siblings' => $row->household_key ? DB::table('orphan_import_rows')->where('batch_id', $row->batch_id)->where('household_key', $row->household_key)->where('id', '<>', $id)->count() : 0,
            'placeholder_ids' => array_keys(array_filter(['اليتيم' => $row->child_national_id, 'الوصي' => $row->guardian_national_id, 'الأب' => $row->father_national_id,
                'الأم' => $row->mother_national_id], fn (?string $id): bool => self::isPlaceholderId($id))),
            'existing' => $existing ? ['public_id' => $existing->public_id, 'name' => $existing->full_name, 'dob' => $existing->date_of_birth, 'status' => $existing->status] : null,
            'history' => DB::table('audit_logs')->where('subject_type', 'orphan_import_rows')->where('subject_id', $id)->orderByDesc('id')->limit(30)->get(['action', 'actor_id', 'created_at']),
        ];
    }

    /**
     * One decision on one staged row: identity, household membership or the guardian claim, each on its own path,
     * with evidence notes. A «supported» guardian claim is NOT a legal guardianship and grants no portal access.
     */
    public function decide(User $user, int $id, string $kind, string $decision, string $note): void
    {
        $this->access->assert($user, 'verify');
        abort_unless(in_array($kind, ['identity', 'household', 'guardianship'], true) && isset(self::DECISIONS[$decision]), 422);
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'اذكر الدليل أو مرجع الوثيقة (10 أحرف على الأقل).']);
        }
        DB::transaction(function () use ($user, $id, $kind, $decision, $note): void {
            $row = DB::table('orphan_import_rows')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            DB::table('orphan_import_rows')->where('id', $id)->update([$kind.'_decision' => $decision, $kind.'_note' => $note, $kind.'_decided_by' => $user->id,
                $kind.'_decided_at' => now(), 'review_status' => $row->review_status === 'pending_review' ? 'in_review' : $row->review_status, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.decision', 'orphan_import_rows', $id, [$kind => $row->{$kind.'_decision'}], [$kind => $decision, 'note' => $note], (int) $user->id, request()?->ip());
        });
    }

    /** @return list<int> */
    public function reviewers(): array
    {
        return $this->access->departmentUsersWith('review');
    }

    /** Export rows (same filters). IDs masked and protected columns left out unless explicitly unmasked by a «sensitive» holder. */
    public function exportRows(User $user, object $batch, array $filters, bool $unmask): array
    {
        $this->access->assert($user, 'review');
        $unmask = $unmask && $this->sensitive($user);
        $rows = $this->filtered($user, $batch, $filters)->orderBy('r.source_row_number')->limit(20001)
            ->get(['r.source_row_number', 'r.child_name', 'r.child_national_id', 'r.child_id_status', 'r.child_sex', 'r.child_dob', 'r.father_name', 'r.mother_name',
                'r.guardian_name', 'r.guardian_national_id', 'r.guardian_relation_key', 'r.governorate_key', 'r.city_key', 'r.is_displaced', 'r.school_grade_key',
                'r.academic_performance_key', 'r.issue_count', 'r.max_severity', 'r.review_status']);
        abort_if($rows->count() > 20000, 422, 'النتائج أكثر من 20000 صف؛ ضيّق التصفية قبل التصدير.');
        $this->audit->recordRow('orphans.import.export', 'orphan_import_batches', $batch->id, null, ['rows' => $rows->count(), 'unmasked' => $unmask, 'filters' => $filters], (int) $user->id, request()?->ip());
        $sex = ['male' => 'ذكر', 'female' => 'أنثى'];

        return [
            'columns' => [['صف الكشف', 'qty'], ['اسم الطفل', 'text'], ['هوية الطفل', 'text'], ['صيغة الهوية', 'text'], ['الجنس', 'text'], ['تاريخ الميلاد', 'date'],
                ['الأب', 'text'], ['الأم', 'text'], ['الوصي المدّعى', 'text'], ['هوية الوصي', 'text'], ['الصلة', 'text'], ['المحافظة', 'text'], ['المدينة', 'text'],
                ['نازح', 'text'], ['الصف الدراسي', 'text'], ['المستوى الدراسي', 'text'], ['عدد الملاحظات', 'qty'], ['الأولوية', 'text'], ['حالة التدقيق', 'text']],
            'rows' => $rows->map(fn (object $r): array => [(string) $r->source_row_number, (string) $r->child_name, (string) ($unmask ? $r->child_national_id : self::mask($r->child_national_id)),
                (string) $r->child_id_status, $sex[$r->child_sex] ?? 'غير محدد', (string) $r->child_dob, (string) $r->father_name, (string) $r->mother_name, (string) $r->guardian_name,
                (string) ($unmask ? $r->guardian_national_id : self::mask($r->guardian_national_id)), (string) $r->guardian_relation_key, (string) $r->governorate_key, (string) $r->city_key,
                $r->is_displaced === null ? '—' : ($r->is_displaced ? 'نعم' : 'لا'), (string) $r->school_grade_key, (string) $r->academic_performance_key,
                (string) $r->issue_count, RegistryIssueTypes::SEVERITIES[$r->max_severity] ?? '—', self::REVIEW_STATUSES[$r->review_status] ?? $r->review_status])->all(),
            'unmasked' => $unmask,
        ];
    }

    /** The shared filter set of «الأيتام» / «الأسر» / «الأوصياء» / «التصنيفات» and exports. */
    public function filtered(User $user, object $batch, array $f): Builder
    {
        $this->access->assert($user, 'view');
        $q = DB::table('orphan_import_rows as r')->where('r.batch_id', $batch->id);
        if (! empty($f['q'])) {
            // Full name or fragments: every typed word must start a word of the same person's name, in any order.
            $columns = self::NAME_SCOPES[$f['q_scope'] ?? 'any'] ?? self::NAME_SCOPES['any'];
            $words = array_slice(array_filter(explode(' ', ArabicText::normalize((string) $f['q']))), 0, 8);
            $q->where(function (Builder $any) use ($columns, $words): void {
                foreach ($columns as $column) {
                    $any->orWhere(function (Builder $all) use ($column, $words): void {
                        foreach ($words as $word) {
                            $all->whereRaw("(' ' || coalesce($column, '') || ' ') like ?", ['% '.self::escapeLike($word).'%']);
                        }
                    });
                }
            });
        }
        foreach (['c' => 'r.child_name_search', 'g' => 'r.guardian_name_search'] as $prefix => $column) {
            for ($part = 1; $part <= 4; $part++) {
                if (trim((string) ($f[$prefix.'n'.$part] ?? '')) !== '') {
                    $this->namePart($q, $column, $part, (string) $f[$prefix.'n'.$part], ($f['name_mode'] ?? 'starts') === 'exact');
                }
            }
        }
        if (! empty($f['national_id'])) {
            // Exact identity lookup is for holders of «sensitive» only; never a fuzzy match.
            $this->access->assert($user, 'sensitive');
            $id = ArabicText::digits((string) $f['national_id']);
            $q->where(fn (Builder $w) => $w->where('r.child_national_id', $id)->orWhere('r.guardian_national_id', $id)->orWhere('r.father_national_id', $id)->orWhere('r.mother_national_id', $id));
        }
        foreach (['child_id' => 'r.child_national_id', 'guardian_id' => 'r.guardian_national_id'] as $param => $column) {
            if (trim((string) ($f[$param] ?? '')) !== '') {
                // «رقم هوية اليتيم» / «رقم هوية الوصي»: exact text as stored, so a leading zero is never lost or added.
                $this->access->assert($user, 'sensitive');
                $q->where($column, ArabicText::digits((string) $f[$param]));
            }
        }
        if (($f['governorate'] ?? '') === self::UNMAPPED) {
            // Source governorate text not yet linked to «ثوابت النظام» in «التصنيفات» (or empty).
            $q->where(fn (Builder $w) => $w->whereNull('r.governorate_key')
                ->orWhereNotIn('r.governorate_key', DB::table('orphan_import_aliases')->where('field_key', 'governorate')->select('source_key')));
            unset($f['governorate']);
        }
        if (! empty($f['id_placeholder'])) {
            // «هوية شكلية»: the same digit repeated (e.g. 000000000) for the child or the guardian — flagged, never changed.
            $q->whereRaw('('.self::placeholderSql('r.child_national_id').' or '.self::placeholderSql('r.guardian_national_id').')');
        }
        if (! empty($f['row_no'])) {
            $q->where('r.source_row_number', (int) $f['row_no']);
        }
        foreach (['review_status', 'governorate_key' => 'governorate', 'city_key' => 'city', 'guardian_relation_key' => 'relation', 'school_grade_key' => 'grade',
            'academic_performance_key' => 'performance', 'child_id_status' => 'id_status', 'max_severity' => 'severity', 'mother_status_key' => 'mother_status'] as $column => $param) {
            $column = is_int($column) ? $param : $column;
            if (isset($f[$param]) && $f[$param] !== '') {
                $f[$param] === self::MISSING ? $q->whereNull('r.'.$column) : $q->where('r.'.$column, $f[$param]);
            }
        }
        if (($f['father_death'] ?? '') !== '') {
            $f['father_death'] === 'missing' ? $q->whereNull('r.father_death_date') : $q->whereNotNull('r.father_death_date');
        }
        if (! empty($f['age_unknown'])) {
            $q->whereNull('r.child_dob');
        }
        if (! empty($f['name_incomplete'])) {
            $q->whereRaw("coalesce(array_length(string_to_array(nullif(r.child_name_search, ''), ' '), 1), 0) < 4");
        }
        if (($f['claim'] ?? '') !== '') {
            $f['claim'] === 'pending' ? $q->whereNull('r.guardianship_decision') : $q->where('r.guardianship_decision', $f['claim']);
        }
        if (isset($f['health']) && $f['health'] !== '') {
            $this->access->assert($user, 'sensitive');
            $f['health'] === self::MISSING ? $q->whereNull('r.health_status_key') : $q->where('r.health_status_key', $f['health']);
        }
        if (($f['sex'] ?? '') !== '') {
            $f['sex'] === 'missing' ? $q->whereNull('r.child_sex') : $q->where('r.child_sex', $f['sex']);
        }
        if (($f['displaced'] ?? '') !== '') {
            $f['displaced'] === 'missing' ? $q->whereNull('r.is_displaced') : $q->where('r.is_displaced', $f['displaced'] === 'yes');
        }
        if (isset($f['age_min']) && $f['age_min'] !== '') {
            $q->where('r.child_dob', '<=', now(config('orphans.timezone'))->subYears((int) $f['age_min'])->toDateString());
        }
        if (isset($f['age_max']) && $f['age_max'] !== '') {
            $q->where('r.child_dob', '>', now(config('orphans.timezone'))->subYears((int) $f['age_max'] + 1)->toDateString());
        }
        foreach (['dob_from' => '>=', 'dob_to' => '<='] as $param => $operator) {
            if (! empty($f[$param])) {
                $q->where('r.child_dob', $operator, $f[$param]);
            }
        }
        if (! empty($f['issue'])) {
            $q->whereExists(fn (Builder $e) => $e->from('orphan_import_issues as i')->whereColumn('i.row_id', 'r.id')->where('i.type', $f['issue'])->whereIn('i.status', ['open', 'follow_up']));
        }
        if (! empty($f['household'])) {
            $q->where('r.household_key', self::unref((string) $f['household']));
        }
        if (! empty($f['guardian'])) {
            $q->where('r.guardian_key', self::unref((string) $f['guardian']));
        }
        if (($f['assigned'] ?? '') === 'me') {
            $q->where('r.assigned_to', $user->id);
        }
        foreach (['size_min' => '>=', 'size_max' => '<='] as $param => $operator) {
            if (isset($f[$param]) && $f[$param] !== '') {
                $q->where('r.family_total', $operator, (int) $f[$param]);
            }
        }

        return $q;
    }

    /**
     * One part of «الاسم الرباعي» against the normalized name: parts 1–3 are the 1st–3rd words, the family name is
     * everything after the 3rd word (multi-word family names stay whole). A name of fewer than four words has no
     * grandfather/family part, so it never matches those parts — nothing is guessed. A part typed with several words
     * (e.g. «عبد الله») is matched as consecutive words.
     */
    private function namePart(Builder $q, string $column, int $part, string $term, bool $exact): void
    {
        $term = ArabicText::normalize($term);
        $like = self::escapeLike($term);
        if (str_contains($term, ' ') && $part < 4) {
            $part === 1
                ? $q->whereRaw("$column like ?", [$like.($exact ? ' %' : '%')])
                : $q->whereRaw("(' ' || $column || ' ') like ?", ['% '.$like.($exact ? ' %' : '%')]);

            return;
        }
        $expr = $part < 4 ? "split_part($column, ' ', $part)" : "array_to_string((string_to_array($column, ' '))[4:], ' ')";
        if ($exact) {
            $q->whereRaw("$expr = ?", [$term]);
        } else {
            $q->whereRaw($part < 4 ? "$expr like ?" : "(' ' || $expr) like ?", [$part < 4 ? $like.'%' : '% '.$like.'%']);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function brief(object $r, bool $sensitive): array
    {
        return ['id' => $r->id, 'row' => $r->source_row_number, 'name' => $r->child_name, 'national_id' => $sensitive ? $r->child_national_id : self::mask($r->child_national_id),
            'id_status' => $r->child_id_status, 'dob' => $r->child_dob, 'sex' => $r->child_sex, 'relation' => $r->guardian_relation_key, 'guardian_name' => $r->guardian_name,
            'issues' => $r->issue_count, 'severity' => $r->max_severity, 'review_status' => $r->review_status, 'claim' => $r->guardianship_decision ?? 'pending'];
    }

    private function paged($page): array
    {
        return ['data' => $page->items(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }
}
