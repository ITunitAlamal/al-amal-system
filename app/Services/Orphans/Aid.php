<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceSourceBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sponsorships (commitments), assistance requests and confirmed benefits. Money is never booked here: a due is
 * referred to Accounts as a receivable and its collection is read from Accounts' allocations.
 */
final class Aid
{
    public const SPONSORSHIP_STATUS = ['draft' => 'مسودة', 'active' => 'نشطة', 'partial' => 'نشطة جزئيًا', 'suspended' => 'موقوفة', 'expired' => 'منتهية المدة', 'ended' => 'منتهية'];

    public const COVERAGE = ['individual' => 'فردية (يتيم واحد)', 'multiple' => 'عدة أيتام', 'household' => 'أسرة', 'pooled' => 'برنامج مجمّع'];

    public const ASSISTANCE_STATUS = ['requested' => 'مطلوبة', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'fulfilled' => 'مُنفذة', 'cancelled' => 'ملغاة'];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit, private readonly FinanceSourceBridge $finance) {}

    public function saveSponsor(array $data, ?int $id, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');
        $fill = collect($data)->only(['kind', 'name', 'contact_name', 'phone', 'email', 'country', 'notes', 'status'])->all();
        if ($id) {
            DB::table('sponsors')->where('id', $id)->update([...$fill, 'updated_at' => now()]);
        } else {
            $id = DB::table('sponsors')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('SPR', 'sponsor_seq'), ...$fill, 'status' => $fill['status'] ?? 'active',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit->recordRow('orphans.sponsor.saved', 'sponsors', $id, null, ['name' => $fill['name'] ?? null], $actor->id, request()?->ip());

        return $id;
    }

    /** Creates a sponsorship draft with its beneficiary lines (orphans and/or households). */
    public function createSponsorship(array $data, array $orphanIds, array $householdIds, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');
        if ($orphanIds !== [] && DB::table('orphans')->whereIn('id', $orphanIds)->where('status', 'provisional')->exists()) {
            throw ValidationException::withMessages(['beneficiaries' => 'لا تُنشأ كفالة لملف «معتمد مبدئيًا»؛ اعتمد الوصاية ثم الملف نهائيًا أولًا.']);
        }
        if ($orphanIds === [] && $householdIds === [] && $data['coverage'] !== 'pooled') {
            throw ValidationException::withMessages(['beneficiaries' => 'أضف مستفيدًا واحدًا على الأقل (أو اختر برنامجًا مجمّعًا).']);
        }
        if ($data['coverage'] === 'individual' && count($orphanIds) + count($householdIds) !== 1) {
            throw ValidationException::withMessages(['beneficiaries' => 'الكفالة الفردية ليتيم واحد فقط.']);
        }
        if ($data['coverage'] === 'household' && $householdIds === []) {
            throw ValidationException::withMessages(['beneficiaries' => 'كفالة الأسرة تحتاج أسرة مسجلة.']);
        }

        return DB::transaction(function () use ($data, $orphanIds, $householdIds, $actor): int {
            $id = DB::table('sponsorships')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('SPN', 'sponsorship_seq'),
                ...collect($data)->only(['sponsor_id', 'program_value_id', 'coverage', 'amount', 'currency', 'period_months', 'frequency_value_id', 'starts_on', 'ends_on', 'notes', 'renewal_of_id'])->all(),
                'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($orphanIds as $orphan) {
                $this->addBeneficiary($id, ['orphan_id' => $orphan], $data['starts_on'], $actor);
            }
            foreach ($householdIds as $household) {
                $this->addBeneficiary($id, ['household_id' => $household], $data['starts_on'], $actor);
            }
            $this->audit->recordRow('orphans.sponsorship.created', 'sponsorships', $id, null, ['amount' => $data['amount'], 'currency' => $data['currency']], $actor->id, request()?->ip());

            return $id;
        });
    }

    public function addBeneficiary(int $sponsorshipId, array $unit, string $startsOn, User $actor, ?string $share = null): void
    {
        DB::table('sponsorship_beneficiaries')->insert([...$unit, 'sponsorship_id' => $sponsorshipId, 'share_amount' => $share, 'starts_on' => $startsOn, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function endBeneficiary(int $lineId, string $reason, User $actor): void
    {
        $this->access->assert($actor, 'sponsorships');
        DB::table('sponsorship_beneficiaries')->where('id', $lineId)->whereNull('ends_on')->update(['ends_on' => today()->toDateString(), 'ended_reason' => $reason, 'updated_at' => now()]);
    }

    /** Lifecycle changes; activation and suspension/ending belong to the head (or the system admin). */
    public function setStatus(object $sponsorship, string $status, ?string $reason, User $actor): void
    {
        $this->access->assert($actor, 'sponsorships');
        if (in_array($status, ['active', 'suspended', 'ended'], true)) {
            abort_unless($this->access->canAssign($actor), 403, 'تفعيل الكفالة أو إيقافها أو إنهاؤها لرئيس القسم.');
        }
        if (in_array($status, ['suspended', 'ended'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اذكر السبب.']);
        }
        DB::transaction(function () use ($sponsorship, $status, $reason, $actor): void {
            $activation = $sponsorship->activated_at ? [] : ($status !== 'draft' ? ['activated_by' => $actor->id, 'activated_at' => now()] : []);
            DB::table('sponsorships')->where('id', $sponsorship->id)->update(['status' => $status, 'status_reason' => $reason, ...$activation, 'updated_at' => now()]);
            if ($status === 'ended') {
                DB::table('sponsorship_beneficiaries')->where('sponsorship_id', $sponsorship->id)->whereNull('ends_on')->update(['ends_on' => today()->toDateString(), 'ended_reason' => 'إنهاء الكفالة', 'updated_at' => now()]);
                DB::table('sponsorship_dues')->where('sponsorship_id', $sponsorship->id)->where('status', 'planned')->where('due_on', '>', today()->toDateString())->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            if (in_array($status, ['active', 'partial'], true)) {
                $this->schedule(DB::table('sponsorships')->find($sponsorship->id));
            }
            $this->audit->recordRow('orphans.sponsorship.status', 'sponsorships', $sponsorship->id, ['status' => $sponsorship->status], ['status' => $status, 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /** Planned dues up to 12 months ahead (or the end date); existing periods are never rewritten. */
    public function schedule(object $sponsorship): int
    {
        $start = Carbon::parse($sponsorship->starts_on);
        $limit = min(today()->addMonths(12), $sponsorship->ends_on ? Carbon::parse($sponsorship->ends_on) : today()->addMonths(12));
        $added = 0;
        // Each period is computed from the start date without overflow (31 Aug + 1 month = 30 Sep, never 1 Oct).
        for ($i = 0; ($period = $start->copy()->addMonthsNoOverflow($i * (int) $sponsorship->period_months))->lte($limit); $i++) {
            $added += DB::table('sponsorship_dues')->insertOrIgnore(['sponsorship_id' => $sponsorship->id, 'period_start' => $period->toDateString(), 'due_on' => $period->toDateString(),
                'amount' => $sponsorship->amount, 'currency' => $sponsorship->currency, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $added;
    }

    /** Refers one due to Accounts as an approved receivable (idempotent by source reference). */
    public function refer(object $due, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');
        abort_unless($due->status === 'planned', 409, 'الاستحقاق محال مسبقًا أو ملغى.');
        $sponsorship = DB::table('sponsorships as s')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')->where('s.id', $due->sponsorship_id)->first(['s.*', 'p.name as sponsor_name']);
        abort_unless(in_array($sponsorship->status, ['active', 'partial'], true), 409, 'لا تُحال استحقاقات كفالة غير نشطة.');

        return DB::transaction(function () use ($due, $sponsorship, $actor): int {
            $receivable = $this->finance->registerApprovedReceivable('sponsorships', $sponsorship->reference_no.':'.$due->period_start, 'sponsorship', $sponsorship->sponsor_name,
                $due->currency, (string) $due->amount, (string) $due->due_on, $actor->id, 'كفالة '.$sponsorship->reference_no.' عن الفترة من '.$due->period_start);
            DB::table('sponsorship_dues')->where('id', $due->id)->update(['status' => 'referred', 'finance_receivable_id' => $receivable, 'referred_by' => $actor->id, 'referred_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.sponsorship.due_referred', 'sponsorship_dues', $due->id, null, ['receivable' => $receivable], $actor->id, request()?->ip());

            return $receivable;
        });
    }

    /** Amount Accounts allocated to a receivable through executed, non-reversed documents. */
    public static function collected(?int $receivableId): ?string
    {
        if (! $receivableId) {
            return null;
        }

        return (string) DB::table('finance_payment_allocations as a')->join('finance_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.receivable_module', 'finance')->where('a.receivable_id', (string) $receivableId)->whereNull('a.reversed_by_transaction_id')
            ->where('t.state', 'executed')->sum('a.amount');
    }

    public function createAssistance(array $data, array $unit, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');
        $id = DB::table('assistance_requests')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('AST', 'assistance_seq'), ...$unit,
            ...collect($data)->only(['type_value_id', 'description', 'quantity', 'amount', 'currency'])->all(), 'status' => 'requested', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.assistance.requested', 'assistance_requests', $id, null, null, $actor->id, request()?->ip());

        return $id;
    }

    public function decideAssistance(object $request, string $decision, ?string $note, User $actor): void
    {
        abort_unless($this->access->canAssign($actor), 403, 'اعتماد طلبات المساعدة لرئيس القسم.');
        abort_unless($request->status === 'requested', 409, 'تم البت في الطلب مسبقًا.');
        if ($decision !== 'approved' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'اذكر السبب.']);
        }
        DB::table('assistance_requests')->where('id', $request->id)->update(['status' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.assistance.'.$decision, 'assistance_requests', $request->id, null, ['note' => $note], $actor->id, request()?->ip());
    }

    /**
     * Records a confirmed delivery (approval is not delivery). Household aid is booked once on the household; a
     * child-specific item is booked on the child even if the guardian received it.
     */
    public function recordBenefit(array $data, array $unit, User $actor): int
    {
        abort_unless($this->access->allows($actor, 'sponsorships') || $this->access->allows($actor, 'delivery'), 403, 'تسجيل الاستفادة يحتاج قدرة الكفالات أو التسليم.');
        if (blank($data['fulfillment_ref'] ?? null) && ($data['source'] ?? '') !== 'other') {
            throw ValidationException::withMessages(['fulfillment_ref' => 'مرجع التنفيذ مطلوب (رقم سند الحسابات أو حركة المخزن أو محضر التسليم).']);
        }

        return DB::transaction(function () use ($data, $unit, $actor): int {
            if (! empty($data['assistance_request_id'])) {
                $request = DB::table('assistance_requests')->lockForUpdate()->find($data['assistance_request_id']);
                abort_unless($request && $request->status === 'approved', 409, 'طلب المساعدة غير معتمد أو مُنفذ مسبقًا.');
                $unit = array_filter(['orphan_id' => $request->orphan_id, 'household_id' => $request->household_id]);
            }
            $id = DB::table('benefit_records')->insertGetId(['public_id' => (string) Str::ulid(), 'kind' => 'delivery', ...$unit,
                ...collect($data)->only(['source', 'campaign_id', 'campaign_delivery_id', 'sponsorship_id', 'assistance_request_id', 'type_value_id', 'description', 'quantity', 'unit', 'amount', 'currency',
                    'recipient_person_id', 'recipient_name', 'delivered_on', 'fulfillment_ref'])->all(), 'recorded_by' => $actor->id, 'created_at' => now()]);
            if (! empty($data['assistance_request_id'])) {
                DB::table('assistance_requests')->where('id', $data['assistance_request_id'])->update(['status' => 'fulfilled', 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.benefit.recorded', 'benefit_records', $id, null, ['source' => $data['source']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** A correction: a reversal row pointing to the original, which stays untouched. */
    public function reverseBenefit(object $benefit, string $reason, User $actor): int
    {
        abort_unless($this->access->canAssign($actor), 403, 'عكس استفادة مؤكدة لرئيس القسم.');
        abort_unless($benefit->kind === 'delivery', 409, 'لا يُعكس قيد عكسي.');
        abort_if(DB::table('benefit_records')->where('reverses_id', $benefit->id)->exists(), 409, 'هذه الاستفادة معكوسة مسبقًا.');
        $id = DB::table('benefit_records')->insertGetId(['public_id' => (string) Str::ulid(), 'kind' => 'reversal', 'orphan_id' => $benefit->orphan_id, 'household_id' => $benefit->household_id,
            'source' => $benefit->source, 'campaign_id' => $benefit->campaign_id, 'description' => 'عكس: '.$benefit->description, 'quantity' => $benefit->quantity, 'amount' => $benefit->amount,
            'currency' => $benefit->currency, 'delivered_on' => today()->toDateString(), 'reverses_id' => $benefit->id, 'reason' => $reason, 'recorded_by' => $actor->id, 'created_at' => now()]);
        $this->audit->recordRow('orphans.benefit.reversed', 'benefit_records', $benefit->id, null, ['reversal' => $id, 'reason' => $reason], $actor->id, request()?->ip());

        return $id;
    }

    /** Confirmed, non-reversed benefits of a unit, newest first. */
    public static function benefits(array $unit, int $limit = 100): array
    {
        $query = DB::table('benefit_records as b')->leftJoin('master_data_values as t', 't.id', '=', 'b.type_value_id')->leftJoin('users as u', 'u.id', '=', 'b.recorded_by')
            ->where('b.kind', 'delivery')->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'));
        foreach ($unit as $column => $value) {
            is_array($value) ? $query->whereIn('b.'.$column, $value) : $query->where('b.'.$column, $value);
        }

        return $query->orderByDesc('b.delivered_on')->orderByDesc('b.id')->limit($limit)
            ->get(['b.public_id', 'b.orphan_id', 'b.household_id', 'b.source', 'b.description', 'b.quantity', 'b.unit', 'b.amount', 'b.currency', 'b.recipient_name', 'b.delivered_on',
                'b.fulfillment_ref', 'b.campaign_id', 't.name_ar as type', 'u.name as recorded_by_name'])->map(fn ($r) => (array) $r)->all();
    }

    /** Effective status: an active/partial sponsorship past its end date reads as expired. */
    public static function effectiveStatus(object $s): string
    {
        return in_array($s->status, ['active', 'partial'], true) && $s->ends_on && $s->ends_on < today()->toDateString() ? 'expired' : $s->status;
    }
}
