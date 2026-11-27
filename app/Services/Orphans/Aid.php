<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceSourceBridge;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sponsorships (commitments), assistance requests and confirmed benefits. Money is never booked here: a due is
 * referred to Accounts as a receivable and its collection is read from Accounts' allocations.
 */
final class Aid
{
    public const SPONSORSHIP_STATUS = ['draft' => 'مسودة', 'active' => 'نشطة', 'partial' => 'نشطة جزئيًا', 'suspended' => 'موقوفة', 'expired' => 'منتهية المدة', 'ended' => 'منتهية'];

    public const COVERAGE = ['individual' => 'فردية (يتيم واحد)', 'multiple' => 'عدة أيتام', 'household' => 'أسرة', 'pooled' => 'برنامج مجمّع'];

    public const ASSISTANCE_STATUS = ['requested' => 'مطلوبة', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'fulfilled' => 'مُنفذة', 'cancelled' => 'ملغاة'];

    /** What a delivery's execution reference points to (benefit_records.fulfillment_kind). Campaign deliveries carry their own campaign reference. */
    public const FULFILLMENT_KINDS = ['handover' => 'محضر تسليم', 'stock_movement' => 'حركة صرف من المخازن', 'finance_document' => 'سند من الحسابات'];

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

    /**
     * Allowed lifecycle moves (from → to). «expired» is derived from the end date and never set; nothing returns to
     * draft; ended is terminal (a draft may be ended with a reason, as before). Every move into active/partial passes
     * the eligibility gate (assertFundable).
     */
    public const TRANSITIONS = [
        'draft' => ['active', 'partial', 'ended'],
        'active' => ['partial', 'suspended', 'ended'],
        'partial' => ['active', 'suspended', 'ended'],
        'suspended' => ['active', 'partial', 'ended'],
        'expired' => ['ended'],
        'ended' => [],
    ];

    /** Orphan files that can never be put in a sponsorship, even a draft. */
    public const CLOSED_FILE_STATUSES = ['ended', 'archived'];

    /**
     * The eligibility gate: locks the sponsorship and requires every live orphan line to be an officially approved
     * file (status «active»). Household and pooled lines keep their own rules (none is defined yet). Call it inside
     * a transaction. Returns the locked sponsorship row.
     */
    public function assertFundable(int $sponsorshipId): object
    {
        $sponsorship = DB::table('sponsorships')->where('id', $sponsorshipId)->lockForUpdate()->first();
        abort_unless($sponsorship, 404);
        $blocked = DB::table('sponsorship_beneficiaries as b')->join('orphans as o', 'o.id', '=', 'b.orphan_id')
            ->where('b.sponsorship_id', $sponsorshipId)->whereNull('b.ends_on')->where('o.status', '<>', 'active')
            ->sharedLock()->orderBy('o.reference_no')->limit(20)->get(['o.reference_no', 'o.status']);
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages(['beneficiaries' => 'لا تُفعَّل الكفالة ولا تُنشأ أو تُحال استحقاقاتها قبل الاعتماد النهائي لكل الأيتام المستفيدين. ملفات غير معتمدة: '
                .$blocked->map(fn ($o): string => $o->reference_no.' ('.(RegistryQueries::ORPHAN_STATUS[$o->status] ?? $o->status).')')->implode('، ')]);
        }

        return $sponsorship;
    }

    /** Creates a sponsorship draft with its beneficiary lines (orphans and/or households). */
    public function createSponsorship(array $data, array $orphanIds, array $householdIds, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');
        // A draft may include files not yet approved (draft, provisional, suspended); activation is gated later.
        if ($orphanIds !== [] && DB::table('orphans')->whereIn('id', $orphanIds)->whereIn('status', self::CLOSED_FILE_STATUSES)->exists()) {
            throw ValidationException::withMessages(['beneficiaries' => 'لا يُدرج في الكفالة ملف منتهٍ أو مؤرشف.']);
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

    /**
     * Adds a line. Staff shape a draft; on a sponsorship that is no longer a draft, assigning a beneficiary is the
     * head's decision (req-04 §6) and the sponsorship must stay fundable: every live orphan line approved. The
     * coverage rules of createSponsorship hold on every addition.
     */
    public function addBeneficiary(int $sponsorshipId, array $unit, string $startsOn, User $actor, ?string $share = null): void
    {
        $this->access->assert($actor, 'sponsorships');
        DB::transaction(function () use ($sponsorshipId, $unit, $startsOn, $actor, $share): void {
            $sponsorship = DB::table('sponsorships')->where('id', $sponsorshipId)->lockForUpdate()->first();
            abort_unless($sponsorship, 404);
            abort_if(in_array($sponsorship->status, ['ended', 'expired'], true), 409, 'لا يُضاف مستفيد إلى كفالة منتهية.');
            if ($sponsorship->status !== 'draft') {
                $this->access->assertDecide($actor, 'إسناد مستفيد إلى كفالة غير مسودة قرار لرئيس القسم.');
            }
            $orphan = ! empty($unit['orphan_id']) ? DB::table('orphans')->where('id', $unit['orphan_id'])->sharedLock()->first(['reference_no', 'status']) : null;
            if ($orphan && in_array($orphan->status, self::CLOSED_FILE_STATUSES, true)) {
                throw ValidationException::withMessages(['beneficiaries' => 'لا يُدرج في الكفالة ملف منتهٍ أو مؤرشف.']);
            }
            $this->assertCoverage($sponsorship, $share);
            // Checked before the insert, so this message is shown rather than the database guard's (000025).
            if ($sponsorship->status !== 'draft') {
                $this->assertFundable($sponsorshipId);
                if ($orphan && $orphan->status !== 'active') {
                    throw ValidationException::withMessages(['beneficiaries' => 'لا يُضاف إلى كفالة غير مسودة إلا ملف معتمد نهائيًا. ملف غير معتمد: '
                        .$orphan->reference_no.' ('.(RegistryQueries::ORPHAN_STATUS[$orphan->status] ?? $orphan->status).')']);
                }
            }
            $line = DB::table('sponsorship_beneficiaries')->insertGetId([...$unit, 'sponsorship_id' => $sponsorshipId, 'share_amount' => $share, 'starts_on' => $startsOn, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.sponsorship.line_added', 'sponsorship_beneficiaries', $line, null, [...$unit, 'sponsorship' => $sponsorship->reference_no, 'share' => $share], $actor->id, request()?->ip());
        });
    }

    /** An individual sponsorship has at most one live beneficiary; the live shares never exceed the sponsorship amount. */
    private function assertCoverage(object $sponsorship, ?string $share): void
    {
        $live = DB::table('sponsorship_beneficiaries')->where('sponsorship_id', $sponsorship->id)->whereNull('ends_on');
        if ($sponsorship->coverage === 'individual' && (clone $live)->exists()) {
            throw ValidationException::withMessages(['beneficiaries' => 'الكفالة الفردية لمستفيد واحد؛ أنهِ استفادة المستفيد الحالي قبل إسناد غيره.']);
        }
        if ($share !== null && $share !== '' && round((float) (clone $live)->sum('share_amount') + (float) $share, 2) > round((float) $sponsorship->amount, 2)) {
            throw ValidationException::withMessages(['share_amount' => 'مجموع حصص المستفيدين يتجاوز قيمة الكفالة ('.$sponsorship->amount.' '.$sponsorship->currency.').']);
        }
    }

    /** Ends one live line of THIS sponsorship, with its reason kept. Outside a draft it is the head's decision. */
    public function endBeneficiary(int $sponsorshipId, int $lineId, string $reason, User $actor): void
    {
        $this->access->assert($actor, 'sponsorships');
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب إنهاء استفادة هذا المستفيد.']);
        }
        DB::transaction(function () use ($sponsorshipId, $lineId, $reason, $actor): void {
            $sponsorship = DB::table('sponsorships')->where('id', $sponsorshipId)->lockForUpdate()->first();
            abort_unless($sponsorship, 404);
            if ($sponsorship->status !== 'draft') {
                $this->access->assertDecide($actor, 'إنهاء استفادة مستفيد من كفالة غير مسودة قرار لرئيس القسم.');
            }
            $updated = DB::table('sponsorship_beneficiaries')->where('id', $lineId)->where('sponsorship_id', $sponsorshipId)->whereNull('ends_on')
                ->update(['ends_on' => today()->toDateString(), 'ended_reason' => $reason, 'updated_at' => now()]);
            abort_if($updated === 0, 404, 'سطر المستفيد غير موجود في هذه الكفالة أو أُنهي مسبقًا.');
            $this->audit->recordRow('orphans.sponsorship.line_ended', 'sponsorship_beneficiaries', $lineId, ['ends_on' => null], ['ends_on' => today()->toDateString(), 'reason' => $reason, 'sponsorship' => $sponsorship->reference_no], $actor->id, request()?->ip());
        });
    }

    /**
     * Lifecycle changes, decided by the head (or acting head) only, along TRANSITIONS. $expected is the status the
     * user saw: a concurrent change is refused. Activation (including partial and resume) passes the gate.
     */
    public function setStatus(object $sponsorship, string $status, ?string $reason, User $actor, string $expected): void
    {
        $this->access->assertDecide($actor, 'تفعيل الكفالة أو إيقافها أو إنهاؤها لرئيس القسم.');
        if (! in_array($status, self::TRANSITIONS[$expected] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'لا يجوز نقل الكفالة من «'.(self::SPONSORSHIP_STATUS[$expected] ?? $expected).'» إلى «'.(self::SPONSORSHIP_STATUS[$status] ?? $status).'».']);
        }
        if (in_array($status, ['suspended', 'ended'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اذكر السبب.']);
        }
        DB::transaction(function () use ($sponsorship, $status, $reason, $actor, $expected): void {
            $live = in_array($status, ['active', 'partial'], true);
            $current = $live ? $this->assertFundable((int) $sponsorship->id) : DB::table('sponsorships')->where('id', $sponsorship->id)->lockForUpdate()->first();
            abort_unless($current && $current->status === $expected, 409, 'تغيّرت حالة الكفالة منذ فتح الصفحة؛ أعد تحميلها.');
            $activation = ! $current->activated_at && $live ? ['activated_by' => $actor->id, 'activated_at' => now()] : [];
            $updated = DB::table('sponsorships')->where('id', $current->id)->where('status', $expected)->update(['status' => $status, 'status_reason' => $reason, ...$activation, 'updated_at' => now()]);
            abort_if($updated === 0, 409, 'تغيّرت حالة الكفالة منذ فتح الصفحة؛ أعد تحميلها.');
            if ($status === 'ended') {
                DB::table('sponsorship_beneficiaries')->where('sponsorship_id', $current->id)->whereNull('ends_on')->update(['ends_on' => today()->toDateString(), 'ended_reason' => 'إنهاء الكفالة', 'updated_at' => now()]);
                DB::table('sponsorship_dues')->where('sponsorship_id', $current->id)->where('status', 'planned')->where('due_on', '>', today()->toDateString())->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            if ($live) {
                $this->schedule($current);
            }
            $this->audit->recordRow('orphans.sponsorship.status', 'sponsorships', $current->id, ['status' => $expected], ['status' => $status, 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /** Planned dues up to 12 months ahead (or the end date) of an active, fundable sponsorship; existing periods are never rewritten. */
    public function schedule(object $sponsorship): int
    {
        return DB::transaction(function () use ($sponsorship): int {
            $sponsorship = $this->assertFundable((int) $sponsorship->id);
            abort_unless(in_array($sponsorship->status, ['active', 'partial'], true), 409, 'لا تُنشأ استحقاقات لكفالة غير نشطة.');
            $start = Carbon::parse($sponsorship->starts_on);
            $limit = min(today()->addMonths(12), $sponsorship->ends_on ? Carbon::parse($sponsorship->ends_on) : today()->addMonths(12));
            $added = 0;
            // Each period is computed from the start date without overflow (31 Aug + 1 month = 30 Sep, never 1 Oct).
            for ($i = 0; ($period = $start->copy()->addMonthsNoOverflow($i * (int) $sponsorship->period_months))->lte($limit); $i++) {
                $added += DB::table('sponsorship_dues')->insertOrIgnore(['sponsorship_id' => $sponsorship->id, 'period_start' => $period->toDateString(), 'due_on' => $period->toDateString(),
                    'amount' => $sponsorship->amount, 'currency' => $sponsorship->currency, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
            }

            return $added;
        });
    }

    /** Refers one due to Accounts as an approved receivable (idempotent by source reference). */
    public function refer(object $due, User $actor): int
    {
        $this->access->assert($actor, 'sponsorships');

        return DB::transaction(function () use ($due, $actor): int {
            // Sponsorship first, then the due: the same lock order as setStatus, so the two cannot deadlock.
            $sponsorshipId = DB::table('sponsorship_dues')->where('id', $due->id)->value('sponsorship_id');
            abort_unless($sponsorshipId, 404);
            $locked = $this->assertFundable((int) $sponsorshipId);
            $due = DB::table('sponsorship_dues')->where('id', $due->id)->lockForUpdate()->first();
            abort_unless($due && $due->status === 'planned', 409, 'الاستحقاق محال مسبقًا أو ملغى.');
            abort_unless(in_array($locked->status, ['active', 'partial'], true), 409, 'لا تُحال استحقاقات كفالة غير نشطة.');
            $sponsorship = DB::table('sponsorships as s')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')->where('s.id', $due->sponsorship_id)->first(['s.*', 'p.name as sponsor_name']);
            $receivable = $this->finance->registerApprovedReceivable('sponsorships', $sponsorship->reference_no.':'.$due->period_start, 'sponsorship', $sponsorship->sponsor_name,
                $due->currency, (string) $due->amount, (string) $due->due_on, $actor->id, 'كفالة '.$sponsorship->reference_no.' عن الفترة من '.$due->period_start);
            DB::table('sponsorship_dues')->where('id', $due->id)->update(['status' => 'referred', 'finance_receivable_id' => $receivable, 'referred_by' => $actor->id, 'referred_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.sponsorship.due_referred', 'sponsorship_dues', $due->id, null, ['receivable' => $receivable], $actor->id, request()?->ip());

            return $receivable;
        });
    }

    /**
     * Amount Accounts collected on a referred due: allocations of executed, non-reversed internal documents (full
     * mode) plus the receipts recorded from the external accounting software (documentary mode, ACC-09), not reversed.
     */
    public static function collected(?int $receivableId, ?int $dueId = null): ?string
    {
        if (! $receivableId) {
            return null;
        }
        $internal = DB::table('finance_payment_allocations as a')->join('finance_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.receivable_module', 'finance')->where('a.receivable_id', (string) $receivableId)->whereNull('a.reversed_by_transaction_id')
            ->where('t.state', 'executed')->sum('a.amount');
        $external = $dueId ? DB::table('sponsorship_receipt_allocations')->where('due_id', $dueId)->whereNull('reversed_at')->sum('amount') : 0;

        return number_format((float) $internal + (float) $external, 2, '.', '');
    }

    /** The same collected amount as an SQL expression over a sponsorship_dues alias (exact numeric arithmetic). */
    public static function collectedSql(string $due = 'd'): string
    {
        return "((select coalesce(sum(a.amount), 0) from finance_payment_allocations a join finance_transactions t on t.id = a.transaction_id
            where a.receivable_module = 'finance' and a.receivable_id = {$due}.finance_receivable_id::text and a.reversed_by_transaction_id is null and t.state = 'executed')
          + (select coalesce(sum(x.amount), 0) from sponsorship_receipt_allocations x where x.due_id = {$due}.id and x.reversed_at is null))";
    }

    /**
     * «متأخر»: dues referred to Accounts whose date has passed and whose collection (read from Accounts, as in
     * collected()) is below the amount. Derived and read-only; the department never changes a balance.
     */
    public static function overdueDues(): Builder
    {
        return DB::table('sponsorship_dues as d')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')
            ->where('d.status', 'referred')->where('d.due_on', '<', today()->toDateString())
            ->whereRaw(self::collectedSql('d').' < d.amount');
    }

    /**
     * Daily: keeps the planned dues of every live sponsorship twelve months ahead (or to its end date) through
     * schedule(), so the eligibility gate applies. A sponsorship that fails it is left unchanged and returned in
     * «blocked», so the head is told that its commitment is no longer being scheduled.
     *
     * @return array{sponsorships: int, added: int, blocked: list<array{reference_no: string, public_id: string}>}
     */
    public function extendSchedules(): array
    {
        $result = ['sponsorships' => 0, 'added' => 0, 'blocked' => []];
        DB::table('sponsorships')->whereIn('status', ['active', 'partial'])->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))
            ->select(['id', 'reference_no', 'public_id'])->chunkById(200, function ($rows) use (&$result): void {
                foreach ($rows as $row) {
                    try {
                        $result['added'] += $this->schedule($row);
                        $result['sponsorships']++;
                    } catch (ValidationException|HttpExceptionInterface) {
                        $result['blocked'][] = ['reference_no' => (string) $row->reference_no, 'public_id' => (string) $row->public_id];
                    }
                }
            });

        return $result;
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
        $this->access->assertDecide($actor, 'اعتماد طلبات المساعدة لرئيس القسم.');
        abort_unless($request->status === 'requested', 409, 'تم البت في الطلب مسبقًا.');
        if ($decision !== 'approved' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'اذكر السبب.']);
        }
        DB::table('assistance_requests')->where('id', $request->id)->update(['status' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.assistance.'.$decision, 'assistance_requests', $request->id, null, ['note' => $note], $actor->id, request()?->ip());
    }

    /**
     * Records a confirmed delivery (approval is not delivery). Household aid is booked once on the household; a
     * child-specific item is booked on the child even if the guardian received it. Assistance is delivered only
     * against its approved request. The execution reference is typed (minute, Stores issue, Accounts document) and
     * the same delivery (beneficiary, type, sponsorship, reference) is never booked twice; a reversed booking may be
     * recorded again.
     */
    public function recordBenefit(array $data, array $unit, User $actor): int
    {
        abort_unless($this->access->allows($actor, 'sponsorships') || $this->access->allows($actor, 'delivery'), 403, 'تسجيل الاستفادة يحتاج قدرة الكفالات أو التسليم.');
        $source = (string) ($data['source'] ?? 'other');
        $ref = trim((string) ($data['fulfillment_ref'] ?? ''));
        $kind = $ref === '' ? null : ($data['fulfillment_kind'] ?? null);
        if ($ref === '' && $source !== 'other') {
            throw ValidationException::withMessages(['fulfillment_ref' => 'مرجع التنفيذ مطلوب (رقم سند الحسابات أو حركة المخزن أو محضر التسليم).']);
        }
        if ($ref !== '' && ! isset(self::FULFILLMENT_KINDS[$kind])) {
            throw ValidationException::withMessages(['fulfillment_kind' => 'حدد نوع مرجع التنفيذ: محضر تسليم أو حركة صرف من المخازن أو سند من الحسابات.']);
        }
        if ($source === 'assistance' && empty($data['assistance_request_id'])) {
            throw ValidationException::withMessages(['assistance_public_id' => 'تسليم المساعدة يُسجَّل على طلب مساعدة معتمد.']);
        }
        if ($source === 'sponsorship' && empty($data['sponsorship_id'])) {
            throw ValidationException::withMessages(['sponsorship_public_id' => 'حدد الكفالة التي تُسلَّم منها الاستفادة.']);
        }
        if ($ref !== '') {
            $this->assertFulfillmentRef($kind, $ref);
        }

        return DB::transaction(function () use ($data, $unit, $actor, $ref, $kind): int {
            if (! empty($data['assistance_request_id'])) {
                $request = DB::table('assistance_requests')->lockForUpdate()->find($data['assistance_request_id']);
                abort_unless($request && $request->status === 'approved', 409, 'طلب المساعدة غير معتمد أو مُنفذ مسبقًا.');
                $unit = array_filter(['orphan_id' => $request->orphan_id, 'household_id' => $request->household_id]);
            }
            $unit = array_filter(['orphan_id' => $unit['orphan_id'] ?? null, 'household_id' => $unit['household_id'] ?? null]);
            if (count($unit) !== 1) {
                throw ValidationException::withMessages(['unit' => 'حدد المستفيد (يتيمًا أو أسرة).']);
            }
            // A request-backed delivery is already guarded by the request lock and its approved→fulfilled move, so
            // several approved requests may share one minute or voucher. Otherwise the key is unit, type, sponsorship
            // and reference: only the same delivery booked twice is refused.
            if ($ref !== '' && empty($data['assistance_request_id'])) {
                $column = array_key_first($unit);
                $type = $data['type_value_id'] ?? null;
                $sponsorship = $data['sponsorship_id'] ?? null;
                // Serialises bookings of the same key, so two people cannot record the same parcel at once.
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['benefit:'.$column.':'.$unit[$column].':'.$type.':'.$sponsorship.':'.$kind.':'.$ref]);
                $duplicate = DB::table('benefit_records as b')->where('b.kind', 'delivery')->where('b.'.$column, $unit[$column])->where('b.fulfillment_ref', $ref)
                    ->where(fn ($q) => $q->where('b.fulfillment_kind', $kind)->orWhereNull('b.fulfillment_kind'))
                    ->when($type === null, fn ($q) => $q->whereNull('b.type_value_id'), fn ($q) => $q->where('b.type_value_id', $type))
                    ->when($sponsorship === null, fn ($q) => $q->whereNull('b.sponsorship_id'), fn ($q) => $q->where('b.sponsorship_id', $sponsorship))
                    ->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'))->value('b.delivered_on');
                if ($duplicate) {
                    throw ValidationException::withMessages(['fulfillment_ref' => 'سُجّلت لهذا المستفيد استفادة من النوع نفسه بالمرجع نفسه بتاريخ '.$duplicate.'؛ لا تُسجَّل مرتين. للتصحيح يُعكس القيد أولًا.']);
                }
            }
            $id = DB::table('benefit_records')->insertGetId(['public_id' => (string) Str::ulid(), 'kind' => 'delivery', ...$unit,
                ...collect($data)->only(['source', 'campaign_id', 'campaign_delivery_id', 'sponsorship_id', 'assistance_request_id', 'type_value_id', 'description', 'quantity', 'unit', 'amount', 'currency',
                    'recipient_person_id', 'recipient_name', 'delivered_on'])->all(), 'fulfillment_ref' => $ref !== '' ? $ref : null, 'fulfillment_kind' => $kind,
                'recorded_by' => $actor->id, 'created_at' => now()]);
            if (! empty($data['assistance_request_id'])) {
                DB::table('assistance_requests')->where('id', $data['assistance_request_id'])->update(['status' => 'fulfilled', 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.benefit.recorded', 'benefit_records', $id, null, ['source' => $data['source'], 'reference' => $kind ? $kind.':'.$ref : null], $actor->id, request()?->ip());

            return $id;
        });
    }

    /**
     * A Stores reference must be an issue movement of the Stores ledger, and not one already booked for a campaign or
     * a warehouse transfer (counted elsewhere). An Accounts document number found in AIMS must be executed and not
     * reversed; a number not found here is a voucher of the external books, kept as given.
     */
    private function assertFulfillmentRef(string $kind, string $ref): void
    {
        if ($kind === 'stock_movement' && ! (ctype_digit($ref) && DB::table('finance_stock_movements')->where('id', (int) $ref)->where('kind', 'issue')
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['campaign', 'warehouse_transfer']))->exists())) {
            throw ValidationException::withMessages(['fulfillment_ref' => 'رقم حركة الصرف غير موجود في سجل المخازن، أو ليس حركة صرف، أو صرفٌ لحملة أو نقل بين مخازن.']);
        }
        if ($kind === 'finance_document') {
            $document = DB::table('finance_transactions')->where('document_no', $ref)->first(['id', 'state']);
            if ($document && ($document->state !== 'executed' || DB::table('finance_transactions')->where('reversal_of_id', $document->id)->exists())) {
                throw ValidationException::withMessages(['fulfillment_ref' => 'سند الحسابات '.$ref.' غير منفذ أو معكوس؛ لا يُسجَّل عليه تسليم.']);
            }
        }
    }

    /** A correction: a reversal row pointing to the original, which stays untouched. */
    public function reverseBenefit(object $benefit, string $reason, User $actor): int
    {
        $this->access->assertDecide($actor, 'عكس استفادة مؤكدة لرئيس القسم.');
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
