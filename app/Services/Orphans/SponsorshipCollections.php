<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «قبض الكفالات» (ACC-09): sponsor payments are collected in the external accounting software while Accounts run the
 * documentary cycle, so the holder of finance.sponsorships.record records the receipt reference, date and amount
 * against the sponsorship due the department referred (like the external voucher of a supplier invoice). AIMS keeps
 * the reference, not the books. A resubmitted form is recognised by its request key, bound to the form's data (the
 * same key with another due, reference or amount is refused); the same reference cannot count
 * twice on a due; no amount exceeds what remains (also a trigger of 000036); a record is reversed, never deleted.
 * Aid::collected / overdueDues read these rows, so the department sees the collection and the arrears it derives.
 */
final class SponsorshipCollections
{
    public const PERMISSION = 'finance.sponsorships.record';

    public function __construct(private readonly FinanceGuard $guard, private readonly AuditService $audit) {}

    /** Dues referred to Accounts, with what was collected and what remains. */
    public function dues(): Builder
    {
        return DB::table('sponsorship_dues as d')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')
            ->leftJoin('finance_receivables as r', 'r.id', '=', 'd.finance_receivable_id')->where('d.status', 'referred')
            ->select(['d.id', 'd.due_on', 'd.period_start', 'd.amount', 'd.currency', 'd.referred_at', 's.reference_no as sponsorship_reference', 'p.name as sponsor', 'r.reference as receivable_reference'])
            ->selectRaw(Aid::collectedSql('d').' as collected')
            ->selectRaw('d.amount - '.Aid::collectedSql('d').' as remaining');
    }

    /**
     * @param  array{request_key: string, due_id: int, reference: string, posted_on: string, amount: string, note?: ?string}  $data
     * @return bool false when the same form was already recorded
     */
    public function record(User $actor, array $data): bool
    {
        $this->guard->assert($actor, self::PERMISSION);
        if (! $this->guard->documentaryMode()) {
            throw ValidationException::withMessages(['due_id' => 'تسجيل قبض خارجي متاح في وضع الدورة المستندية فقط؛ استخدم سند القبض الداخلي.']);
        }

        $hash = hash('sha256', json_encode([(int) $data['due_id'], FinanceMoney::cents($data['amount']), mb_strtolower(trim($data['reference'])), (string) $data['posted_on']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $hash): bool {
            // One holder of a request key at a time: a concurrent resubmission waits here, then finds the first record.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['sponsorship-receipt:'.$data['request_key']]);
            if ($existing = DB::table('sponsorship_receipt_allocations')->where('request_key', $data['request_key'])->first()) {
                $same = $existing->request_hash !== null ? hash_equals($existing->request_hash, $hash)
                    : (int) $existing->due_id === (int) $data['due_id'] && FinanceMoney::cents((string) $existing->amount) === FinanceMoney::cents($data['amount'])
                        && mb_strtolower(trim($existing->accounting_reference)) === mb_strtolower(trim($data['reference']));
                if (! $same) {
                    throw ValidationException::withMessages(['reference' => 'هذا النموذج سُجّل من قبل ببيانات أخرى (الاستحقاق أو السند أو المبلغ)؛ لم يُسجَّل شيء جديد. أعد تحميل الصفحة وراجع القبض المسجل.']);
                }

                return false;
            }
            $due = DB::table('sponsorship_dues')->where('id', $data['due_id'])->lockForUpdate()->first();
            abort_unless($due, 404);
            if ($due->status !== 'referred') {
                throw ValidationException::withMessages(['due_id' => 'يُسجَّل القبض على استحقاق أحاله قسم الكفالات إلى الحسابات فقط.']);
            }
            // Separation of duties: whoever referred the due to Accounts does not record its collection.
            if ((int) $due->referred_by === (int) $actor->id) {
                throw ValidationException::withMessages(['due_id' => 'أحلتَ هذا الاستحقاق بنفسك؛ يسجل قبضه موظف آخر في الحسابات.']);
            }
            $amount = FinanceMoney::cents($data['amount']);
            $collected = FinanceMoney::cents((string) DB::selectOne('select '.Aid::collectedSql('d').'::numeric(14,2) as c from sponsorship_dues d where d.id = ?', [$due->id])->c);
            $remaining = FinanceMoney::cents((string) $due->amount) - $collected;
            $system = $this->guard->externalSystemName();
            if ($amount <= 0 || $amount > $remaining) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي على الاستحقاق ('.FinanceMoney::format(max($remaining, 0)).' '.$due->currency
                    .'). الزيادة تبقى رصيدًا للكافل في '.$system.'.']);
            }
            $reference = trim($data['reference']);
            try {
                $id = DB::transaction(fn (): int => DB::table('sponsorship_receipt_allocations')->insertGetId([
                    'due_id' => $due->id, 'accounting_reference' => $reference, 'amount' => FinanceMoney::format($amount), 'currency' => $due->currency,
                    'posted_on' => $data['posted_on'], 'external_system' => $system, 'note' => $data['note'] ?? null, 'request_key' => $data['request_key'], 'request_hash' => $hash,
                    'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['reference' => 'السند '.$reference.' مسجل من قبل على هذا الاستحقاق.']);
            }
            $detail = ['due_id' => (int) $due->id, 'reference' => $reference, 'amount' => FinanceMoney::format($amount), 'currency' => $due->currency, 'posted_on' => $data['posted_on'], 'system' => $system];
            $this->guard->audit('sponsorship_due', (int) $due->id, (int) $actor->id, 'sponsorship.receipt.recorded', ['allocation' => $id, ...$detail]);
            $this->audit->recordRow('finance.sponsorship_receipt.recorded', 'sponsorship_receipt_allocations', $id, null, $detail, $actor->id, request()?->ip());

            return true;
        });
    }

    /** Reverse a recorded receipt (corrected or reversed in the accounting software); the record stays. */
    public function reverse(User $actor, int $allocationId, string $reason, ?string $reference): void
    {
        $this->guard->assert($actor, self::PERMISSION);
        DB::transaction(function () use ($actor, $allocationId, $reason, $reference): void {
            $allocation = DB::table('sponsorship_receipt_allocations')->where('id', $allocationId)->lockForUpdate()->first();
            abort_unless($allocation, 404);
            if ($allocation->reversed_at !== null) {
                throw ValidationException::withMessages(['reason' => 'هذا القبض معكوس من قبل.']);
            }
            DB::table('sponsorship_receipt_allocations')->where('id', $allocationId)->update(['reversed_at' => now(), 'reversed_by' => $actor->id,
                'reversal_reason' => $reason, 'reversal_reference' => $reference, 'updated_at' => now()]);
            $this->guard->audit('sponsorship_due', (int) $allocation->due_id, (int) $actor->id, 'sponsorship.receipt.reversed', ['allocation' => $allocationId, 'reason' => $reason, 'reference' => $reference]);
            $this->audit->recordRow('finance.sponsorship_receipt.reversed', 'sponsorship_receipt_allocations', $allocationId,
                ['reference' => $allocation->accounting_reference, 'amount' => (string) $allocation->amount], ['reversed' => true, 'reason' => $reason, 'reversal_reference' => $reference],
                $actor->id, request()?->ip());
        });
    }
}
