<?php

namespace App\Services\Orphans\Campaigns;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\StockExact;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Aid;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Registry;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: create → configure → simulate → draft list → head recommendation → EXECUTIVE DIRECTOR approval
 * → lock → Stores issue → field delivery → reconcile → close. Any change to an approved list is a change request
 * approved by the executive director only, producing a new immutable version.
 */
final class Campaigns
{
    public const STATUS = ['draft' => 'مسودة', 'list_draft' => 'كشف قيد الإعداد', 'pending_executive' => 'بانتظار اعتماد المدير التنفيذي', 'approved' => 'معتمد ومقفل',
        'distributing' => 'قيد التوزيع', 'reconciling' => 'قيد التسوية', 'closed' => 'مغلقة', 'cancelled' => 'ملغاة'];

    public const LIST_STATUS = ['draft' => 'مسودة', 'pending_executive' => 'بانتظار اعتماد المدير التنفيذي', 'approved' => 'معتمد ومقفل', 'superseded' => 'إصدار سابق', 'rejected' => 'مرفوض'];

    public function __construct(private readonly OrphanAccess $access, private readonly NominationEngine $engine, private readonly AuditService $audit,
        private readonly NotificationCenter $notifications) {}

    /* ---------- access ---------- */

    public function canView(?User $user, object $campaign): bool
    {
        return $this->access->canView($user) || $this->member($user, $campaign) !== null;
    }

    public function canPrepare(?User $user, object $campaign): bool
    {
        return $this->access->allows($user, 'campaigns') || in_array($this->member($user, $campaign), ['staff'], true);
    }

    public function canDeliver(?User $user, object $campaign): bool
    {
        return $this->access->allows($user, 'delivery') || $this->access->allows($user, 'campaigns') || in_array($this->member($user, $campaign), ['staff', 'distributor'], true);
    }

    public function member(?User $user, object $campaign): ?string
    {
        if (! $user) {
            return null;
        }

        return DB::table('campaign_members')->where('campaign_id', $campaign->id)->where('user_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))->value('role');
    }

    /* ---------- wizard ---------- */

    public function saveBasics(?object $campaign, array $data, array $items, User $actor): object
    {
        $this->access->assert($actor, 'campaigns');
        if ($campaign && ! in_array($campaign->status, ['draft', 'list_draft'], true)) {
            throw ValidationException::withMessages(['status' => 'لا تُعدّل أساسيات حملة بعد رفع كشفها.']);
        }

        return DB::transaction(function () use ($campaign, $data, $items, $actor): object {
            $fill = collect($data)->only(['title', 'description', 'donor_name', 'sponsor_id', 'aid_type_value_id', 'beneficiary_unit', 'starts_on', 'ends_on', 'warehouse_id', 'restricted_fund_id', 'manager_user_id'])->all();
            if ($campaign) {
                abort_if($campaign->beneficiary_unit !== ($fill['beneficiary_unit'] ?? $campaign->beneficiary_unit) && DB::table('campaign_runs')->where('campaign_id', $campaign->id)->exists(), 409,
                    'لا يُغيّر نوع وحدة الاستفادة بعد تشغيل المحاكاة؛ أنشئ حملة جديدة.');
                DB::table('campaigns')->where('id', $campaign->id)->update([...$fill, 'wizard_step' => max(2, (int) $campaign->wizard_step), 'updated_at' => now()]);
                $id = $campaign->id;
            } else {
                $id = DB::table('campaigns')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('CMP', 'campaign_seq'), ...$fill, 'status' => 'draft',
                    'wizard_step' => 2, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $keep = [];
            foreach ($items as $item) {
                $row = ['stock_item_id' => $item['stock_item_id'] ?? null, 'label' => $item['label'], 'variant' => $item['variant'] ?? null, 'per_unit_quantity' => $item['per_unit_quantity'] ?? 1,
                    'total_quantity' => $item['total_quantity'] ?? 0, 'criteria' => json_encode(array_filter($item['criteria'] ?? [], fn ($v) => $v !== null && $v !== '')), 'updated_at' => now()];
                if (! empty($item['id']) && DB::table('campaign_items')->where('id', $item['id'])->where('campaign_id', $id)->exists()) {
                    DB::table('campaign_items')->where('id', $item['id'])->update($row);
                    $keep[] = (int) $item['id'];
                } else {
                    $keep[] = DB::table('campaign_items')->insertGetId([...$row, 'campaign_id' => $id, 'created_at' => now()]);
                }
            }
            $removable = DB::table('campaign_items')->where('campaign_id', $id)->whereNotIn('id', $keep ?: [0])->pluck('id');
            foreach ($removable as $itemId) {
                abort_if(DB::table('campaign_candidates')->where('item_id', $itemId)->exists(), 409, 'صنف مستخدم في محاكاة سابقة؛ اضبط كميته على صفر بدل حذفه.');
                DB::table('campaign_items')->where('id', $itemId)->delete();
            }
            $this->audit->recordRow('orphans.campaign.saved', 'campaigns', $id, null, ['title' => $fill['title'] ?? null], $actor->id, request()?->ip());

            return DB::table('campaigns')->find($id);
        });
    }

    public function saveTargeting(object $campaign, array $targeting, array $quotas, User $actor): void
    {
        $this->assertDraft($campaign, $actor);
        DB::transaction(function () use ($campaign, $targeting, $quotas): void {
            DB::table('campaigns')->where('id', $campaign->id)->update(['targeting' => json_encode($targeting), 'wizard_step' => max(3, (int) $campaign->wizard_step), 'updated_at' => now()]);
            DB::table('campaign_quotas')->where('campaign_id', $campaign->id)->delete();
            foreach ($quotas as $q) {
                if (! empty($q['governorate_value_id']) && (int) ($q['quantity'] ?? 0) > 0) {
                    DB::table('campaign_quotas')->insert(['campaign_id' => $campaign->id, 'governorate_value_id' => $q['governorate_value_id'], 'locality_value_id' => $q['locality_value_id'] ?? null,
                        'quantity' => (int) $q['quantity'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function saveRules(object $campaign, array $rules, User $actor): int
    {
        $this->assertDraft($campaign, $actor);
        $id = $this->engine->saveRules($campaign, $rules, $actor);
        DB::table('campaigns')->where('id', $campaign->id)->update(['wizard_step' => max(4, (int) $campaign->wizard_step), 'updated_at' => now()]);

        return $id;
    }

    public function simulate(object $campaign, User $actor): object
    {
        $this->assertDraft($campaign, $actor);
        $rules = DB::table('campaign_rule_versions')->where('campaign_id', $campaign->id)->orderByDesc('version')->first();
        abort_unless($rules, 422, 'احفظ معايير الترشيح أولًا.');
        $run = $this->engine->run($campaign, (int) $rules->id, $actor);
        $this->audit->recordRow('orphans.campaign.simulated', 'campaigns', $campaign->id, null, ['run' => $run->public_id, 'counts' => json_decode($run->counts, true)], $actor->id, request()?->ip());

        return $run;
    }

    /** Simulation report: who, where, ages, scores, capacity vs stock, gaps, previous similar aid, change vs previous run. */
    public function report(object $campaign, object $run): array
    {
        $rows = DB::table('campaign_candidates')->where('run_id', $run->id);
        $byOutcome = (clone $rows)->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->all();
        $primary = (clone $rows)->whereIn('outcome', ['primary']);
        $byRegion = (clone $primary)->leftJoin('master_data_values as g', 'g.id', '=', 'campaign_candidates.governorate_value_id')
            ->selectRaw("coalesce(g.name_ar, 'غير محدد') as label, count(*) as n")->groupBy('g.name_ar')->orderByDesc('n')->get();
        $bands = app(RegistryQueries::class)->values('orphan_age_bands');
        $ages = (clone $primary)->pluck('age')->all();
        $byAge = array_map(function ($band) use ($ages): array {
            $meta = json_decode((string) DB::table('master_data_values')->where('id', $band['id'])->value('metadata'), true) ?: [];

            return ['label' => $band['name'], 'n' => count(array_filter($ages, fn ($a) => $a !== null && $a >= ($meta['min_age'] ?? 0) && ($meta['max_age'] === null || $a <= $meta['max_age'])))];
        }, $bands);
        $byAge[] = ['label' => 'غير متوفر', 'n' => count(array_filter($ages, fn ($a) => $a === null))];
        $scores = (clone $rows)->whereNotNull('score')->selectRaw('floor(score / 10) * 10 as bucket, count(*) as n')->groupBy('bucket')->orderBy('bucket')->pluck('n', 'bucket')->all();
        $missing = [];
        foreach ((clone $rows)->whereIn('outcome', ['review'])->pluck('missing') as $m) {
            foreach (json_decode((string) $m, true) ?: [] as $label) {
                $missing[$label] = ($missing[$label] ?? 0) + 1;
            }
        }
        $items = DB::table('campaign_items')->where('campaign_id', $campaign->id)->get()->map(fn ($i): array => ['label' => $i->label.($i->variant ? ' — '.$i->variant : ''),
            'planned' => (float) $i->total_quantity, 'allocated' => (float) DB::table('campaign_candidates')->where('run_id', $run->id)->where('outcome', 'primary')->where('item_id', $i->id)->count() * (float) $i->per_unit_quantity,
            'available' => $this->available($campaign, $i)])->all();
        $previous = DB::table('campaign_runs')->where('campaign_id', $campaign->id)->where('id', '<', $run->id)->orderByDesc('id')->first();
        $change = null;
        if ($previous) {
            $now = DB::table('campaign_candidates')->where('run_id', $run->id)->where('outcome', 'primary')->pluck('unit_key')->all();
            $before = DB::table('campaign_candidates')->where('run_id', $previous->id)->where('outcome', 'primary')->pluck('unit_key')->all();
            $change = ['added' => count(array_diff($now, $before)), 'removed' => count(array_diff($before, $now)), 'same_input' => $previous->input_hash === $run->input_hash];
        }
        $similar = $campaign->aid_type_value_id ? DB::table('benefit_records as b')->join('campaigns as c', 'c.id', '=', 'b.campaign_id')->where('b.kind', 'delivery')
            ->where('c.aid_type_value_id', $campaign->aid_type_value_id)->where('b.delivered_on', '>=', today()->subYear()->toDateString())->count() : null;

        return ['outcomes' => $byOutcome, 'regions' => $byRegion, 'ages' => $byAge, 'scores' => $scores, 'missing' => $missing, 'items' => $items, 'change' => $change,
            'duplicates' => DB::table('campaign_duplicate_alerts')->where('run_id', $run->id)->count(), 'similar_deliveries_last_year' => $similar,
            'input_hash' => $run->input_hash, 'rule_version' => DB::table('campaign_rule_versions')->where('id', $run->rule_version_id)->value('version')];
    }

    /** Quantity in the campaign's warehouse minus what other approved campaigns still need to issue. Null when not linked to Stores. */
    public function available(object $campaign, object $item): ?float
    {
        if (! $campaign->warehouse_id || ! $item->stock_item_id) {
            return null;
        }
        $balance = StockExact::balance((int) $campaign->warehouse_id, (int) $item->stock_item_id)['qty'] / 1000;
        $held = 0.0;
        foreach (DB::table('campaign_items as i')->join('campaigns as c', 'c.id', '=', 'i.campaign_id')->where('i.stock_item_id', $item->stock_item_id)->where('c.warehouse_id', $campaign->warehouse_id)
            ->where('c.id', '<>', $campaign->id)->whereIn('c.status', ['approved', 'distributing'])->get(['i.id', 'i.campaign_id']) as $other) {
            $needed = (float) DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('l.status', 'approved')->where('e.kind', 'primary')->where('e.item_id', $other->id)->sum('e.quantity');
            $issued = (float) DB::table('campaign_stock_issues')->where('item_id', $other->id)->selectRaw("coalesce(sum(case when kind = 'issue' then quantity else -quantity end), 0) as q")->value('q');
            $held += max(0, $needed - $issued);
        }

        return round($balance - $held, 3);
    }

    /* ---------- lists ---------- */

    public function draftFromRun(object $campaign, object $run, User $actor): object
    {
        $this->assertDraft($campaign, $actor);
        abort_if(DB::table('campaign_lists')->where('campaign_id', $campaign->id)->whereIn('status', ['approved', 'superseded'])->exists(), 409, 'للحملة كشف معتمد؛ أي تغيير يتم بطلب تعديل.');

        return DB::transaction(function () use ($campaign, $run, $actor): object {
            foreach (DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'draft')->pluck('id') as $old) {
                DB::table('campaign_list_entries')->where('list_id', $old)->delete();
                DB::table('campaign_lists')->where('id', $old)->delete();
            }
            $listId = DB::table('campaign_lists')->insertGetId(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'version' => $this->nextVersion($campaign->id), 'status' => 'draft',
                'source' => 'run', 'run_id' => $run->id, 'prepared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $position = 0;
            foreach (DB::table('campaign_candidates')->where('run_id', $run->id)->whereIn('outcome', ['primary', 'reserve'])->orderByRaw("CASE outcome WHEN 'primary' THEN 0 ELSE 1 END")->orderBy('rank')->get() as $c) {
                $snapshot = json_decode($c->snapshot, true);
                DB::table('campaign_list_entries')->insert(['list_id' => $listId, 'kind' => $c->outcome, 'position' => ++$position, 'orphan_id' => $c->orphan_id, 'household_id' => $c->household_id,
                    'guardian_id' => $c->guardian_id, 'unit_key' => $c->unit_key, 'recipient_guardian_id' => $c->orphan_id ? DB::table('orphan_guardianships')->where('orphan_id', $c->orphan_id)->where('status', 'approved')->orderByDesc('is_primary')->value('guardian_id') : $c->guardian_id,
                    'item_id' => $c->item_id, 'quantity' => $c->item_id ? DB::table('campaign_items')->where('id', $c->item_id)->value('per_unit_quantity') : 1, 'score' => $c->score,
                    'reason' => implode(' · ', json_decode((string) $c->reasons, true) ?: []), 'governorate_value_id' => $c->governorate_value_id, 'candidate_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->refreshList($listId);
            DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'list_draft', 'updated_at' => now()]);
            $this->audit->recordRow('orphans.campaign.list_drafted', 'campaign_lists', $listId, null, ['run' => $run->public_id], $actor->id, request()?->ip());

            return DB::table('campaign_lists')->find($listId);
        });
    }

    /**
     * Edits a DRAFT version (initial list or a change-request proposal) with an optimistic version check: a stale
     * edit is refused. Approved versions are locked in the database and cannot be reached from here.
     */
    public function editDraft(object $list, int $expectedLock, string $action, array $data, User $actor): void
    {
        $campaign = DB::table('campaigns')->find($list->campaign_id);
        abort_unless($this->canPrepare($actor, $campaign), 403, 'إعداد الكشوف يحتاج قدرة «إعداد الحملات والكشوف».');
        DB::transaction(function () use ($list, $expectedLock, $action, $data, $actor): void {
            $updated = DB::table('campaign_lists')->where('id', $list->id)->where('status', 'draft')->where('lock_version', $expectedLock)->update(['lock_version' => $expectedLock + 1, 'updated_at' => now()]);
            abort_if($updated === 0, 409, 'تغيّر الكشف منذ فتحه أو لم يعد مسودة؛ أعد تحميل الصفحة.');
            if (blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'سبب التعديل مطلوب.']);
            }
            match ($action) {
                'remove' => DB::table('campaign_list_entries')->where('list_id', $list->id)->where('id', $data['entry_id'] ?? 0)->delete(),
                'move' => DB::table('campaign_list_entries')->where('list_id', $list->id)->where('id', $data['entry_id'] ?? 0)->update(['kind' => $data['kind'] === 'reserve' ? 'reserve' : 'primary', 'updated_at' => now()]),
                'quantity' => DB::table('campaign_list_entries')->where('list_id', $list->id)->where('id', $data['entry_id'] ?? 0)->update(['quantity' => max(0.001, (float) ($data['quantity'] ?? 1)),
                    'item_id' => $data['item_id'] ?? DB::raw('item_id'), 'updated_at' => now()]),
                'add' => $this->addCandidate($list, (int) ($data['candidate_id'] ?? 0), $data['kind'] ?? 'primary'),
                default => abort(422),
            };
            DB::table('campaign_list_entries')->where('list_id', $list->id)->where('id', $data['entry_id'] ?? 0)->update(['reason' => DB::raw("coalesce(reason, '') || ' · تعديل: ' || ".DB::getPdo()->quote($data['reason']))]);
            $this->refreshList($list->id);
            $this->audit->recordRow('orphans.campaign.list_edited', 'campaign_lists', $list->id, null, ['action' => $action, 'reason' => $data['reason'], 'entry' => $data['entry_id'] ?? $data['candidate_id'] ?? null], $actor->id, request()?->ip());
        });
    }

    private function addCandidate(object $list, int $candidateId, string $kind): void
    {
        $c = DB::table('campaign_candidates as c')->join('campaign_runs as r', 'r.id', '=', 'c.run_id')->where('c.id', $candidateId)->where('r.campaign_id', $list->campaign_id)->first(['c.*']);
        abort_unless($c, 422, 'المرشح غير موجود في محاكاة هذه الحملة.');
        abort_if(in_array($c->outcome, ['ineligible'], true), 422, 'المرشح غير مؤهل وفق القواعد المنشورة.');
        DB::table('campaign_list_entries')->insert(['list_id' => $list->id, 'kind' => $kind === 'reserve' ? 'reserve' : 'primary', 'position' => (int) DB::table('campaign_list_entries')->where('list_id', $list->id)->max('position') + 1,
            'orphan_id' => $c->orphan_id, 'household_id' => $c->household_id, 'guardian_id' => $c->guardian_id, 'unit_key' => $c->unit_key,
            'recipient_guardian_id' => $c->orphan_id ? DB::table('orphan_guardianships')->where('orphan_id', $c->orphan_id)->where('status', 'approved')->orderByDesc('is_primary')->value('guardian_id') : $c->guardian_id,
            'item_id' => $c->item_id, 'quantity' => 1, 'score' => $c->score, 'reason' => 'أُضيف يدويًا من «'.(NominationEngine::OUTCOMES[$c->outcome] ?? $c->outcome).'»', 'governorate_value_id' => $c->governorate_value_id,
            'candidate_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * «رفع لرئيس القسم»: the preparer tells the head that a draft is ready. The list stays a draft (still editable);
     * only the head refers it to the executive director (submit). The request is kept on the draft, so it stays in
     * the head's work queue (CampaignExtension) whether or not the notification is delivered.
     */
    public function requestReview(object $list, ?string $note, User $actor): int
    {
        $campaign = DB::table('campaigns')->find($list->campaign_id);
        abort_unless($this->canPrepare($actor, $campaign), 403, 'إعداد الكشوف يحتاج قدرة «إعداد الحملات والكشوف».');
        abort_unless($list->status === 'draft' && $list->entry_count > 0, 409, 'لا يُرفع إلا كشف مسودة غير فارغ.');
        $heads = array_values(array_diff($this->access->headUserIds(), [(int) $actor->id]));
        abort_if($heads === [], 409, 'لا يوجد رئيس قسم (أو قائم بأعماله) يراجع الكشف؛ يلزم تعيينه في الهيكل.');
        $updated = DB::table('campaign_lists')->where('id', $list->id)->where('status', 'draft')->update(['review_requested_at' => now(), 'review_requested_by' => $actor->id, 'updated_at' => now()]);
        abort_if($updated === 0, 409, 'لم يعد الكشف مسودة؛ أعد تحميل الصفحة.');
        $this->audit->recordRow('orphans.campaign.list_review_requested', 'campaign_lists', $list->id, null, ['version' => $list->version, 'note' => $note], $actor->id, request()?->ip());

        return $this->notifications->notifyMany($heads, 'orphans_campaign_review', ['title' => 'كشف الحملة '.$campaign->reference_no.' (الإصدار '.$list->version.') جاهز لمراجعتك',
            'body' => $note, 'url' => '/orphans/campaigns/'.$campaign->public_id.'?tab=list&list='.$list->id, 'dedupe' => 'campaign-review-'.$list->id.'-'.$list->lock_version]);
    }

    /**
     * The head (or acting head) reviews the draft, recommends it and refers it to the executive director; it freezes
     * for review. The head's review is a mandatory stage of the approved campaign workflow (AIMS 2.0 spec), so a
     * preparer cannot send a list to the director directly.
     */
    public function submit(object $list, ?string $note, User $actor): void
    {
        $this->access->assertDecide($actor, 'رفع الكشف للمدير التنفيذي لرئيس القسم بعد مراجعته؛ يرفعه معدّ الحملة إلى رئيس القسم أولًا.');
        abort_unless($list->status === 'draft' && $list->entry_count > 0, 409, 'لا يُرفع إلا كشف مسودة غير فارغ.');
        DB::transaction(function () use ($list, $note, $actor): void {
            $locked = DB::table('campaign_lists')->where('id', $list->id)->where('status', 'draft')->lockForUpdate()->first();
            abort_unless($locked, 409, 'لم يعد الكشف مسودة؛ أعد تحميل الصفحة.');
            $this->assertEligibleEntries($locked);
            $this->refreshList($list->id);
            DB::table('campaign_lists')->where('id', $list->id)->update(['status' => 'pending_executive', 'submitted_at' => now(),
                'recommended_by' => $actor->id, 'recommended_at' => now(), 'recommendation_note' => $note, 'review_requested_at' => null, 'review_requested_by' => null, 'updated_at' => now()]);
            $request = DB::table('campaign_change_requests')->where('proposed_list_id', $list->id)->first();
            if ($request) {
                DB::table('campaign_change_requests')->where('id', $request->id)->update(['status' => 'pending', 'diff' => json_encode($this->diff($request->base_list_id, $list->id), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            } else {
                DB::table('campaigns')->where('id', $list->campaign_id)->update(['status' => 'pending_executive', 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.campaign.list_submitted', 'campaign_lists', $list->id, null, ['note' => $note], $actor->id, request()?->ip());
        });
        $campaign = DB::table('campaigns')->find($list->campaign_id);
        $this->notifications->notifyMany($this->access->executiveUserIds(), 'orphans_campaign_decision', ['title' => 'كشف الحملة '.$campaign->reference_no.' بانتظار اعتمادك',
            'url' => '/orphans/campaigns/'.$campaign->public_id.'?tab=approval', 'dedupe' => 'campaign-list-'.$list->id]);
    }

    /** Returns a submitted version to draft (the head who refers it, before the executive director decides). */
    public function withdraw(object $list, User $actor): void
    {
        $this->access->assertDecide($actor, 'سحب الكشف من المدير التنفيذي لرئيس القسم.');
        abort_unless($list->status === 'pending_executive', 409, 'الكشف ليس بانتظار القرار.');
        DB::table('campaign_lists')->where('id', $list->id)->update(['status' => 'draft', 'submitted_at' => null, 'recommended_by' => null, 'recommended_at' => null, 'lock_version' => $list->lock_version + 1, 'updated_at' => now()]);
        if (! DB::table('campaign_change_requests')->where('proposed_list_id', $list->id)->exists()) {
            DB::table('campaigns')->where('id', $list->campaign_id)->update(['status' => 'list_draft', 'updated_at' => now()]);
        } else {
            DB::table('campaign_change_requests')->where('proposed_list_id', $list->id)->update(['status' => 'draft', 'updated_at' => now()]);
        }
        $this->audit->recordRow('orphans.campaign.list_withdrawn', 'campaign_lists', $list->id, null, null, $actor->id, request()?->ip());
    }

    /**
     * EXECUTIVE DIRECTOR ONLY: approves or rejects the exact version (and its exact diff when it is a change request).
     * Only the original, officially designated director (head of EXEC holding the role there) decides; this is checked
     * here and again by the database trigger. No delegation, acting role or system-admin path.
     */
    public function decide(object $list, string $decision, ?string $note, string $expectedHash, User $actor): void
    {
        abort_unless($this->access->isDesignatedExecutive($actor), 403, 'اعتماد كشوف الحملات وتعديلاتها أو رفضها حصري للمدير التنفيذي المعيّن رسميًا.');
        if ($decision === 'reject' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'سبب الرفض مطلوب.']);
        }
        DB::transaction(function () use ($list, $decision, $note, $expectedHash, $actor): void {
            $list = DB::table('campaign_lists')->where('id', $list->id)->lockForUpdate()->first();
            abort_unless($list && $list->status === 'pending_executive', 409, 'الكشف ليس بانتظار قرار المدير التنفيذي.');
            abort_unless(hash_equals((string) $list->content_hash, $expectedHash), 409, 'تغيّر محتوى الكشف منذ عرضه؛ أعد مراجعته قبل القرار.');
            if ($decision === 'approve') {
                // No approval without the head's review (also enforced by the database guard, 000028).
                abort_unless($list->recommended_by && $list->recommended_at, 409, 'لم يراجع رئيس القسم هذا الكشف ولم يوصِ به؛ يسحبه رئيس القسم ويعيد رفعه بعد مراجعته.');
                $this->assertEligibleEntries($list);
            }
            $request = DB::table('campaign_change_requests')->where('proposed_list_id', $list->id)->lockForUpdate()->first();
            if ($decision === 'approve') {
                if ($request) {
                    DB::table('campaign_lists')->where('id', $request->base_list_id)->where('status', 'approved')->update(['status' => 'superseded', 'updated_at' => now()]);
                }
                DB::table('campaign_lists')->where('id', $list->id)->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
                $campaign = DB::table('campaigns')->find($list->campaign_id);
                DB::table('campaigns')->where('id', $list->campaign_id)->update(['status' => in_array($campaign->status, ['distributing', 'reconciling'], true) ? $campaign->status : 'approved', 'updated_at' => now()]);
            } else {
                DB::table('campaign_lists')->where('id', $list->id)->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
                if (! $request) {
                    DB::table('campaigns')->where('id', $list->campaign_id)->update(['status' => 'draft', 'updated_at' => now()]);
                }
            }
            if ($request) {
                DB::table('campaign_change_requests')->where('id', $request->id)->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(),
                    'decision_note' => $note, 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.campaign.list_'.($decision === 'approve' ? 'approved' : 'rejected'), 'campaign_lists', $list->id, null,
                ['version' => $list->version, 'hash' => $list->content_hash, 'note' => $note, 'change_request' => $request?->public_id], $actor->id, request()?->ip());
        });
        $campaign = DB::table('campaigns')->find($list->campaign_id);
        $this->notifications->notifyMany(array_filter([$list->prepared_by, $list->recommended_by, ...$this->access->headUserIds()]), 'orphans_campaign_update', [
            'title' => ($decision === 'approve' ? 'اعتمد المدير التنفيذي كشف ' : 'رفض المدير التنفيذي كشف ').$campaign->reference_no.' (الإصدار '.$list->version.')',
            'url' => '/orphans/campaigns/'.$campaign->public_id, 'dedupe' => 'campaign-decision-'.$list->id]);
    }

    /** Starts a change request: a draft copy of the approved version; the approved version itself is untouched. */
    public function requestChange(object $campaign, string $reason, ?int $appealId, User $actor): object
    {
        abort_unless($this->canPrepare($actor, $campaign), 403);
        $approved = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->first();
        abort_unless($approved, 409, 'لا يوجد كشف معتمد لتعديله.');
        abort_if(DB::table('campaign_lists')->where('campaign_id', $campaign->id)->whereIn('status', ['draft', 'pending_executive'])->exists(), 409, 'يوجد طلب تعديل مفتوح لهذه الحملة.');

        return DB::transaction(function () use ($campaign, $approved, $reason, $appealId, $actor): object {
            $listId = DB::table('campaign_lists')->insertGetId(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'version' => $this->nextVersion($campaign->id), 'status' => 'draft',
                'source' => 'change_request', 'run_id' => $approved->run_id, 'base_list_id' => $approved->id, 'prepared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::statement('INSERT INTO campaign_list_entries (list_id, kind, position, orphan_id, household_id, guardian_id, unit_key, recipient_guardian_id, item_id, quantity, score, reason, governorate_value_id, candidate_id, created_at, updated_at)
                SELECT ?, kind, position, orphan_id, household_id, guardian_id, unit_key, recipient_guardian_id, item_id, quantity, score, reason, governorate_value_id, candidate_id, now(), now() FROM campaign_list_entries WHERE list_id = ?', [$listId, $approved->id]);
            $this->refreshList($listId);
            DB::table('campaign_change_requests')->insert(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'base_list_id' => $approved->id, 'proposed_list_id' => $listId,
                'appeal_id' => $appealId, 'reason' => $reason, 'status' => 'draft', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.campaign.change_requested', 'campaign_lists', $listId, null, ['base' => $approved->version, 'reason' => $reason], $actor->id, request()?->ip());

            return DB::table('campaign_lists')->find($listId);
        });
    }

    /** Before/after diff between two versions, with the impact on goods already delivered. */
    public function diff(int $baseId, int $proposedId): array
    {
        $map = fn (int $id) => DB::table('campaign_list_entries')->where('list_id', $id)->get()->keyBy(fn ($e) => $e->unit_key.'#'.($e->item_id ?? 0));
        $base = $map($baseId);
        $proposed = $map($proposedId);
        $label = fn ($e) => $this->entryLabel($e);
        $delivered = DB::table('campaign_deliveries')->whereIn('list_entry_id', DB::table('campaign_list_entries')->where('list_id', $baseId)->pluck('id'))->where('kind', 'delivered')
            ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as c')->whereColumn('c.corrects_id', 'campaign_deliveries.id'))->pluck('unit_key')->all();
        $added = $proposed->diffKeys($base)->map(fn ($e) => ['unit' => $label($e), 'kind' => $e->kind, 'quantity' => (float) $e->quantity])->values()->all();
        $removed = $base->diffKeys($proposed)->map(fn ($e) => ['unit' => $label($e), 'kind' => $e->kind, 'quantity' => (float) $e->quantity, 'already_delivered' => in_array($e->unit_key, $delivered, true)])->values()->all();
        $changed = [];
        foreach ($proposed->intersectByKeys($base) as $key => $e) {
            $b = $base[$key];
            if ($b->kind !== $e->kind || (float) $b->quantity !== (float) $e->quantity) {
                $changed[] = ['unit' => $label($e), 'from' => $b->kind.' × '.(float) $b->quantity, 'to' => $e->kind.' × '.(float) $e->quantity, 'already_delivered' => in_array($e->unit_key, $delivered, true)];
            }
        }
        $totals = fn ($set) => ['primary' => $set->where('kind', 'primary')->count(), 'reserve' => $set->where('kind', 'reserve')->count(), 'quantity' => (float) $set->where('kind', 'primary')->sum('quantity')];

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'before' => $totals($base), 'after' => $totals($proposed),
            'delivered_affected' => count(array_filter($removed, fn ($r) => $r['already_delivered'])) + count(array_filter($changed, fn ($r) => $r['already_delivered']))];
    }

    public function entryLabel(object $e): string
    {
        return match (true) {
            (bool) $e->orphan_id => (string) DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('o.id', $e->orphan_id)->value(DB::raw("p.full_name || ' (' || o.reference_no || ')'")),
            (bool) $e->household_id => 'أسرة '.DB::table('households')->where('id', $e->household_id)->value('reference_no'),
            default => 'وصي '.DB::table('orphan_guardians as g')->join('persons as p', 'p.id', '=', 'g.person_id')->where('g.id', $e->guardian_id)->value('p.full_name'),
        };
    }

    /* ---------- access per campaign ---------- */

    public function grantMember(object $campaign, int $userId, string $role, ?string $endsOn, User $actor): void
    {
        abort_unless($this->access->canAssign($actor) || $this->access->isExecutive($actor), 403, 'تكليف المستخدمين بحملة لرئيس القسم.');
        DB::table('campaign_members')->where('campaign_id', $campaign->id)->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
        DB::table('campaign_members')->insert(['campaign_id' => $campaign->id, 'user_id' => $userId, 'role' => $role, 'starts_on' => today()->toDateString(), 'ends_on' => $endsOn,
            'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.campaign.member_granted', 'campaigns', $campaign->id, null, ['user' => $userId, 'role' => $role], $actor->id, request()?->ip());
    }

    /* ---------- Stores, delivery, reconciliation ---------- */

    /**
     * Issue/return through the Stores ledger (finance_stock_movements); nothing is counted twice. Under a lock on the
     * campaign item and in exact scaled integers: an issue never exceeds the approved list's need for the item (its
     * primary entries, plus those without an item) less what is already issued (net of returns), a return never
     * exceeds that net issue, and a return comes back into the store at the average cost at which the net issue left it.
     */
    public function stock(object $campaign, object $item, string $kind, string $quantity, User $actor): void
    {
        abort_unless(app(FinanceGuard::class)->allows($actor, $kind === 'issue' ? 'finance.stock.issue' : 'finance.stock.receive'), 403, 'صرف المواد وإرجاعها لأمين المخازن المخول في الحسابات.');
        abort_unless(in_array($campaign->status, ['approved', 'distributing', 'reconciling'], true), 409, 'لا تُصرف مواد قبل اعتماد المدير التنفيذي للكشف.');
        abort_unless($campaign->warehouse_id && $item->stock_item_id, 422, 'اربط الحملة بمخزن والصنف بصنف مخزني أولًا.');
        DB::transaction(function () use ($campaign, $item, $kind, $quantity, $actor): void {
            DB::table('campaign_items')->where('id', $item->id)->lockForUpdate()->first();
            $requested = StockExact::scaled($quantity, 3);
            if ($requested <= 0) {
                throw ValidationException::withMessages(['quantity' => 'الكمية غير صالحة.']);
            }
            // Net issued quantity (3 places) and its stock value (3 + 4 places), read from the movements themselves.
            $net = 0;
            $value = 0;
            foreach (DB::table('campaign_stock_issues as s')->join('finance_stock_movements as m', 'm.id', '=', 's.stock_movement_id')
                ->where('s.item_id', $item->id)->get(['s.kind', 'm.quantity', 'm.unit_cost']) as $row) {
                $sign = $row->kind === 'issue' ? 1 : -1;
                $q = StockExact::scaled((string) $row->quantity, 3);
                $net += $sign * $q;
                $value += $sign * $q * StockExact::scaled((string) $row->unit_cost, 4);
            }
            $issued = StockExact::decimal(max(0, $net), 3);
            if ($kind === 'issue') {
                // A primary entry without an item (imported without a variant, a reserve moved to primary) still
                // needs goods: it counts toward the sole item of a one-item campaign, and toward every item's cap
                // of a multi-item campaign, since the list does not say which item it takes.
                $needed = StockExact::scaled((string) DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')
                    ->where('l.campaign_id', $campaign->id)->where('l.status', 'approved')->where('e.kind', 'primary')
                    ->where(fn ($q) => $q->where('e.item_id', $item->id)->orWhereNull('e.item_id'))
                    ->selectRaw('coalesce(sum(e.quantity), 0) as q')->value('q'), 3);
                if ($net + $requested > $needed) {
                    throw ValidationException::withMessages(['quantity' => 'الصرف يتجاوز احتياج الكشف المعتمد من هذا الصنف: المطلوب '.StockExact::decimal($needed, 3).'، والمصروف صافيًا '.$issued.'.']);
                }
            } elseif ($requested > $net) {
                throw ValidationException::withMessages(['quantity' => 'الإرجاع يتجاوز صافي ما صُرف لهذه الحملة من هذا الصنف ('.$issued.').']);
            }
            $returnCost = $kind === 'issue' ? [] : ['unit_cost' => StockExact::decimal(intdiv(max(0, $value) + intdiv($net, 2), $net), 4)];
            $movement = app(FinanceOperations::class)->stockMovement(['warehouse_id' => $campaign->warehouse_id, 'stock_item_id' => $item->stock_item_id, 'kind' => $kind === 'issue' ? 'issue' : 'return_in',
                'quantity' => StockExact::decimal($requested, 3), ...$returnCost, 'reference_type' => 'campaign', 'reference_id' => $campaign->reference_no,
                'note' => ($kind === 'issue' ? 'صرف لحملة ' : 'إرجاع من حملة ').$campaign->reference_no], $actor->id);
            DB::table('campaign_stock_issues')->insert(['campaign_id' => $campaign->id, 'item_id' => $item->id, 'kind' => $kind, 'quantity' => StockExact::decimal($requested, 3), 'stock_movement_id' => $movement, 'recorded_by' => $actor->id, 'created_at' => now()]);
            if ($kind === 'issue' && $campaign->status === 'approved') {
                DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'distributing', 'updated_at' => now()]);
            }
        });
    }

    /**
     * Records one delivery outcome against the APPROVED version. The idempotency key is bound to the request's data
     * (request_hash): a retry or a repeated sync with the same data returns the first result, the same key with other
     * data is refused (409). Holders of one key are serialised, so concurrent retries also get the first result. A
     * row lock on the entry prevents two concurrent deliveries of the same unit. A confirmed delivery creates exactly
     * one benefit record (unique index), booked on the actual beneficiary.
     *
     * @return array{status: string, delivery_id: int}
     */
    public function deliver(object $campaign, int $entryId, array $data, User $actor, ?int $deviceId = null): array
    {
        abort_unless($this->canDeliver($actor, $campaign), 403, 'تسجيل التسليم لموظفي التسليم المكلفين بهذه الحملة.');
        $hash = hash('sha256', json_encode([(int) $campaign->id, $entryId, (string) $data['kind'], trim((string) ($data['recipient_name'] ?? '')),
            (string) ($data['recipient_relation'] ?? ''), (int) ($data['failure_reason_value_id'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($campaign, $entryId, $data, $actor, $deviceId, $hash): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['campaign-delivery:'.$data['idempotency_key']]);
            if ($existing = DB::table('campaign_deliveries')->where('idempotency_key', $data['idempotency_key'])->first()) {
                // Rows from before request_hash existed are compared on the columns that define the delivery.
                $same = $existing->request_hash !== null ? hash_equals($existing->request_hash, $hash)
                    : (int) $existing->campaign_id === (int) $campaign->id && (int) $existing->list_entry_id === $entryId && $existing->kind === $data['kind'];
                abort_unless($same, 409, 'مفتاح هذا التسليم استُخدم من قبل لتسجيل ببيانات أخرى؛ لم يُسجَّل شيء. أعد فتح نموذج التسليم وسجّل من جديد.');

                return ['status' => 'duplicate', 'delivery_id' => (int) $existing->id];
            }
            abort_unless(in_array($campaign->status, ['approved', 'distributing', 'reconciling'], true), 409, 'الحملة غير مفتوحة للتسليم.');
            $entry = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('e.id', $entryId)->where('l.campaign_id', $campaign->id)
                ->where('l.status', 'approved')->lockForUpdate()->first(['e.*']);
            abort_unless($entry && $entry->kind === 'primary', 422, 'المستفيد غير مدرج في الكشف الأساسي المعتمد.');
            $state = $this->entryState($campaign->id, $entry->unit_key);
            if ($data['kind'] === 'delivered') {
                abort_if($state === 'delivered', 409, 'سُلّم هذا المستفيد مسبقًا؛ التصحيح يتم بحدث تصحيح معتمد.');
                if (blank($data['recipient_name'] ?? null) || ! in_array($data['recipient_relation'] ?? '', ['guardian', 'beneficiary', 'proxy'], true)) {
                    throw ValidationException::withMessages(['recipient_name' => 'اسم المستلم الفعلي وصفته مطلوبان.']);
                }
                if ($data['recipient_relation'] === 'proxy' && blank($data['proxy_note'] ?? null)) {
                    throw ValidationException::withMessages(['proxy_note' => 'التسليم لوكيل يحتاج بيان التفويض.']);
                }
            }
            $id = DB::table('campaign_deliveries')->insertGetId(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id, 'list_entry_id' => $entry->id, 'unit_key' => $entry->unit_key,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'kind' => $data['kind'], 'quantity' => $data['kind'] === 'delivered' ? $entry->quantity : 0, 'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_relation' => $data['recipient_relation'] ?? null, 'proxy_note' => $data['proxy_note'] ?? null, 'identity_checked' => (bool) ($data['identity_checked'] ?? false),
                'failure_reason_value_id' => $data['failure_reason_value_id'] ?? null, 'note' => $data['note'] ?? null, 'recorded_by' => $actor->id, 'device_id' => $deviceId,
                'occurred_at' => $data['occurred_at'] ?? now(), 'signature_path' => $data['signature_path'] ?? null, 'created_at' => now()]);
            if ($data['kind'] === 'delivered') {
                $item = $entry->item_id ? DB::table('campaign_items')->find($entry->item_id) : null;
                // The benefit is booked on the actual beneficiary unit (child, household), never on the guardian personally.
                $unit = $entry->guardian_id && ! $entry->orphan_id && ! $entry->household_id ? $this->guardianUnit($entry) : ['orphan_id' => $entry->orphan_id, 'household_id' => $entry->household_id];
                DB::table('benefit_records')->insert(['public_id' => (string) Str::ulid(), 'kind' => 'delivery', ...$unit,
                    'source' => 'campaign', 'campaign_id' => $campaign->id, 'campaign_delivery_id' => $id, 'type_value_id' => null,
                    'description' => $campaign->title.($item ? ' — '.$item->label.($item->variant ? ' ('.$item->variant.')' : '') : ''), 'quantity' => $entry->quantity, 'unit' => null,
                    'recipient_name' => $data['recipient_name'], 'delivered_on' => substr((string) ($data['occurred_at'] ?? now()), 0, 10), 'fulfillment_ref' => $campaign->reference_no.'/'.$id, 'recorded_by' => $actor->id, 'created_at' => now()]);
            }

            return ['status' => 'recorded', 'delivery_id' => $id];
        });
    }

    /** Guardian-unit aid is booked on the guardian's household when known, else on the first approved child. */
    private function guardianUnit(object $entry): array
    {
        $person = DB::table('orphan_guardians')->where('id', $entry->guardian_id)->value('person_id');
        $household = DB::table('household_members')->where('person_id', $person)->whereNull('ends_on')->value('household_id');

        return $household ? ['household_id' => $household, 'orphan_id' => null]
            : ['orphan_id' => DB::table('orphan_guardianships')->where('guardian_id', $entry->guardian_id)->where('status', 'approved')->orderBy('id')->value('orphan_id'), 'household_id' => null];
    }

    /** Authorized correction of a recorded outcome: a new correction event; the benefit (if any) is reversed. */
    public function correct(object $delivery, string $reason, User $actor): void
    {
        $this->access->assertDecide($actor, 'تصحيح تسليم مؤكد لرئيس القسم.');
        abort_if($delivery->kind === 'correction' || DB::table('campaign_deliveries')->where('corrects_id', $delivery->id)->exists(), 409, 'لا يمكن تصحيح هذا الحدث.');
        DB::transaction(function () use ($delivery, $reason, $actor): void {
            DB::table('campaign_deliveries')->insert(['public_id' => (string) Str::ulid(), 'campaign_id' => $delivery->campaign_id, 'list_entry_id' => $delivery->list_entry_id, 'unit_key' => $delivery->unit_key,
                'idempotency_key' => (string) Str::uuid(), 'kind' => 'correction', 'quantity' => 0, 'note' => $reason, 'recorded_by' => $actor->id, 'occurred_at' => now(), 'corrects_id' => $delivery->id, 'created_at' => now()]);
            if ($benefit = DB::table('benefit_records')->where('campaign_delivery_id', $delivery->id)->where('kind', 'delivery')->first()) {
                app(Aid::class)->reverseBenefit($benefit, 'تصحيح تسليم حملة: '.$reason, $actor);
            }
            $this->audit->recordRow('orphans.campaign.delivery_corrected', 'campaign_deliveries', $delivery->id, null, ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /** prepared | delivered | undelivered | returned — from the latest non-corrected event of the unit. */
    public function entryState(int $campaignId, string $unitKey): string
    {
        $last = DB::table('campaign_deliveries as d')->where('d.campaign_id', $campaignId)->where('d.unit_key', $unitKey)->where('d.kind', '<>', 'correction')
            ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as c')->whereColumn('c.corrects_id', 'd.id'))->orderByDesc('d.id')->value('kind');

        return $last ?? 'prepared';
    }

    /** Issued vs delivered vs returned per item, recorded with a note; the campaign moves to reconciling. */
    public function reconcile(object $campaign, ?string $note, User $actor): array
    {
        abort_unless($this->access->canAssign($actor) || $this->access->allows($actor, 'campaigns'), 403);
        $summary = DB::table('campaign_items')->where('campaign_id', $campaign->id)->get()->map(function ($i) use ($campaign): array {
            $issued = (float) DB::table('campaign_stock_issues')->where('item_id', $i->id)->where('kind', 'issue')->sum('quantity');
            $returned = (float) DB::table('campaign_stock_issues')->where('item_id', $i->id)->where('kind', 'return')->sum('quantity');
            $delivered = (float) DB::table('campaign_deliveries as d')->join('campaign_list_entries as e', 'e.id', '=', 'd.list_entry_id')->where('d.campaign_id', $campaign->id)->where('e.item_id', $i->id)
                ->where('d.kind', 'delivered')->whereNotExists(fn ($q) => $q->from('campaign_deliveries as c')->whereColumn('c.corrects_id', 'd.id'))->sum('d.quantity');

            return ['item' => $i->label.($i->variant ? ' — '.$i->variant : ''), 'issued' => $issued, 'delivered' => $delivered, 'returned' => $returned, 'variance' => round($issued - $delivered - $returned, 3)];
        })->all();
        DB::table('campaign_reconciliations')->insert(['campaign_id' => $campaign->id, 'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'note' => $note, 'recorded_by' => $actor->id, 'created_at' => now()]);
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'reconciling', 'updated_at' => now()]);

        return $summary;
    }

    public function close(object $campaign, ?string $note, User $actor): void
    {
        $this->access->assertDecide($actor, 'إغلاق الحملة لرئيس القسم.');
        abort_unless($campaign->status === 'reconciling', 409, 'أغلق الحملة بعد تسجيل التسوية.');
        $last = DB::table('campaign_reconciliations')->where('campaign_id', $campaign->id)->orderByDesc('id')->value('summary');
        $variance = collect(json_decode((string) $last, true) ?: [])->sum(fn ($r) => abs($r['variance']));
        if ($variance > 0.0001 && blank($note)) {
            throw ValidationException::withMessages(['note' => 'توجد فروقات في التسوية؛ اذكر تفسيرها قبل الإغلاق.']);
        }
        DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'close_note' => $note, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.campaign.closed', 'campaigns', $campaign->id, null, ['note' => $note], $actor->id, request()?->ip());
    }

    /* ---------- helpers ---------- */

    public function refreshList(int $listId): void
    {
        $entries = DB::table('campaign_list_entries')->where('list_id', $listId)->orderBy('kind')->orderBy('position')->get(['kind', 'unit_key', 'item_id', 'quantity']);
        $hash = hash('sha256', $entries->map(fn ($e) => $e->kind.'|'.$e->unit_key.'|'.($e->item_id ?? 0).'|'.(float) $e->quantity)->implode("\n"));
        DB::table('campaign_lists')->where('id', $listId)->where('status', 'draft')->update(['content_hash' => $hash, 'entry_count' => $entries->count(), 'updated_at' => now()]);
    }

    /**
     * Refuses a list with entries that no longer pass the base eligibility rule, naming them (up to 20). In a change
     * request, a unit carried over from the approved version that already has a delivery event is exempt, so the
     * amended list still covers deliveries already recorded against it.
     */
    private function assertEligibleEntries(object $list): void
    {
        $offenders = NominationEngine::ineligibleEntries((int) $list->id);
        if ($offenders !== [] && ($list->source ?? null) === 'change_request' && $list->base_list_id) {
            $carried = DB::table('campaign_list_entries')->where('list_id', $list->base_list_id)->pluck('unit_key')->flip();
            $offenders = array_values(array_filter($offenders, fn (object $e): bool => ! isset($carried[$e->unit_key])
                || $this->entryState((int) $list->campaign_id, $e->unit_key) === 'prepared'));
        }
        if ($offenders !== []) {
            throw ValidationException::withMessages(['entries' => 'في الكشف '.count($offenders).' مستفيد لم يعد مؤهلًا (ملف معتمد مبدئيًا أو منتهٍ أو مؤرشف، أو أسرة/وصي دون ملف مؤهل)؛ احذفهم بطلب تعديل أو مسودة ثم أعد الرفع: '
                .collect($offenders)->take(20)->map(fn (object $e): string => $this->entryLabel($e))->implode('، ')]);
        }
    }

    private function nextVersion(int $campaignId): int
    {
        return (int) DB::table('campaign_lists')->where('campaign_id', $campaignId)->max('version') + 1;
    }

    private function assertDraft(object $campaign, User $actor): void
    {
        $this->access->assert($actor, 'campaigns');
        abort_unless(in_array($campaign->status, ['draft', 'list_draft'], true), 409, 'لا تُعدّل إعدادات حملة رُفع كشفها أو اعتُمد؛ التغييرات تتم بطلب تعديل.');
    }
}
