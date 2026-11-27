<?php

namespace App\Services\Orphans\Campaigns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The three nomination modes, run against a frozen rule version:
 *  - general: eligibility gates only; when demand exceeds capacity a tie-break policy is mandatory (no silent subset).
 *  - scored: weighted ranking of the targeted population.
 *  - hybrid (default): gates first, then weighted ranking where capacity is scarce.
 *
 * Every candidate keeps a snapshot of the facts used (value, source, verification, date), the contribution of each
 * criterion, missing data, and the reason for its outcome. Missing data is never read as prosperity: it sends the
 * candidate to review (policy «review») or counts as neutral (policy «neutral»), always flagged. Medical diagnoses
 * are never read; only the non-clinical «support need» flag. A run never approves anything and reserves no stock.
 */
final class NominationEngine
{
    /** The seven approved selection dimensions (TRANSFORM §13); every criterion belongs to exactly one. */
    public const DIMENSIONS = ['economic' => 'الوضع الاقتصادي', 'family' => 'الوضع الأسري', 'health' => 'الصحة', 'housing' => 'السكن', 'education' => 'التعليم',
        'previous_assistance' => 'المساعدات السابقة', 'vulnerability' => 'الهشاشة ومستوى الاحتياج'];

    /**
     * key => [label, type, dimension]. Nothing is weighted here: each campaign's rule version sets the weights and,
     * for «map» criteria, the points of each «ثوابت النظام» value.
     */
    public const CRITERIA = [
        'current_income_band' => ['فئة دخل الأسرة/الوصي الحالية', 'map', 'economic'], 'father_pre_death_income' => ['دخل الأب قبل الوفاة (سياق تاريخي)', 'map', 'economic'],
        'orphan_class' => ['فئة اليتم', 'map', 'family'], 'dependents' => ['عدد المُعالين', 'cap', 'family'],
        'support_need' => ['احتياج دعم غير سريري (إعاقة/رعاية مزمنة)', 'flag', 'health'],
        'displacement' => ['النزوح الحالي', 'flag', 'housing'], 'housing_tenure' => ['حيازة السكن الحالي', 'map', 'housing'],
        'shelter_type' => ['نوع المأوى الحالي', 'map', 'housing'], 'building_condition' => ['حالة المبنى الحالي', 'map', 'housing'],
        'education_need' => ['حالة الالتحاق الدراسي لليتيم (آخر سجل تعليمي)', 'map', 'education'],
        'no_recent_benefit' => ['مدة منذ آخر استفادة مؤكدة', 'days', 'previous_assistance'], 'not_sponsored' => ['غير مكفول حاليًا', 'flag', 'previous_assistance'],
        'needs_level' => ['مستوى الاحتياج في آخر تقييم اجتماعي', 'map', 'vulnerability'], 'age_younger' => ['صغر السن', 'age', 'vulnerability'],
    ];

    public const OUTCOMES = ['primary' => 'الكشف الأساسي', 'reserve' => 'الاحتياط', 'review' => 'للمراجعة', 'ineligible' => 'غير مؤهل', 'insufficient_priority' => 'أولوية غير كافية'];

    /** Base rule of every mode and every entry path: these orphan files are never campaign beneficiaries. */
    public const EXCLUDED_FILE_STATUSES = ['ended', 'archived', 'provisional'];

    /**
     * The base rule for one unit key ("o:<id>", "h:<id>", "g:<id>"): an orphan file not excluded; an active household
     * with a live member whose file is not excluded; an active guardian with an approved guardianship.
     */
    public static function eligibleUnit(string $unitKey): bool
    {
        return self::eligibleIds(substr($unitKey, 0, 1), [(int) substr($unitKey, 2)]) !== [];
    }

    /** Entries of a list that no longer pass the base rule (checked again at submission and approval). @return list<object> */
    public static function ineligibleEntries(int $listId): array
    {
        $entries = DB::table('campaign_list_entries')->where('list_id', $listId)->orderBy('position')->get(['id', 'unit_key', 'orphan_id', 'household_id', 'guardian_id']);
        $eligible = [];
        foreach (['o', 'h', 'g'] as $kind) {
            $ids = $entries->filter(fn (object $e): bool => str_starts_with($e->unit_key, $kind.':'))->map(fn (object $e): int => (int) substr($e->unit_key, 2))->unique()->values()->all();
            foreach (array_chunk($ids, 5000) as $chunk) {
                foreach (self::eligibleIds($kind, $chunk) as $id) {
                    $eligible[$kind.':'.$id] = true;
                }
            }
        }

        return $entries->reject(fn (object $e): bool => isset($eligible[$e->unit_key]))->values()->all();
    }

    /** @param list<int> $ids @return list<int> the ids of one unit kind that pass the base rule */
    private static function eligibleIds(string $kind, array $ids): array
    {
        $query = match ($kind) {
            'o' => DB::table('orphans as o')->whereIn('o.id', $ids)->whereNotIn('o.status', self::EXCLUDED_FILE_STATUSES)->select('o.id'),
            'h' => DB::table('households as h')->whereIn('h.id', $ids)->where('h.status', 'active')->whereExists(fn ($q) => $q->from('household_members as m')
                ->join('orphans as o', 'o.person_id', '=', 'm.person_id')->whereColumn('m.household_id', 'h.id')->whereNull('m.ends_on')->whereNotIn('o.status', self::EXCLUDED_FILE_STATUSES))->select('h.id'),
            'g' => DB::table('orphan_guardians as gg')->whereIn('gg.id', $ids)->where('gg.status', 'active')
                ->whereExists(fn ($q) => $q->from('orphan_guardianships as g')->whereColumn('g.guardian_id', 'gg.id')->where('g.status', 'approved'))->select('gg.id'),
            default => null,
        };

        return $query ? $query->pluck('id')->map(fn ($id): int => (int) $id)->all() : [];
    }

    /** Validates and freezes a rule version (immutable rows; a change is a new version). */
    public function saveRules(object $campaign, array $rules, User $actor): int
    {
        $mode = $rules['mode'] ?? 'hybrid';
        $criteria = array_values(array_filter($rules['criteria'] ?? [], fn ($c) => isset(self::CRITERIA[$c['key'] ?? '']) && (float) ($c['weight'] ?? 0) > 0));
        // Map points keyed by «ثوابت النظام» value id; blank entries are «not configured» (missing), never zero.
        $criteria = array_map(fn ($c) => [...collect($c)->only(['key', 'weight', 'cap', 'max_age', 'days'])->all(),
            'map' => (object) array_filter((array) ($c['map'] ?? []), fn ($v, $k) => $v !== null && $v !== '' && (int) $k > 0, ARRAY_FILTER_USE_BOTH)], $criteria);
        // Education is read from the child's own record; a household or guardian unit has none, so every candidate would be «missing».
        if ($campaign->beneficiary_unit !== 'orphan' && in_array('education_need', array_column($criteria, 'key'), true)) {
            throw ValidationException::withMessages(['criteria' => 'معيار «'.self::CRITERIA['education_need'][0].'» يخص الحملات التي وحدتها اليتيم فقط.']);
        }
        if ($mode !== 'general') {
            $sum = array_sum(array_map(fn ($c) => (float) $c['weight'], $criteria));
            if ($criteria === [] || abs($sum - 100) > 0.001) {
                throw ValidationException::withMessages(['criteria' => 'مجموع أوزان المعايير يجب أن يساوي 100 (الحالي '.$sum.').']);
            }
        }
        if ($mode === 'general' && ! empty($rules['tie_break']) && ! in_array($rules['tie_break'], ['oldest_benefit', 'youngest', 'earliest_registration'], true)) {
            throw ValidationException::withMessages(['tie_break' => 'سياسة ترجيح غير معروفة.']);
        }
        $gates = collect($rules['gates'] ?? [])->only(['classes', 'require_approved_guardian', 'active_files_only', 'displaced_only', 'exclude_sponsored', 'same_type_window_days'])->all();
        $payload = ['mode' => $mode, 'gates' => $gates, 'criteria' => $criteria, 'missing_policy' => ($rules['missing_policy'] ?? 'review') === 'neutral' ? 'neutral' : 'review',
            'tie_break' => $rules['tie_break'] ?? null, 'reserve_count' => max(0, (int) ($rules['reserve_count'] ?? 0))];
        $hash = hash('sha256', json_encode($payload));
        $last = DB::table('campaign_rule_versions')->where('campaign_id', $campaign->id)->orderByDesc('version')->first();
        if ($last && $last->rules_hash === $hash) {
            return (int) $last->id;
        }

        return (int) DB::table('campaign_rule_versions')->insertGetId([...$payload, 'gates' => json_encode($gates), 'criteria' => json_encode($criteria), 'campaign_id' => $campaign->id,
            'version' => ($last->version ?? 0) + 1, 'rules_hash' => $hash, 'notes' => $rules['notes'] ?? null, 'created_by' => $actor->id, 'created_at' => now()]);
    }

    /** Runs the nomination on current data and stores the frozen, explained result. */
    public function run(object $campaign, int $ruleVersionId, User $actor): object
    {
        // A department-wide run holds every candidate in memory to rank them; give it room and time.
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        $rules = DB::table('campaign_rule_versions')->where('id', $ruleVersionId)->where('campaign_id', $campaign->id)->firstOrFail();
        $gates = json_decode($rules->gates, true) ?: [];
        $criteria = json_decode($rules->criteria, true) ?: [];
        $targeting = json_decode((string) $campaign->targeting, true) ?: [];
        $facts = $this->facts($campaign, $targeting);
        $items = DB::table('campaign_items')->where('campaign_id', $campaign->id)->orderBy('id')->get();
        $quotas = DB::table('campaign_quotas')->where('campaign_id', $campaign->id)->get();
        $windowDays = (int) ($gates['same_type_window_days'] ?? 0);
        $duplicates = $windowDays > 0 ? $this->duplicates($campaign, array_keys($facts), $windowDays) : [];

        $candidates = [];
        foreach ($facts as $key => $f) {
            $candidates[$key] = $this->evaluate($f, $rules, $gates, $criteria, $targeting) + ['unit_key' => $key, 'facts' => $f, 'duplicates' => $duplicates[$key] ?? []];
        }
        $eligible = array_filter($candidates, fn ($c) => $c['outcome'] === 'eligible');
        $capacityUnits = $this->capacityUnits($items, $quotas);
        if ($rules->mode === 'general' && $capacityUnits !== null && count($eligible) > $capacityUnits && ! $rules->tie_break) {
            foreach ($eligible as $key => $c) {
                $candidates[$key]['outcome'] = 'review';
                $candidates[$key]['reasons'][] = 'الطلب يتجاوز الكمية المتاحة ولا توجد سياسة ترجيح معتمدة؛ لم يُختر أي جزء تلقائيًا.';
            }
            $eligible = [];
        }
        uasort($eligible, fn ($a, $b) => $this->compare($a, $b, $rules));
        $this->allocate($eligible, $candidates, $items, $quotas, (int) $rules->reserve_count, $campaign->beneficiary_unit);

        $ordered = $candidates;
        ksort($ordered);
        // The capture time is recorded in each snapshot but excluded here: same data + same rules = same hash.
        $inputHash = hash('sha256', $rules->rules_hash.json_encode(array_map(fn ($c) => $c['facts'], $ordered)).json_encode($items).json_encode($quotas));
        $counts = array_count_values(array_column($candidates, 'outcome'));

        $takenAt = now()->toIso8601String();

        return DB::transaction(function () use ($campaign, $rules, $candidates, $inputHash, $counts, $items, $capacityUnits, $actor, $takenAt): object {
            $runId = DB::table('campaign_runs')->insertGetId(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'rule_version_id' => $rules->id, 'input_hash' => $inputHash,
                'counts' => json_encode($counts), 'capacity' => json_encode(['units' => $capacityUnits, 'items' => $items->map(fn ($i) => ['id' => $i->id, 'label' => $i->label, 'variant' => $i->variant, 'total' => $i->total_quantity])]),
                'created_by' => $actor->id, 'created_at' => now()]);
            foreach (array_chunk(array_values($candidates), 500) as $chunk) {
                DB::table('campaign_candidates')->insert(array_map(fn ($c) => [
                    'run_id' => $runId, ...$c['facts']['unit'], 'unit_key' => $c['unit_key'], 'outcome' => $c['outcome'], 'rank' => $c['rank'] ?? null,
                    'score' => $c['score'], 'contributions' => json_encode($c['contributions'], JSON_UNESCAPED_UNICODE), 'reasons' => json_encode($c['reasons'], JSON_UNESCAPED_UNICODE),
                    'missing' => json_encode($c['missing'], JSON_UNESCAPED_UNICODE), 'snapshot' => json_encode(self::snapshot($c['facts'], $takenAt), JSON_UNESCAPED_UNICODE),
                    'governorate_value_id' => $c['facts']['governorate_value_id'], 'age' => $c['facts']['age'], 'item_id' => $c['item_id'] ?? null,
                    'duplicate_flags' => $c['duplicates'] ? json_encode($c['duplicates'], JSON_UNESCAPED_UNICODE) : null,
                ], $chunk));
            }
            foreach ($candidates as $c) {
                if ($c['duplicates'] && in_array($c['outcome'], ['primary', 'reserve'], true)) {
                    foreach ($c['duplicates'] as $d) {
                        DB::table('campaign_duplicate_alerts')->insert(['campaign_id' => $campaign->id, 'run_id' => $runId, 'unit_key' => $c['unit_key'], 'kind' => $d['kind'],
                            'other_campaign_id' => $d['campaign_id'] ?? null, 'benefit_record_id' => $d['benefit_id'] ?? null, 'detail' => $d['detail'], 'status' => 'open', 'created_at' => now()]);
                    }
                }
            }

            return DB::table('campaign_runs')->find($runId);
        });
    }

    /** Targeting, gates, scoring and explanation for one candidate. */
    private function evaluate(array $f, object $rules, array $gates, array $criteria, array $targeting): array
    {
        $reasons = [];
        $missing = [];
        $outcome = 'eligible';
        // Targeting (all modes).
        if (! empty($targeting['governorates']) && ! in_array($f['governorate_value_id'], array_map('intval', $targeting['governorates']), true)) {
            $f['governorate_value_id'] === null ? $missing[] = 'العنوان الحالي' : $reasons[] = 'خارج المحافظات المستهدفة';
        }
        if (! empty($targeting['localities']) && ! in_array($f['locality_value_id'], array_map('intval', $targeting['localities']), true)) {
            $f['locality_value_id'] === null ? $missing[] = 'المدينة/البلدة' : $reasons[] = 'خارج المناطق المستهدفة';
        }
        if ($f['unit']['orphan_id'] ?? null) {
            if (isset($targeting['age_min']) || isset($targeting['age_max'])) {
                if ($f['age'] === null) {
                    $missing[] = 'تاريخ الميلاد';
                } elseif ((isset($targeting['age_min']) && $f['age'] < (int) $targeting['age_min']) || (isset($targeting['age_max']) && $targeting['age_max'] !== '' && $f['age'] > (int) $targeting['age_max'])) {
                    $reasons[] = 'خارج الفئة العمرية المستهدفة';
                }
            }
            if (! empty($targeting['sex'])) {
                $f['sex'] === null ? $missing[] = 'الجنس' : ($f['sex'] !== $targeting['sex'] ? $reasons[] = 'لا يطابق الجنس المستهدف' : null);
            }
        }
        // Eligibility gates (general and hybrid).
        if ($rules->mode !== 'scored') {
            if (! empty($gates['classes']) && ($f['unit']['orphan_id'] ?? null)) {
                $f['class_value_id'] === null ? $missing[] = 'فئة اليتم' : (! in_array($f['class_value_id'], array_map('intval', $gates['classes']), true) ? $reasons[] = 'فئة اليتم غير مشمولة' : null);
            }
            if (! empty($gates['active_files_only']) && ($f['file_status'] ?? 'active') !== 'active') {
                $reasons[] = 'الملف غير معتمد';
            }
            if (! empty($gates['displaced_only']) && ! $f['displaced']) {
                $reasons[] = 'غير نازح حاليًا';
            }
            if (! empty($gates['exclude_sponsored']) && $f['sponsored']) {
                $reasons[] = 'مكفول حاليًا';
            }
        }
        $verification = [];
        if (($gates['require_approved_guardian'] ?? true) && ($f['unit']['orphan_id'] ?? null) && ! $f['approved_guardian']) {
            $verification[] = 'لا توجد وصاية معتمدة (بانتظار التحقق)';
        }
        if ($reasons !== []) {
            $outcome = 'ineligible';
        } elseif ($missing !== [] || $verification !== []) {
            $outcome = 'review';
            $reasons = [...$reasons, ...array_map(fn ($m) => 'بيانات ناقصة: '.$m, $missing), ...$verification];
        }

        // Scoring (scored and hybrid).
        $score = null;
        $contributions = [];
        if ($rules->mode !== 'general') {
            $total = 0.0;
            $scoreMissing = [];
            foreach ($criteria as $c) {
                [$value, $source] = $this->criterionValue($c, $f);
                $weight = (float) $c['weight'];
                if ($value === null) {
                    $scoreMissing[] = self::CRITERIA[$c['key']][0];
                    $value = $rules->missing_policy === 'neutral' ? 0.5 : null;
                }
                $points = $value === null ? null : round($weight * $value, 3);
                $total += $points ?? 0;
                $contributions[] = ['key' => $c['key'], 'label' => self::CRITERIA[$c['key']][0], 'dimension' => self::CRITERIA[$c['key']][2], 'weight' => $weight, 'value' => $value, 'points' => $points,
                    'source' => $source, 'missing' => $value === null || in_array(self::CRITERIA[$c['key']][0], $scoreMissing, true)];
            }
            $score = round($total, 3);
            if ($scoreMissing !== []) {
                $missing = [...$missing, ...$scoreMissing];
                if ($rules->missing_policy === 'review' && $outcome === 'eligible') {
                    $outcome = 'review';
                    $reasons[] = 'معلومات تقييم ناقصة: '.implode('، ', $scoreMissing).' (لا تُعامل كغياب حاجة)';
                }
            }
        }

        return ['outcome' => $outcome, 'reasons' => $reasons, 'missing' => array_values(array_unique($missing)), 'score' => $score, 'contributions' => $contributions];
    }

    /** @return array{0: ?float, 1: ?string} normalized value 0..1 (null = missing) and where it came from */
    private function criterionValue(array $c, array $f): array
    {
        $map = fn (?int $id, ?string $source) => $id === null ? [null, $source] : (isset($c['map'][(string) $id]) && $c['map'][(string) $id] !== '' ? [max(0, min(100, (float) $c['map'][(string) $id])) / 100, $source] : [null, $source]);

        return match ($c['key']) {
            'orphan_class' => $map($f['class_value_id'], 'ملف اليتيم'),
            'current_income_band' => $map($f['income_band_value_id'], $f['economic'] ? 'تقييم '.$f['economic'][0].' بتاريخ '.$f['economic'][3] : 'لا يوجد تقييم اقتصادي'),
            'father_pre_death_income' => $map($f['father_income_band_value_id'], 'تقييم الأب قبل الوفاة'),
            'needs_level' => $map($f['needs_level_value_id'], 'آخر تقييم اجتماعي'),
            'dependents' => $f['dependents'] === null ? [null, 'تقييم اقتصادي'] : [min(1, $f['dependents'] / max(1, (int) ($c['cap'] ?? 8))), 'تقييم اقتصادي'],
            'displacement' => [$f['displaced'] ? 1.0 : 0.0, 'العناوين (نزوح مفتوح)'],
            'age_younger' => $f['age'] === null ? [null, 'تاريخ الميلاد'] : [max(0, 1 - $f['age'] / max(1, (int) ($c['max_age'] ?? 18))), 'تاريخ الميلاد'],
            'no_recent_benefit' => [$f['last_benefit'] === null ? 1.0 : min(1, now()->diffInDays($f['last_benefit']) / max(1, (int) ($c['days'] ?? 180))), 'سجل الاستفادة المؤكدة'],
            'not_sponsored' => [$f['sponsored'] ? 0.0 : 1.0, 'الكفالات النشطة'],
            'support_need' => [$f['support_need'] ? 1.0 : 0.0, 'احتياجات الدعم غير السريرية'],
            'housing_tenure' => $map($f['tenure_value_id'], 'العنوان الحالي (الحيازة)'),
            'shelter_type' => $map($f['shelter_type_value_id'], 'العنوان الحالي (نوع المأوى)'),
            'building_condition' => $map($f['building_condition_value_id'], 'العنوان الحالي (حالة المبنى)'),
            'education_need' => $map($f['education_status_value_id'], 'آخر سجل تعليمي لليتيم'),
            default => [null, null],
        };
    }

    /**
     * Per-dimension subtotals of one candidate's contributions, in the order of DIMENSIONS. Runs made before the
     * dimensions were stored take the dimension from the criterion key.
     *
     * @return list<array{key: string, label: string, weight: float, points: float, missing: bool}>
     */
    public static function dimensionTotals(array $contributions): array
    {
        $totals = [];
        foreach ($contributions as $c) {
            $dimension = $c['dimension'] ?? (self::CRITERIA[$c['key'] ?? ''][2] ?? null);
            if ($dimension === null || ! isset(self::DIMENSIONS[$dimension])) {
                continue;
            }
            $totals[$dimension] ??= ['key' => $dimension, 'label' => self::DIMENSIONS[$dimension], 'weight' => 0.0, 'points' => 0.0, 'missing' => false];
            $totals[$dimension]['weight'] += (float) ($c['weight'] ?? 0);
            $totals[$dimension]['points'] += (float) ($c['points'] ?? 0);
            $totals[$dimension]['missing'] = $totals[$dimension]['missing'] || ! empty($c['missing']);
        }

        return array_values(array_map(fn (array $t): array => [...$t, 'weight' => round($t['weight'], 3), 'points' => round($t['points'], 3)],
            array_filter(array_replace(array_fill_keys(array_keys(self::DIMENSIONS), null), $totals))));
    }

    private function compare(array $a, array $b, object $rules): int
    {
        if ($rules->mode !== 'general' && $a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        $tie = match ($rules->tie_break) {
            'youngest' => ($a['facts']['age'] ?? 999) <=> ($b['facts']['age'] ?? 999),
            'earliest_registration' => ($a['facts']['registered_on'] ?? '9999') <=> ($b['facts']['registered_on'] ?? '9999'),
            default => ($a['facts']['last_benefit'] ?? '0000') <=> ($b['facts']['last_benefit'] ?? '0000'),
        };

        return $tie !== 0 ? $tie : strcmp($a['unit_key'], $b['unit_key']);
    }

    /** Units the campaign can serve: the smallest item capacity (null = no quantities defined, i.e. unlimited). */
    private function capacityUnits($items, $quotas): ?int
    {
        $units = null;
        foreach ($items as $item) {
            $units = ($units ?? 0) + intdiv((int) round($item->total_quantity * 1000), max(1, (int) round($item->per_unit_quantity * 1000)));
        }
        if ($quotas->isNotEmpty()) {
            $units = min($units ?? PHP_INT_MAX, (int) $quotas->sum('quantity'));
        }

        return $units;
    }

    /**
     * Greedy allocation in rank order: a matching variant (size/age/sex) with remaining quantity and a remaining
     * geographic quota → primary; otherwise reserve while reserve places remain; otherwise insufficient priority.
     */
    private function allocate(array $eligible, array &$candidates, $items, $quotas, int $reserve, string $unit): void
    {
        $remaining = $items->mapWithKeys(fn ($i) => [$i->id => (int) round($i->total_quantity * 1000)])->all();
        $quota = $quotas->mapWithKeys(fn ($q) => [$q->governorate_value_id.':'.($q->locality_value_id ?? '*') => (int) $q->quantity])->all();
        $rank = 0;
        foreach ($eligible as $key => $c) {
            $candidates[$key]['rank'] = ++$rank;
            $item = null;
            foreach ($items as $i) {
                $criteria = json_decode((string) $i->criteria, true) ?: [];
                $f = $c['facts'];
                $fits = (! isset($criteria['age_min']) || ($f['age'] !== null && $f['age'] >= (int) $criteria['age_min'])) && (! isset($criteria['age_max']) || ($f['age'] !== null && $f['age'] <= (int) $criteria['age_max']))
                    && (empty($criteria['sex']) || $f['sex'] === $criteria['sex']);
                if ($fits && $remaining[$i->id] >= (int) round($i->per_unit_quantity * 1000)) {
                    $item = $i;
                    break;
                }
            }
            $quotaKey = null;
            if ($quota !== []) {
                $quotaKey = isset($quota[$c['facts']['governorate_value_id'].':'.($c['facts']['locality_value_id'] ?? '*')]) ? $c['facts']['governorate_value_id'].':'.$c['facts']['locality_value_id']
                    : (isset($quota[$c['facts']['governorate_value_id'].':*']) ? $c['facts']['governorate_value_id'].':*' : null);
            }
            $hasQuota = $quota === [] || ($quotaKey !== null && $quota[$quotaKey] > 0);
            if (($items->isEmpty() || $item) && $hasQuota) {
                $candidates[$key]['outcome'] = 'primary';
                $candidates[$key]['item_id'] = $item?->id;
                if ($item) {
                    $remaining[$item->id] -= (int) round($item->per_unit_quantity * 1000);
                }
                if ($quotaKey !== null) {
                    $quota[$quotaKey]--;
                }
                $candidates[$key]['reasons'][] = 'مؤهل'.($c['score'] !== null ? ' — الدرجة '.$c['score'] : '').' — الترتيب '.$rank;
            } elseif ($reserve > 0) {
                $reserve--;
                $candidates[$key]['outcome'] = 'reserve';
                $candidates[$key]['reasons'][] = $hasQuota ? 'مؤهل لكن لا تتوفر كمية مطابقة (مقاس/صنف)؛ في الاحتياط' : 'مؤهل لكن حصة منطقته نفدت أو غير محددة؛ في الاحتياط';
            } else {
                $candidates[$key]['outcome'] = 'insufficient_priority';
                $candidates[$key]['reasons'][] = 'مؤهل لكن الكمية أو الحصص لا تكفي لترتيبه';
            }
        }
    }

    /**
     * Facts per beneficiary unit in set-based queries (no per-candidate queries). Keys: "o:<id>", "h:<id>", "g:<id>".
     *
     * @return array<string, array<string, mixed>>
     */
    public function facts(object $campaign, array $targeting): array
    {
        // Tens of thousands of candidates are ranked in memory; this needs more than the default 128 MB.
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        $today = today()->toDateString();
        $sponsored = "exists (select 1 from sponsorship_beneficiaries sb join sponsorships s on s.id = sb.sponsorship_id where sb.orphan_id = o.id and sb.ends_on is null and s.status in ('active','partial') and (s.ends_on is null or s.ends_on >= '$today'))";
        $lastBenefit = "(select max(b.delivered_on) from benefit_records b where b.orphan_id = o.id and b.kind = 'delivery' and not exists (select 1 from benefit_records r where r.reverses_id = b.id))";
        $rows = [];
        if ($campaign->beneficiary_unit === 'orphan') {
            $query = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')
                ->leftJoin('registry_addresses as a', fn ($j) => $j->on('a.person_id', '=', 'p.id')->where('a.kind', 'current')->whereNull('a.ends_on'))
                ->whereNotIn('o.status', self::EXCLUDED_FILE_STATUSES)
                ->selectRaw("o.id, o.status, o.registered_on, o.orphan_class_value_id, p.sex, p.date_of_birth, a.governorate_value_id, a.locality_value_id,
                    a.tenure_value_id, a.shelter_type_value_id, a.building_condition_value_id,
                    (select e.status_value_id from education_records e where e.orphan_id = o.id and e.status_value_id is not null order by e.id desc limit 1) as education_status,
                    exists (select 1 from registry_addresses d where d.person_id = p.id and d.kind = 'displacement' and d.ends_on is null) as displaced,
                    exists (select 1 from orphan_guardianships g where g.orphan_id = o.id and g.status = 'approved' and (g.starts_on is null or g.starts_on <= '$today') and (g.ends_on is null or g.ends_on >= '$today')) as approved_guardian,
                    (select g.guardian_id from orphan_guardianships g where g.orphan_id = o.id and g.status = 'approved' order by g.is_primary desc, g.id limit 1) as guardian_id,
                    $sponsored as sponsored, $lastBenefit as last_benefit,
                    exists (select 1 from care_records c where c.person_id = p.id and c.is_active and c.category in ('disability','chronic','assistive_device')) as support_need,
                    (select m.household_id from household_members m where m.person_id = p.id and m.ends_on is null order by m.id limit 1) as household_id,
                    (select e.income_band_value_id from economic_assessments e join orphan_parent_links l on l.person_id = e.person_id where l.orphan_id = o.id and l.relation = 'father' and e.period = 'pre_death' order by e.id desc limit 1) as father_income")
                ->when(! empty($targeting['governorates']), fn ($q) => $q->where(fn ($w) => $w->whereIn('a.governorate_value_id', array_map('intval', $targeting['governorates']))->orWhereNull('a.governorate_value_id')));
            foreach ($query->get() as $r) {
                $rows['o:'.$r->id] = (array) $r;
            }
        } elseif ($campaign->beneficiary_unit === 'household') {
            $query = DB::table('households as h')->leftJoin('registry_addresses as a', fn ($j) => $j->on('a.household_id', '=', 'h.id')->where('a.kind', 'current')->whereNull('a.ends_on'))
                ->where('h.status', 'active')->whereExists(fn ($q) => $q->from('household_members as m')->join('orphans as o', 'o.person_id', '=', 'm.person_id')->whereColumn('m.household_id', 'h.id')->whereNull('m.ends_on')->whereNotIn('o.status', self::EXCLUDED_FILE_STATUSES))
                ->selectRaw("h.id, h.dependents_count, h.members_count, a.governorate_value_id, a.locality_value_id, a.tenure_value_id, a.shelter_type_value_id, a.building_condition_value_id,
                    exists (select 1 from registry_addresses d where d.household_id = h.id and d.kind = 'displacement' and d.ends_on is null) as displaced,
                    (select max(b.delivered_on) from benefit_records b where b.household_id = h.id and b.kind = 'delivery' and not exists (select 1 from benefit_records r where r.reverses_id = b.id)) as last_benefit");
            foreach ($query->cursor() as $r) {
                $rows['h:'.$r->id] = (array) $r;
            }
        } else {
            $query = DB::table('orphan_guardians as gg')->join('persons as p', 'p.id', '=', 'gg.person_id')
                ->leftJoin('registry_addresses as a', fn ($j) => $j->on('a.person_id', '=', 'p.id')->where('a.kind', 'current')->whereNull('a.ends_on'))->where('gg.status', 'active')
                ->whereExists(fn ($q) => $q->from('orphan_guardianships as g')->whereColumn('g.guardian_id', 'gg.id')->where('g.status', 'approved'))
                ->selectRaw("gg.id, gg.person_id, a.governorate_value_id, a.locality_value_id, a.tenure_value_id, a.shelter_type_value_id, a.building_condition_value_id, (select count(*) from orphan_guardianships g where g.guardian_id = gg.id and g.status = 'approved') as children,
                    exists (select 1 from registry_addresses d where d.person_id = p.id and d.kind = 'displacement' and d.ends_on is null) as displaced");
            foreach ($query->cursor() as $r) {
                $rows['g:'.$r->id] = (array) $r;
            }
        }
        // Economics: the household's current assessment, else the guardian's (as of its own date, never copied).
        $householdIds = array_values(array_filter(array_column($rows, 'household_id')));
        if ($campaign->beneficiary_unit === 'household') {
            $householdIds = array_map(fn ($k) => (int) substr($k, 2), array_keys($rows));
        }
        $householdEcon = $this->latestEconomics('household_id', $householdIds);
        $guardianPersons = $campaign->beneficiary_unit === 'guardian' ? array_column($rows, 'person_id')
            : DB::table('orphan_guardians')->whereIn('id', array_values(array_filter(array_column($rows, 'guardian_id'))))->pluck('person_id', 'id')->all();
        $personEcon = $this->latestEconomics('person_id', array_values($guardianPersons));
        $needs = $this->latestNeeds($campaign->beneficiary_unit === 'orphan' ? array_map(fn ($k) => (int) substr($k, 2), array_keys($rows)) : [], $householdIds);

        $facts = [];
        $today = [now()->format('Y'), now()->format('m-d')];
        foreach ($rows as $key => $r) {
            $kind = $key[0];
            $id = (int) substr($key, 2);
            $household = $kind === 'h' ? $id : ($r['household_id'] ?? null);
            $guardianPerson = $kind === 'g' ? $r['person_id'] : ($guardianPersons[$r['guardian_id'] ?? 0] ?? null);
            $economic = $household && isset($householdEcon[$household]) ? $householdEcon[$household] + ['owner' => 'الأسرة'] : ($guardianPerson && isset($personEcon[$guardianPerson]) ? $personEcon[$guardianPerson] + ['owner' => 'الوصي'] : null);
            // Full years from the ISO date string (no date object per row: this loop runs for every candidate).
            $age = ! empty($r['date_of_birth']) ? (int) $today[0] - (int) substr($r['date_of_birth'], 0, 4) - (substr($r['date_of_birth'], 5, 5) > $today[1] ? 1 : 0) : null;
            $need = $kind === 'o' ? ($needs['orphan'][$id] ?? ($household ? ($needs['household'][$household] ?? null) : null)) : ($needs['household'][$household] ?? null);
            $dependents = $economic['dependents_count'] ?? ($kind === 'h' ? $r['dependents_count'] : null);
            $facts[$key] = [
                'unit' => ['orphan_id' => $kind === 'o' ? $id : null, 'household_id' => $kind === 'h' ? $id : null, 'guardian_id' => $kind === 'g' ? $id : null],
                'governorate_value_id' => $r['governorate_value_id'] ? (int) $r['governorate_value_id'] : null, 'locality_value_id' => $r['locality_value_id'] ? (int) $r['locality_value_id'] : null,
                'age' => $age, 'sex' => $r['sex'] ?? null, 'class_value_id' => isset($r['orphan_class_value_id']) ? (int) $r['orphan_class_value_id'] ?: null : null,
                'file_status' => $r['status'] ?? 'active', 'registered_on' => $r['registered_on'] ?? null, 'displaced' => (bool) $r['displaced'],
                'approved_guardian' => (bool) ($r['approved_guardian'] ?? true), 'recipient_guardian_id' => $r['guardian_id'] ?? null, 'sponsored' => (bool) ($r['sponsored'] ?? false),
                'last_benefit' => $r['last_benefit'] ?? null, 'support_need' => (bool) ($r['support_need'] ?? false),
                'income_band_value_id' => $economic['income_band_value_id'] ?? null, 'income_source_label' => null,
                'father_income_band_value_id' => isset($r['father_income']) ? (int) $r['father_income'] ?: null : null, 'needs_level_value_id' => $need['needs_level_value_id'] ?? null, 'dependents' => $dependents !== null ? (int) $dependents : null,
                'economic' => $economic ? [$economic['owner'], $economic['source'], $economic['verification'], (string) $economic['as_of']] : null, 'needs_as_of' => $need['as_of'] ?? null,
                // Housing from the unit's current address, education from the child's latest record (null = missing, never «no need»).
                'tenure_value_id' => ! empty($r['tenure_value_id']) ? (int) $r['tenure_value_id'] : null, 'shelter_type_value_id' => ! empty($r['shelter_type_value_id']) ? (int) $r['shelter_type_value_id'] : null,
                'building_condition_value_id' => ! empty($r['building_condition_value_id']) ? (int) $r['building_condition_value_id'] : null,
                'education_status_value_id' => ! empty($r['education_status']) ? (int) $r['education_status'] : null,
            ];
            unset($rows[$key]);
        }

        return $facts;
    }

    /** The frozen snapshot stored with each candidate, derived from the facts (no second copy kept in memory). */
    public static function snapshot(array $f, string $takenAt): array
    {
        return ['age' => $f['age'], 'sex' => $f['sex'], 'governorate' => $f['governorate_value_id'], 'locality' => $f['locality_value_id'], 'class' => $f['class_value_id'],
            'file_status' => $f['file_status'], 'displaced' => $f['displaced'], 'approved_guardian' => $f['approved_guardian'], 'sponsored' => $f['sponsored'],
            'last_benefit' => $f['last_benefit'], 'support_need' => $f['support_need'],
            'economic' => $f['economic'] ? ['owner' => $f['economic'][0], 'income_band' => $f['income_band_value_id'], 'dependents' => $f['dependents'], 'source' => $f['economic'][1],
                'verification' => $f['economic'][2], 'as_of' => $f['economic'][3]] : null,
            'father_pre_death_income' => $f['father_income_band_value_id'], 'needs' => $f['needs_level_value_id'] ? ['needs_level_value_id' => $f['needs_level_value_id'], 'as_of' => $f['needs_as_of']] : null,
            'housing' => ['tenure' => $f['tenure_value_id'] ?? null, 'shelter_type' => $f['shelter_type_value_id'] ?? null, 'building_condition' => $f['building_condition_value_id'] ?? null],
            'education_status' => $f['education_status_value_id'] ?? null,
            'taken_at' => $takenAt];
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function latestEconomics(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('economic_assessments')->whereIn($column, array_unique($ids))->where('period', 'current')->orderBy($column)->orderByDesc('id')
            ->selectRaw("distinct on ($column) $column as owner_id, income_band_value_id, dependents_count, source, verification, coalesce(reference_date, created_at::date) as as_of")->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->owner_id => (array) $r])->all();
    }

    private function latestNeeds(array $orphanIds, array $householdIds): array
    {
        $today = today()->toDateString();
        // Only a needs level the head reviewed and approved feeds the score (ORP-19; req-04 §7 step 3-4).
        $reviewed = Schema::hasColumn('social_assessments', 'review_outcome');
        $pick = fn (string $column, array $ids) => $ids === [] ? [] : DB::table('social_assessments')->whereIn($column, array_unique($ids))->where('status', 'completed')->whereNotNull('needs_level_value_id')
            ->when($reviewed, fn ($q) => $q->where('review_outcome', 'approved'))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))->orderBy($column)->orderByDesc('completed_on')->orderByDesc('id')
            ->selectRaw("distinct on ($column) $column as owner_id, needs_level_value_id, completed_on as as_of")->get()->mapWithKeys(fn ($r) => [(int) $r->owner_id => (array) $r])->all();

        return ['orphan' => $pick('orphan_id', $orphanIds), 'household' => $pick('household_id', $householdIds)];
    }

    /** Same-kind assistance in the window: other campaigns' approved lists (not yet delivered) and confirmed benefits. */
    private function duplicates(object $campaign, array $keys, int $days): array
    {
        $since = today()->subDays($days)->toDateString();
        $result = [];
        $other = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->join('campaigns as c', 'c.id', '=', 'l.campaign_id')
            ->where('l.status', 'approved')->where('c.id', '<>', $campaign->id)->whereNotIn('c.status', ['closed', 'cancelled'])
            ->when($campaign->aid_type_value_id, fn ($q) => $q->where('c.aid_type_value_id', $campaign->aid_type_value_id))->whereIn('e.unit_key', $keys)
            ->get(['e.unit_key', 'c.id', 'c.reference_no', 'c.title']);
        foreach ($other as $o) {
            $result[$o->unit_key][] = ['kind' => 'other_campaign_list', 'campaign_id' => $o->id, 'detail' => 'مدرج في كشف معتمد للحملة '.$o->reference_no.' ('.$o->title.')'];
        }
        $benefits = DB::table('benefit_records as b')->join('campaigns as c', 'c.id', '=', 'b.campaign_id')->where('b.kind', 'delivery')->where('b.delivered_on', '>=', $since)
            ->when($campaign->aid_type_value_id, fn ($q) => $q->where('c.aid_type_value_id', $campaign->aid_type_value_id))
            ->get(['b.id', 'b.orphan_id', 'b.household_id', 'b.delivered_on', 'c.reference_no']);
        foreach ($benefits as $b) {
            $key = $b->orphan_id ? 'o:'.$b->orphan_id : 'h:'.$b->household_id;
            if (in_array($key, $keys, true)) {
                $result[$key][] = ['kind' => 'recent_benefit', 'benefit_id' => $b->id, 'detail' => 'استلم مساعدة من النوع نفسه بتاريخ '.$b->delivered_on.' (الحملة '.$b->reference_no.')'];
            }
        }

        return $result;
    }
}
