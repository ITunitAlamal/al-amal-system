<?php

namespace App\Services\Orphans;

use App\Models\OrphanPaymentInstruction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What Accounts record for sponsorships from their own documents in the external accounting software (owner
 * decision 2026-10-23: AIMS keeps references, not the books): approval of payout details, collections against
 * dues, payouts to families, the child's savings ledger (credited only from posted collections) and the receipt of
 * one-off donations. Needs finance.sponsorships.record with an active finance assignment; never the administrator's
 * technical role. Nothing is deleted: a wrong record is reversed and stays for audit.
 */
final class SponsorshipAccounts
{
    private const MONEY = ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'];

    public function __construct(
        private readonly FinanceGuard $guard,
        private readonly Sponsorships $sponsorships,
        private readonly OrphanAccess $access,
        private readonly AuditService $audit,
    ) {}

    public function allows(User $user): bool
    {
        return ! $user->isSystemAdmin() && $this->guard->allows($user, 'finance.sponsorships.record');
    }

    public function assert(User $user): void
    {
        abort_unless($this->allows($user), 403, 'يسجله قسم الحسابات من مستنداته (صلاحية قبض وصرف الكفالات مع تخصيص مالي نشط).');
    }

    /** Approves or rejects a household's payout details; the approved one replaces the previous. */
    public function reviewPayment(User $actor, int $instructionId, string $decision, ?string $note): void
    {
        $this->assert($actor);
        if ($decision === 'rejected' && mb_strlen(trim((string) $note)) < 3) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب الرفض.']);
        }
        DB::transaction(function () use ($actor, $instructionId, $decision, $note): void {
            $row = DB::table('orphan_payment_instructions')->where('id', $instructionId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pending_review') {
                throw ValidationException::withMessages(['payment' => 'بيانات الصرف هذه رُوجعت مسبقًا.']);
            }
            abort_if((int) $row->submitted_by === (int) $actor->id, 403, 'لا يعتمد مُدخل بيانات الصرف ما أدخله.');
            if ($decision === 'approved') {
                DB::table('orphan_payment_instructions')->where('household_id', $row->household_id)->where('status', 'approved')->lockForUpdate()
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            DB::table('orphan_payment_instructions')->where('id', $instructionId)->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'review_note' => $note, 'updated_at' => now()]);
            $this->guard->audit('orphan_payment_instruction', $instructionId, (int) $actor->id, 'sponsorship.payout_details.'.$decision, ['household' => (int) $row->household_id]);
            $this->audit->recordRow('orphans.payment_instruction.'.$decision, 'orphan_payment_instructions', $instructionId, ['status' => 'pending_review'], ['status' => $decision], $actor->id, request()?->ip());
        });
    }

    /** The full payout details, for Accounts only; each read is logged. */
    public function paymentDetails(User $actor, int $instructionId): array
    {
        $this->assert($actor);
        $row = OrphanPaymentInstruction::findOrFail($instructionId);
        $this->access->logRead($actor, 'payment_instruction', $row->id);

        return ['account_holder' => $row->account_holder, 'account_identifier' => $row->account_identifier, 'branch' => $row->branch];
    }

    /**
     * A collection recorded against one due. The savings part of a split or savings sponsorship goes to the child's
     * ledger in proportion to what is collected, so a fully collected due credits exactly its savings part.
     *
     * @param  array<string, mixed>  $input
     * @return int the collection id, or 0 when the same form was already recorded
     */
    public function recordCollection(User $actor, array $input): int
    {
        $this->assert($actor);
        $data = validator($input, ['request_key' => ['required', 'uuid'], 'schedule_id' => ['required', 'integer'], 'amount' => self::MONEY,
            'reference' => ['required', 'string', 'min:2', 'max:120'], 'posted_on' => ['required', 'date', 'before_or_equal:today']],
            [], ['reference' => 'رقم السند', 'posted_on' => 'تاريخ السند', 'amount' => 'المبلغ'])->validate();

        return DB::transaction(function () use ($actor, $data): int {
            if (DB::table('sponsorship_collections')->where('request_key', $data['request_key'])->exists()) {
                return 0;
            }
            $schedule = DB::table('sponsorship_schedules')->where('id', $data['schedule_id'])->lockForUpdate()->first();
            abort_unless($schedule, 404);
            $sponsorship = DB::table('sponsorships')->where('id', $schedule->sponsorship_id)->lockForUpdate()->first();
            if (in_array($schedule->status, ['cancelled', 'waived'], true) || ! in_array($sponsorship->status, ['active', 'paused', 'ended', 'superseded'], true)) {
                throw ValidationException::withMessages(['schedule_id' => 'الاستحقاق ملغى أو الكفالة غير معتمدة.']);
            }
            $expected = $this->cents($schedule->expected_amount);
            $collected = $this->cents(DB::table('sponsorship_collections')->where('schedule_id', $schedule->id)->whereNull('reversed_at')->sum('amount'));
            $amount = FinanceMoney::cents((string) $data['amount']);
            $system = $this->guard->externalSystemName();
            if ($amount <= 0 || $amount > $expected - $collected) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز المتبقي على الاستحقاق ('.FinanceMoney::format($expected - $collected).'). الزيادة تبقى رصيدًا للكافل في '.$system.' وتُسجل على استحقاق تالٍ.']);
            }
            try {
                $id = DB::table('sponsorship_collections')->insertGetId(['sponsorship_id' => $sponsorship->id, 'schedule_id' => $schedule->id,
                    'amount' => FinanceMoney::format($amount), 'currency' => $sponsorship->currency, 'accounting_reference' => trim($data['reference']), 'posted_on' => $data['posted_on'],
                    'external_system' => $system, 'request_key' => $data['request_key'], 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['reference' => 'السند '.$data['reference'].' مسجل مسبقًا على هذا الاستحقاق.']);
            }
            $total = $collected + $amount;
            DB::table('sponsorship_schedules')->where('id', $schedule->id)->update(['status' => $total >= $expected ? 'collected' : 'partial', 'updated_at' => now()]);
            $savingsPart = $this->cents($schedule->savings_part);
            if ($sponsorship->orphan_child_id && $savingsPart > 0) {
                $target = $total >= $expected ? $savingsPart : (int) floor($total / $expected * $savingsPart);
                $already = $this->cents(DB::table('orphan_savings_entries')->whereIn('collection_id', DB::table('sponsorship_collections')->where('schedule_id', $schedule->id)->select('id'))
                    ->selectRaw("coalesce(sum(case when kind = 'credit' then amount else -amount end), 0) as n")->value('n'));
                if ($target - $already > 0) {
                    DB::table('orphan_savings_entries')->insert(['orphan_child_id' => $sponsorship->orphan_child_id, 'sponsorship_id' => $sponsorship->id, 'collection_id' => $id,
                        'kind' => 'credit', 'amount' => FinanceMoney::format($target - $already), 'currency' => $sponsorship->currency, 'accounting_reference' => trim($data['reference']),
                        'reason' => 'حصة الادخار من تحصيل '.$schedule->period_start, 'recorded_by' => $actor->id, 'created_at' => now()]);
                }
            }
            $this->sponsorships->refreshRegularity((int) $sponsorship->id);
            $this->guard->audit('sponsorship_schedule', (int) $schedule->id, (int) $actor->id, 'sponsorship.collection.recorded', ['collection' => $id, 'reference' => $data['reference'], 'amount' => $amount / 100, 'system' => $system]);
            $this->audit->recordRow('sponsorships.collection.recorded', 'sponsorship_collections', $id, null, ['sponsorship' => $sponsorship->sponsorship_no, 'period' => $schedule->period_start,
                'amount' => FinanceMoney::format($amount), 'currency' => $sponsorship->currency, 'reference' => $data['reference']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Reverses a recorded collection (the voucher was reversed in the accounting software) with its savings share. */
    public function reverseCollection(User $actor, int $collectionId, string $reason): void
    {
        $this->assert($actor);
        $this->requireReason($reason);
        DB::transaction(function () use ($actor, $collectionId, $reason): void {
            $collection = DB::table('sponsorship_collections')->where('id', $collectionId)->lockForUpdate()->first();
            abort_unless($collection, 404);
            if ($collection->reversed_at !== null) {
                throw ValidationException::withMessages(['reason' => 'هذا التحصيل معكوس مسبقًا.']);
            }
            $schedule = DB::table('sponsorship_schedules')->where('id', $collection->schedule_id)->lockForUpdate()->first();
            $sponsorship = DB::table('sponsorships')->where('id', $collection->sponsorship_id)->lockForUpdate()->first();
            $amount = $this->cents($collection->amount);
            $savingsShare = $this->cents(DB::table('orphan_savings_entries')->where('collection_id', $collectionId)
                ->selectRaw("coalesce(sum(case when kind = 'credit' then amount else -amount end), 0) as n")->value('n'));
            if ($this->cents($this->sponsorships->figures($sponsorship)['available']) < $amount - $savingsShare) {
                throw ValidationException::withMessages(['reason' => 'صُرف للأسرة من حصة المصاريف في هذا التحصيل؛ اعكس الصرف أولًا.']);
            }
            if ($savingsShare > 0) {
                DB::table('orphan_savings_entries')->where('orphan_child_id', $sponsorship->orphan_child_id)->lockForUpdate()->get(['id']);
                if (($this->savingsBalances((int) $sponsorship->orphan_child_id)[$sponsorship->currency] ?? 0) < $savingsShare) {
                    throw ValidationException::withMessages(['reason' => 'رصيد ادخار الطفل أقل من حصة الادخار في هذا التحصيل؛ راجع السحوبات المسجلة قبل العكس.']);
                }
                DB::table('orphan_savings_entries')->insert(['orphan_child_id' => $sponsorship->orphan_child_id, 'sponsorship_id' => $sponsorship->id, 'collection_id' => $collectionId,
                    'kind' => 'reversal', 'amount' => FinanceMoney::format($savingsShare), 'currency' => $collection->currency, 'accounting_reference' => $collection->accounting_reference,
                    'reason' => 'عكس تحصيل: '.$reason, 'recorded_by' => $actor->id, 'created_at' => now()]);
            }
            DB::table('sponsorship_collections')->where('id', $collectionId)->update(['reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason, 'updated_at' => now()]);
            $left = $this->cents(DB::table('sponsorship_collections')->where('schedule_id', $schedule->id)->whereNull('reversed_at')->sum('amount'));
            DB::table('sponsorship_schedules')->where('id', $schedule->id)->update(['status' => $left <= 0 ? 'expected' : ($left >= $this->cents($schedule->expected_amount) ? 'collected' : 'partial'),
                'updated_at' => now()]);
            $this->sponsorships->refreshRegularity((int) $sponsorship->id);
            $this->guard->audit('sponsorship_schedule', (int) $schedule->id, (int) $actor->id, 'sponsorship.collection.reversed', ['collection' => $collectionId, 'reason' => $reason]);
            $this->audit->recordRow('sponsorships.collection.reversed', 'sponsorship_collections', $collectionId, ['amount' => $collection->amount, 'reference' => $collection->accounting_reference],
                ['reversed' => true, 'reason' => $reason, 'savings_reversed' => FinanceMoney::format($savingsShare)], $actor->id, request()?->ip());
        });
    }

    /**
     * A payout to the family through its approved payout details, capped by what was collected for expenses and
     * not yet paid out (no payment without a posted collection).
     *
     * @param  array<string, mixed>  $input
     */
    public function recordDisbursement(User $actor, array $input): int
    {
        $this->assert($actor);
        $data = validator($input, ['sponsorship_id' => ['required', 'integer'], 'schedule_id' => ['nullable', 'integer'], 'amount' => self::MONEY,
            'reference' => ['required', 'string', 'min:2', 'max:120'], 'executed_on' => ['required', 'date', 'before_or_equal:today']],
            [], ['reference' => 'رقم سند الصرف', 'executed_on' => 'تاريخ الصرف', 'amount' => 'المبلغ'])->validate();

        return DB::transaction(function () use ($actor, $data): int {
            $sponsorship = DB::table('sponsorships')->where('id', $data['sponsorship_id'])->lockForUpdate()->first();
            abort_unless($sponsorship, 404);
            if (! in_array($sponsorship->status, ['active', 'paused', 'ended', 'superseded'], true)) {
                throw ValidationException::withMessages(['sponsorship_id' => 'الكفالة غير معتمدة.']);
            }
            if (! empty($data['schedule_id']) && ! DB::table('sponsorship_schedules')->where('id', $data['schedule_id'])->where('sponsorship_id', $sponsorship->id)->exists()) {
                throw ValidationException::withMessages(['schedule_id' => 'الفترة لا تخص هذه الكفالة.']);
            }
            $instruction = DB::table('orphan_payment_instructions')->where('household_id', $sponsorship->household_id)->where('status', 'approved')->lockForUpdate()->first();
            if (! $instruction) {
                throw ValidationException::withMessages(['sponsorship_id' => 'لا توجد بيانات صرف معتمدة من الحسابات لهذه الأسرة.']);
            }
            $amount = FinanceMoney::cents((string) $data['amount']);
            $available = $this->cents($this->sponsorships->figures($sponsorship)['available']);
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز ما حُصّل لحصة المصاريف ولم يُصرف بعد ('.FinanceMoney::format($available).' '.$sponsorship->currency.').']);
            }
            try {
                $id = DB::table('sponsorship_disbursements')->insertGetId(['sponsorship_id' => $sponsorship->id, 'schedule_id' => $data['schedule_id'] ?? null,
                    'household_id' => $sponsorship->household_id, 'payment_instruction_id' => $instruction->id, 'amount' => FinanceMoney::format($amount), 'currency' => $sponsorship->currency,
                    'accounting_reference' => trim($data['reference']), 'executed_on' => $data['executed_on'], 'status' => 'executed', 'recorded_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['reference' => 'سند الصرف '.$data['reference'].' مسجل مسبقًا لهذه الكفالة.']);
            }
            $this->guard->audit('sponsorship', (int) $sponsorship->id, (int) $actor->id, 'sponsorship.disbursement.recorded', ['disbursement' => $id, 'reference' => $data['reference'], 'amount' => $amount / 100]);
            $this->audit->recordRow('sponsorships.disbursement.recorded', 'sponsorship_disbursements', $id, null, ['sponsorship' => $sponsorship->sponsorship_no,
                'amount' => FinanceMoney::format($amount), 'currency' => $sponsorship->currency, 'reference' => $data['reference']], $actor->id, request()?->ip());

            return $id;
        });
    }

    public function reverseDisbursement(User $actor, int $disbursementId, string $reason): void
    {
        $this->assert($actor);
        $this->requireReason($reason);
        DB::transaction(function () use ($actor, $disbursementId, $reason): void {
            $row = DB::table('sponsorship_disbursements')->where('id', $disbursementId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'executed') {
                throw ValidationException::withMessages(['reason' => 'هذا الصرف معكوس مسبقًا.']);
            }
            DB::table('sponsorship_disbursements')->where('id', $disbursementId)->update(['status' => 'reversed', 'reversed_at' => now(), 'reversal_reason' => $reason, 'updated_at' => now()]);
            $this->guard->audit('sponsorship', (int) $row->sponsorship_id, (int) $actor->id, 'sponsorship.disbursement.reversed', ['disbursement' => $disbursementId, 'reason' => $reason]);
            $this->audit->recordRow('sponsorships.disbursement.reversed', 'sponsorship_disbursements', $disbursementId, ['amount' => $row->amount, 'reference' => $row->accounting_reference],
                ['reversed' => true, 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * A withdrawal from a child's savings, recorded from the authorised payment document; never more than the
     * balance in that currency.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordSavingsDebit(User $actor, array $input): void
    {
        $this->assert($actor);
        $data = validator($input, ['file_no' => ['required', 'string', 'max:30'], 'currency' => ['required', 'string', 'size:3'], 'amount' => self::MONEY,
            'reference' => ['required', 'string', 'min:2', 'max:120'], 'reason' => ['required', 'string', 'min:5', 'max:1000']],
            [], ['file_no' => 'رقم ملف الطفل', 'reference' => 'رقم سند الصرف', 'reason' => 'سبب السحب ومرجع الإذن'])->validate();
        DB::transaction(function () use ($actor, $data): void {
            $child = DB::table('orphan_children')->where('file_no', trim($data['file_no']))->first(['id', 'file_no']);
            if (! $child) {
                throw ValidationException::withMessages(['file_no' => 'لا يوجد طفل بهذا الرقم.']);
            }
            DB::table('orphan_savings_entries')->where('orphan_child_id', $child->id)->lockForUpdate()->get(['id']);
            $currency = strtoupper($data['currency']);
            $balance = $this->savingsBalances((int) $child->id)[$currency] ?? 0;
            $amount = FinanceMoney::cents((string) $data['amount']);
            if ($amount <= 0 || $amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز رصيد ادخار الطفل بهذه العملة ('.FinanceMoney::format($balance).' '.$currency.').']);
            }
            DB::table('orphan_savings_entries')->insert(['orphan_child_id' => $child->id, 'kind' => 'debit', 'amount' => FinanceMoney::format($amount), 'currency' => $currency,
                'accounting_reference' => trim($data['reference']), 'reason' => $data['reason'], 'recorded_by' => $actor->id, 'created_at' => now()]);
            $this->guard->audit('orphan_savings', (int) $child->id, (int) $actor->id, 'sponsorship.savings.debited', ['reference' => $data['reference'], 'amount' => $amount / 100, 'currency' => $currency]);
            $this->audit->recordRow('orphans.savings.debited', 'orphan_children', (int) $child->id, null, ['amount' => FinanceMoney::format($amount), 'currency' => $currency,
                'reference' => $data['reference']], $actor->id, request()?->ip());
        });
    }

    /** Receipt of a pledged one-off donation: the voucher number and date from the accounting software. */
    public function collectContribution(User $actor, int $contributionId, array $input): void
    {
        $this->assert($actor);
        $data = validator($input, ['reference' => ['required', 'string', 'min:2', 'max:120'], 'posted_on' => ['required', 'date', 'before_or_equal:today']],
            [], ['reference' => 'رقم سند القبض', 'posted_on' => 'تاريخ السند'])->validate();
        DB::transaction(function () use ($actor, $contributionId, $data): void {
            $row = DB::table('orphan_contributions')->where('id', $contributionId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pledged') {
                throw ValidationException::withMessages(['reference' => 'التبرع ليس تعهدًا بانتظار القبض.']);
            }
            DB::table('orphan_contributions')->where('id', $contributionId)->update(['status' => 'collected', 'accounting_reference' => trim($data['reference']),
                'posted_on' => $data['posted_on'], 'collected_by' => $actor->id, 'updated_at' => now()]);
            $this->guard->audit('orphan_contribution', $contributionId, (int) $actor->id, 'sponsorship.contribution.collected', ['reference' => $data['reference'], 'amount' => (float) $row->amount]);
            $this->audit->recordRow('orphans.contribution.collected', 'orphan_contributions', $contributionId, ['status' => 'pledged'], ['status' => 'collected', 'reference' => $data['reference']],
                $actor->id, request()?->ip());
        });
    }

    public function refundContribution(User $actor, int $contributionId, string $reason): void
    {
        $this->assert($actor);
        $this->requireReason($reason);
        DB::transaction(function () use ($actor, $contributionId, $reason): void {
            $row = DB::table('orphan_contributions')->where('id', $contributionId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'collected') {
                throw ValidationException::withMessages(['reason' => 'يُرد التبرع المقبوض فقط.']);
            }
            DB::table('orphan_contributions')->where('id', $contributionId)->update(['status' => 'refunded', 'notes' => trim(($row->notes ? $row->notes."\n" : '').'رد: '.$reason),
                'updated_at' => now()]);
            $this->guard->audit('orphan_contribution', $contributionId, (int) $actor->id, 'sponsorship.contribution.refunded', ['reason' => $reason]);
            $this->audit->recordRow('orphans.contribution.refunded', 'orphan_contributions', $contributionId, ['status' => 'collected'], ['status' => 'refunded', 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /** @return array<string, int> currency => balance in minor units */
    public function savingsBalances(int $childId): array
    {
        return DB::table('orphan_savings_entries')->where('orphan_child_id', $childId)->groupBy('currency')
            ->selectRaw("currency, sum(case when kind = 'credit' then amount else -amount end) as balance")->pluck('balance', 'currency')
            ->map(fn ($balance): int => $this->cents($balance))->all();
    }

    /** Currencies that Accounts may record in. */
    public function currencies(): array
    {
        return DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')->all();
    }

    private function requireReason(string $reason): void
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']], [], ['reason' => 'السبب'])->validate();
    }

    private function cents(mixed $value): int
    {
        return FinanceMoney::cents(number_format((float) ($value ?? 0), 2, '.', ''));
    }
}
