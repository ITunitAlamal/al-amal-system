<?php

namespace App\Services\Relief;

use App\Models\OrphanChild;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\ScoringEngine;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Builds a campaign's beneficiary roster. Hard eligibility gates are checked first and separately from ranking; the
 * campaign's own approved priority ruleset then scores eligible units (the general social score is never reused as
 * the campaign score, only as one possible input). Siblings never double-benefit when the unit is the household.
 * Exceptions approved by the executive director are placed first; the rest are ranked up to the campaign's capacity,
 * the others wait-listed or excluded with their reasons. The revision is hashed and frozen once submitted.
 */
final class CampaignRosters
{
    public const DECISION_LABELS = ['selected' => 'مختار', 'waitlist' => 'قائمة الانتظار', 'excluded' => 'مستبعد'];

    public const STATUS_LABELS = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار القرار', 'approved' => 'معتمد', 'superseded' => 'مستبدل بمراجعة أحدث', 'rejected' => 'مرفوض'];

    public function __construct(
        private readonly ReliefAccess $access,
        private readonly ReliefCampaigns $campaigns,
        private readonly ScoringEngine $engine,
        private readonly AuditService $audit,
    ) {}

    public function generate(User $actor, int $campaignId): int
    {
        $this->access->assertPrepare($actor);

        return DB::transaction(function () use ($actor, $campaignId): int {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign, 404);
            if (! in_array($campaign->status, ['approved', 'active'], true)) {
                throw ValidationException::withMessages(['roster' => 'يُعد الكشف لحملة معتمدة من المدير التنفيذي فقط.']);
            }
            $ruleset = DB::table('scoring_rulesets')->where('kind', 'campaign')->where('campaign_id', $campaignId)->where('status', 'approved')->first();
            if (! $ruleset) {
                throw ValidationException::withMessages(['roster' => 'قواعد أولوية هذه الحملة غير معتمدة من المدير التنفيذي بعد؛ لا تُستخدم أوزان غير معتمدة.']);
            }
            if (DB::table('relief_rosters')->where('campaign_id', $campaignId)->where('status', 'pending_approval')->exists()) {
                throw ValidationException::withMessages(['roster' => 'يوجد كشف بانتظار القرار؛ لا تُعد مراجعة جديدة قبل البت فيه.']);
            }
            DB::table('relief_exceptions')->where('campaign_id', $campaignId)->where('status', 'approved')->whereNotNull('expires_on')
                ->where('expires_on', '<', today()->toDateString())->update(['status' => 'expired', 'updated_at' => now()]);
            $draft = DB::table('relief_rosters')->where('campaign_id', $campaignId)->where('status', 'draft')->first();
            if ($draft) {
                DB::table('relief_roster_entries')->where('roster_id', $draft->id)->delete();
            }
            $revision = $draft->revision ?? ((int) DB::table('relief_rosters')->where('campaign_id', $campaignId)->max('revision') + 1);
            $rosterId = $draft->id ?? DB::table('relief_rosters')->insertGetId(['campaign_id' => $campaignId, 'revision' => $revision, 'status' => 'draft',
                'ruleset_id' => $ruleset->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

            $entries = $this->evaluate($campaign, $ruleset);
            $exceptions = DB::table('relief_exceptions')->where('campaign_id', $campaignId)->where('status', 'approved')->get()->keyBy('unit_key');
            // Ranked units fill the roster cap; executive exceptions may go above that cap but never above what the
            // approved quantities and budget can physically serve.
            $capacity = $this->campaigns->capacity($campaignId);
            $resources = $this->campaigns->resourceCapacity($campaignId);
            if ($resources !== null && $exceptions->count() > $resources) {
                throw ValidationException::withMessages(['roster' => "الاستثناءات المعتمدة ({$exceptions->count()}) تتجاوز ما تكفيه كميات الحملة وميزانيتها ({$resources})؛ يلزم تعديل الحملة أولًا."]);
            }
            $cap = DB::table('relief_campaigns')->where('id', $campaignId)->value('roster_cap');
            $slots = min($cap ? (int) $cap : PHP_INT_MAX, $resources === null ? PHP_INT_MAX : $resources - $exceptions->count());
            foreach ($entries as &$entry) {
                $exception = $exceptions->get($entry['unit_key']);
                if ($exception) {
                    $entry['decision'] = 'selected';
                    $entry['exception_id'] = $exception->id;
                    $entry['reason'] = 'استثناء معتمد من المدير التنفيذي '.$exception->reference_no;
                } elseif ($entry['eligibility'] === 'eligible' && $slots > 0) {
                    $entry['decision'] = 'selected';
                    $slots--;
                } elseif ($entry['eligibility'] === 'eligible') {
                    $entry['decision'] = 'waitlist';
                    $entry['reason'] = 'مؤهل خارج طاقة الحملة؛ في قائمة الانتظار بترتيبه.';
                } else {
                    $entry['decision'] = 'excluded';
                    $entry['reason'] = implode(' ', $entry['eligibility_reasons']);
                }
            }
            unset($entry);
            foreach ($exceptions as $unitKey => $exception) {
                if (! collect($entries)->contains('unit_key', $unitKey)) {
                    $entries[] = $this->exceptionEntry($campaign, $exception);
                }
            }
            $rows = [];
            foreach ($entries as $entry) {
                $row = $entry + ['roster_id' => $rosterId, 'pseudonym' => $this->pseudonym($campaignId, $entry['unit_key'])];
                $row['entry_hash'] = $this->entryHash($row);
                $rows[] = $row;
            }
            usort($rows, fn ($a, $b) => strcmp($a['unit_key'], $b['unit_key']));
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('relief_roster_entries')->insert(array_map(fn ($row) => [
                    ...$row, 'eligibility_reasons' => json_encode($row['eligibility_reasons'], JSON_UNESCAPED_UNICODE),
                    'score_components' => json_encode($row['score_components'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'inputs' => json_encode($row['inputs'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            $counts = collect($rows)->countBy('decision');
            DB::table('relief_rosters')->where('id', $rosterId)->update(['ruleset_id' => $ruleset->id, 'candidate_count' => count($rows),
                'selected_count' => $counts['selected'] ?? 0, 'waitlist_count' => $counts['waitlist'] ?? 0, 'excluded_count' => $counts['excluded'] ?? 0,
                'capacity' => $capacity, 'snapshot_hash' => $this->snapshotHash($campaignId, $revision, $ruleset->factors_hash, $rows), 'updated_at' => now()]);
            $this->audit->recordRow('relief.roster.generated', 'relief_rosters', $rosterId, null, ['campaign' => $campaign->reference_no, 'revision' => $revision,
                'ruleset_version' => $ruleset->version, 'selected' => $counts['selected'] ?? 0], $actor->id, request()?->ip());

            return $rosterId;
        });
    }

    /**
     * Eligibility, score and rank of every candidate unit.
     *
     * @return list<array<string, mixed>>
     */
    private function evaluate(object $campaign, object $ruleset): array
    {
        $gates = json_decode((string) $campaign->eligibility, true) ?: [];
        $factors = json_decode($ruleset->factors, true);
        $codes = array_column($factors, 'code');
        $cooling = DB::table('orphans_module_settings')->where('id', 1)->value('campaign_cooling_off_days');
        $classCodes = DB::table('master_data_values')->pluck('code', 'id');
        $children = OrphanChild::where('status', 'active')->whereNotNull('household_id')->orderBy('id')->get();
        $households = DB::table('orphan_households')->whereIn('id', $children->pluck('household_id')->unique())->get()->keyBy('id');
        $areas = DB::table('orphan_addresses')->where('kind', 'current')->whereNull('valid_to')->whereIn('household_id', $households->keys())->pluck('governorate_value_id', 'household_id');
        $unitIsHousehold = $campaign->unit_of_benefit === 'household';

        $perChild = [];
        foreach ($children as $child) {
            $household = $households[$child->household_id];
            $reasons = [];
            $age = $child->ageOn();
            if (isset($gates['min_age']) && $age < $gates['min_age']) {
                $reasons[] = "العمر {$age} أقل من الحد الأدنى {$gates['min_age']}.";
            }
            if (isset($gates['max_age']) && $age > $gates['max_age']) {
                $reasons[] = "العمر {$age} أعلى من الحد الأعلى {$gates['max_age']}.";
            }
            if (! empty($gates['orphan_classes']) && ! in_array($classCodes[$child->orphan_class_value_id] ?? null, $gates['orphan_classes'], true)) {
                $reasons[] = 'تصنيف اليتم خارج فئات الحملة.';
            }
            $missing = [];
            $area = $areas[$child->household_id] ?? null;
            if (! empty($gates['governorates'])) {
                if ($area === null) {
                    $missing[] = 'عنوان السكن الحالي غير مكتمل لتحديد النطاق الجغرافي.';
                } elseif (! in_array((int) $area, array_map('intval', $gates['governorates']), true)) {
                    $reasons[] = 'السكن الحالي خارج النطاق الجغرافي للحملة.';
                }
            }
            if (! empty($gates['displaced_only']) && ! $household->displaced) {
                $reasons[] = 'الحملة للأسر النازحة فقط.';
            }
            $inputs = $this->engine->inputs($child, $codes, (int) $campaign->id, $cooling ? (int) $cooling : null);
            $result = $this->engine->evaluate($factors, $inputs);
            if (! $result['complete']) {
                $missing[] = 'بيانات لازمة للتقييم ناقصة (معالجتها «تعليق»).';
            }
            $perChild[] = ['child' => $child, 'household' => $household, 'area' => $area, 'reasons' => $reasons, 'missing' => $missing,
                'inputs' => $inputs, 'score' => $result];
        }

        $units = [];
        $grouped = $unitIsHousehold ? collect($perChild)->groupBy(fn ($row) => 'household:'.$row['household']->id) : collect($perChild)->keyBy(fn ($row) => 'child:'.$row['child']->id)->map(fn ($row) => collect([$row]));
        foreach ($grouped as $unitKey => $rows) {
            // A household is eligible when one of its children passes every gate; its score is that of its most vulnerable eligible child.
            $eligible = $rows->filter(fn ($row) => $row['reasons'] === [] && $row['missing'] === []);
            $passesGates = $rows->filter(fn ($row) => $row['reasons'] === []);
            $best = ($eligible->isNotEmpty() ? $eligible : ($passesGates->isNotEmpty() ? $passesGates : $rows))->sortByDesc(fn ($row) => $row['score']['total'])->first();
            $reasons = $eligible->isNotEmpty() ? [] : array_values(array_unique(($passesGates->isNotEmpty() ? $passesGates->pluck('missing') : $rows->pluck('reasons'))->flatten()->all()));
            $state = $eligible->isNotEmpty() ? 'eligible' : ($passesGates->isNotEmpty() ? 'incomplete' : 'ineligible');
            $householdId = (int) $best['household']->id;
            $blocking = $this->concurrentAllocation((int) $campaign->id, $unitKey, $householdId, $best['child']->id, $campaign->type_value_id, $cooling ? (int) $cooling : null);
            if ($blocking) {
                $reasons[] = $blocking;
                $state = 'ineligible';
            }
            $units[] = [
                'unit_type' => $unitIsHousehold ? 'household' : 'child', 'orphan_child_id' => $unitIsHousehold ? null : $best['child']->id,
                'household_id' => $householdId, 'unit_key' => $unitKey, 'area_value_id' => $best['area'] ? (int) $best['area'] : null,
                'eligibility' => $state,
                'eligibility_reasons' => $reasons, 'score_total' => $reasons === [] ? $best['score']['total'] : null,
                'score_components' => ['complete' => $best['score']['complete'], 'factors' => $best['score']['components'], 'scored_child' => $unitIsHousehold ? $best['child']->file_no : null],
                'inputs' => $best['inputs'], 'file_no' => $best['child']->file_no, 'rank_overall' => null, 'rank_area' => null, 'exception_id' => null, 'reason' => null,
            ];
        }
        $tieBreakers = json_decode((string) $ruleset->tie_breakers, true) ?: [];
        $eligible = array_values(array_filter($units, fn ($u) => $u['eligibility'] === 'eligible'));
        usort($eligible, function ($a, $b) use ($tieBreakers): int {
            if ($a['score_total'] != $b['score_total']) {
                return $b['score_total'] <=> $a['score_total'];
            }
            foreach ($tieBreakers as $code) {
                if ($code === 'file_no') {
                    $order = strcmp((string) $a['file_no'], (string) $b['file_no']);
                } else {
                    $order = ((float) ($b['inputs'][$code] ?? 0)) <=> ((float) ($a['inputs'][$code] ?? 0));
                }
                if ($order !== 0) {
                    return $order;
                }
            }

            return strcmp($a['unit_key'], $b['unit_key']);
        });
        $areaRanks = [];
        foreach ($eligible as $rank => $unit) {
            $key = $unit['unit_key'];
            $area = (string) ($unit['area_value_id'] ?? 'none');
            $areaRanks[$area] = ($areaRanks[$area] ?? 0) + 1;
            foreach ($units as &$candidate) {
                if ($candidate['unit_key'] === $key) {
                    $candidate['rank_overall'] = $rank + 1;
                    $candidate['rank_area'] = $areaRanks[$area];
                }
            }
            unset($candidate);
        }
        usort($units, fn ($a, $b) => [$a['rank_overall'] === null, $a['rank_overall']] <=> [$b['rank_overall'] === null, $b['rank_overall']]);

        return array_map(fn ($u) => array_diff_key($u, ['file_no' => true]), $units);
    }

    /** Why a unit cannot receive again: an open allocation in another live campaign of the same type, or a recent delivery. */
    private function concurrentAllocation(int $campaignId, string $unitKey, int $householdId, int $childId, ?int $typeId, ?int $coolingDays): ?string
    {
        $open = DB::table('relief_allocations as a')->join('relief_campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->where('a.campaign_id', '<>', $campaignId)->whereIn('c.status', ['approved', 'active', 'suspended'])
            ->when($typeId, fn ($q) => $q->where('c.type_value_id', $typeId))
            ->whereIn('a.status', ['reserved', 'assigned'])
            ->where(fn ($q) => $q->where('a.household_id', $householdId)->orWhere('a.orphan_child_id', $childId))->value('c.reference_no');
        if ($open) {
            return "مخصص له في حملة قائمة أخرى ({$open})؛ يلزم استثناء لمنع الازدواج.";
        }
        if ($coolingDays) {
            $recent = DB::table('relief_delivery_events as e')->join('relief_allocations as a', 'a.id', '=', 'e.allocation_id')
                ->where('a.campaign_id', '<>', $campaignId)->where('e.status', 'confirmed')->whereIn('e.kind', ['delivered', 'partial'])
                ->where('e.occurred_at', '>=', now()->subDays($coolingDays))
                ->where(fn ($q) => $q->where('a.household_id', $householdId)->orWhere('a.orphan_child_id', $childId))->exists();
            if ($recent) {
                return "استلم مساعدة خلال آخر {$coolingDays} يومًا (فترة التهدئة المعتمدة).";
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function exceptionEntry(object $campaign, object $exception): array
    {
        $area = DB::table('orphan_addresses')->where('household_id', $exception->household_id)->where('kind', 'current')->whereNull('valid_to')->value('governorate_value_id');

        return ['unit_type' => $exception->unit_type, 'orphan_child_id' => $exception->orphan_child_id, 'household_id' => (int) $exception->household_id,
            'unit_key' => $exception->unit_key, 'area_value_id' => $area ? (int) $area : null, 'eligibility' => 'ineligible',
            'eligibility_reasons' => ['خارج قواعد الحملة: '.$exception->rule_violated], 'score_total' => null, 'score_components' => ['complete' => false, 'factors' => []],
            'inputs' => [], 'rank_overall' => null, 'rank_area' => null, 'decision' => 'selected', 'exception_id' => $exception->id,
            'reason' => 'استثناء معتمد من المدير التنفيذي '.$exception->reference_no];
    }

    public function submit(User $actor, int $rosterId, WorkflowEngine $workflow): void
    {
        $this->access->assertPrepare($actor);
        DB::transaction(function () use ($actor, $rosterId, $workflow): void {
            $roster = DB::table('relief_rosters')->where('id', $rosterId)->lockForUpdate()->first();
            abort_unless($roster, 404);
            if ($roster->status !== 'draft' || $roster->workflow_instance_id) {
                throw ValidationException::withMessages(['roster' => 'الكشف مرفوع مسبقًا.']);
            }
            if ((int) $roster->selected_count === 0) {
                throw ValidationException::withMessages(['roster' => 'لا يوجد مستفيدون مختارون في الكشف.']);
            }
            $this->assertIntact($roster);
            $instance = $workflow->startForSubject($actor, 'RELIEF_ROSTER', ReliefRosterWorkflow::SUBJECT_TYPE, $rosterId, $this->access->orphans()->departmentId());
            DB::table('relief_rosters')->where('id', $rosterId)->update(['status' => 'pending_approval', 'submitted_at' => now(), 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /**
     * Applies the executive approval: the previous approved revision is superseded, allocations are reserved for the
     * selected units (carried over when already reserved), units that left the roster lose their reservation, and the
     * total never exceeds the campaign's planned quantities. Runs inside the workflow move.
     */
    public function approve(int $rosterId, int $actorId): void
    {
        $roster = DB::table('relief_rosters')->where('id', $rosterId)->lockForUpdate()->first();
        $campaign = DB::table('relief_campaigns')->where('id', $roster->campaign_id)->lockForUpdate()->first();
        if (! in_array($campaign->status, ['approved', 'active'], true)) {
            throw ValidationException::withMessages(['roster' => 'الحملة ليست في حالة تسمح بصرف مخصصات.']);
        }
        $this->assertIntact($roster);
        $items = DB::table('relief_campaign_items')->where('campaign_id', $campaign->id)->get()->keyBy('id');
        $entries = DB::table('relief_roster_entries')->where('roster_id', $rosterId)->get();
        $selected = $entries->where('decision', 'selected')->keyBy('unit_key');
        $open = DB::table('relief_allocations')->where('campaign_id', $campaign->id)->where('status', '<>', 'cancelled')->lockForUpdate()->get()->keyBy('unit_key');
        foreach ($open as $unitKey => $allocation) {
            if (! $selected->has($unitKey) && in_array($allocation->status, ['reserved', 'assigned'], true)) {
                if ($allocation->batch_id && DB::table('relief_batches')->where('id', $allocation->batch_id)->whereIn('status', ['assigned', 'downloaded'])->exists()) {
                    throw ValidationException::withMessages(['roster' => 'مستفيد خرج من الكشف ما زال في دفعة ميدانية قائمة؛ يلغي رئيس القسم الدفعة أولًا.']);
                }
                DB::table('relief_allocations')->where('id', $allocation->id)->update(['status' => 'cancelled', 'status_reason' => 'خرج من الكشف المعتمد (المراجعة '.$roster->revision.')', 'updated_at' => now()]);
            }
        }
        foreach ($selected as $unitKey => $entry) {
            $existing = $open->get($unitKey);
            if ($existing && $existing->status !== 'cancelled') {
                DB::table('relief_allocations')->where('id', $existing->id)->update(['roster_id' => $rosterId, 'roster_entry_id' => $entry->id, 'updated_at' => now()]);

                continue;
            }
            $quantities = $items->mapWithKeys(fn ($item) => [(string) $item->id => (float) $item->quantity_per_unit])->all();
            if ($entry->exception_id) {
                $proposed = json_decode((string) DB::table('relief_exceptions')->where('id', $entry->exception_id)->value('proposed_items'), true) ?: [];
                $quantities = array_intersect_key(array_map('floatval', $proposed), $quantities) ?: $quantities;
            }
            DB::table('relief_allocations')->insert(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'roster_id' => $rosterId, 'roster_entry_id' => $entry->id,
                'unit_key' => $unitKey, 'orphan_child_id' => $entry->orphan_child_id, 'household_id' => $entry->household_id,
                'items' => json_encode($quantities), 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertWithinPlan((int) $campaign->id, $items);
        DB::table('relief_rosters')->where('campaign_id', $campaign->id)->where('status', 'approved')->update(['status' => 'superseded', 'updated_at' => now()]);
        DB::table('relief_rosters')->where('id', $rosterId)->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now()]);
        DB::table('relief_exceptions')->whereIn('id', $selected->pluck('exception_id')->filter())->where('status', 'approved')
            ->update(['status' => 'applied', 'applied_roster_id' => $rosterId, 'updated_at' => now()]);
    }

    /** Recomputes every entry hash and the snapshot hash; any silent change to a stored roster stops it. */
    public function assertIntact(object $roster): void
    {
        $rows = DB::table('relief_roster_entries')->where('roster_id', $roster->id)->orderBy('unit_key')->get()->map(fn ($e) => $this->decodedEntry($e))->all();
        foreach ($rows as $row) {
            if (! hash_equals($row['entry_hash'], $this->entryHash($row))) {
                throw ValidationException::withMessages(['roster' => 'بصمة أحد سطور الكشف لا تطابق؛ لا يُعتمد كشف معدّل خارج النظام.']);
            }
        }
        $ruleset = DB::table('scoring_rulesets')->where('id', $roster->ruleset_id)->value('factors_hash');
        if (! hash_equals((string) $roster->snapshot_hash, $this->snapshotHash((int) $roster->campaign_id, (int) $roster->revision, (string) $ruleset, $rows))) {
            throw ValidationException::withMessages(['roster' => 'بصمة الكشف لا تطابق محتواه.']);
        }
    }

    private function assertWithinPlan(int $campaignId, Collection $items): void
    {
        $allocations = DB::table('relief_allocations')->where('campaign_id', $campaignId)->where('status', '<>', 'cancelled')->pluck('items');
        foreach ($items as $item) {
            $total = $allocations->sum(fn ($json) => (float) (json_decode($json, true)[(string) $item->id] ?? 0));
            if ($total > (float) $item->planned_total + 0.0001) {
                throw ValidationException::withMessages(['roster' => "مجموع «{$item->label}» المخصص ({$total}) يتجاوز الكمية المعتمدة ({$item->planned_total})."]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodedEntry(object $entry): array
    {
        $row = (array) $entry;
        foreach (['eligibility_reasons', 'score_components', 'inputs'] as $field) {
            $row[$field] = json_decode((string) $row[$field], true);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public function entryHash(array $row): string
    {
        $canonical = [
            'unit_key' => $row['unit_key'], 'unit_type' => $row['unit_type'], 'child' => $row['orphan_child_id'] === null ? null : (int) $row['orphan_child_id'],
            'household' => (int) $row['household_id'], 'area' => $row['area_value_id'] === null ? null : (int) $row['area_value_id'], 'eligibility' => $row['eligibility'],
            'reasons' => array_values((array) $row['eligibility_reasons']), 'score' => $row['score_total'] === null ? null : number_format((float) $row['score_total'], 2, '.', ''),
            'components' => $this->sortKeys((array) $row['score_components']), 'inputs' => $this->sortKeys((array) $row['inputs']), 'rank' => $row['rank_overall'] === null ? null : (int) $row['rank_overall'],
            'rank_area' => $row['rank_area'] === null ? null : (int) $row['rank_area'], 'decision' => $row['decision'], 'exception' => $row['exception_id'] === null ? null : (int) $row['exception_id'],
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param list<array<string, mixed>> $rows */
    private function snapshotHash(int $campaignId, int $revision, string $rulesetHash, array $rows): string
    {
        usort($rows, fn ($a, $b) => strcmp($a['unit_key'], $b['unit_key']));

        return hash('sha256', $campaignId.'|'.$revision.'|'.$rulesetHash.'|'.implode(',', array_column($rows, 'entry_hash')));
    }

    private function pseudonym(int $campaignId, string $unitKey): string
    {
        return 'B-'.strtoupper(substr(hash_hmac('sha256', $campaignId.':'.$unitKey, (string) config('app.key')), 0, 8));
    }

    /** @param array<mixed> $value */
    private function sortKeys(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($v) => is_array($v) ? $this->sortKeys($v) : $this->scalar($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => is_array($v) ? $this->sortKeys($v) : $this->scalar($v), $value);
    }

    private function scalar(mixed $value): mixed
    {
        return is_float($value) || is_int($value) ? number_format((float) $value, 4, '.', '') : $value;
    }
}
