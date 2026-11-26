<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Finance\FinanceGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collections made in the external accounting software, recorded by Accounts against rent and service dues
 * (owner decision 2026-10-23: AIMS keeps the reference, not the books). One voucher may cover several dues;
 * the same voucher cannot count twice on a due (partial unique index), a resubmitted form is recognised by its
 * request key, and an allocation is reversed, never deleted. No amount may exceed what remains on a due: an
 * overpayment stays as the tenant's credit in the accounting software until a due or an approved settlement uses it.
 */
final class RentalReceipts
{
    public function __construct(private readonly FinanceGuard $guard, private readonly RentalLedger $ledger, private readonly RentalAudit $audit) {}

    /**
     * @param  array{request_key: string, reference: string, posted_on: string, payment_method_value_id?: ?int, note?: ?string, allocations: list<array{due_id: int, amount: string}>}  $data
     * @return int allocations recorded (0 when the same form was already recorded)
     */
    public function record(User $actor, array $data): int
    {
        $this->guard->assert($actor, 'finance.rentals.record');

        return DB::transaction(function () use ($actor, $data): int {
            if (DB::table('aims_rental_receipt_allocations')->where('request_key', $data['request_key'])->exists()) {
                return 0;
            }
            $dueIds = collect($data['allocations'])->pluck('due_id')->map(fn ($id): int => (int) $id)->sort()->values();
            if ($dueIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['allocations' => 'لا يتكرر الاستحقاق نفسه في سند واحد.']);
            }
            $dues = DB::table('aims_rental_dues')->whereIn('id', $dueIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($dues->count() !== $dueIds->count()) {
                throw ValidationException::withMessages(['allocations' => 'أحد الاستحقاقات غير موجود.']);
            }
            if ($dues->pluck('currency_code')->unique()->count() > 1) {
                throw ValidationException::withMessages(['allocations' => 'السند الواحد بعملة واحدة؛ الاستحقاقات المختارة بعملات مختلفة.']);
            }
            $system = $this->guard->externalSystemName();
            $first = true;
            foreach ($data['allocations'] as $index => $allocation) {
                $due = $dues[(int) $allocation['due_id']];
                $amount = MoneyRules::toMinor((string) $allocation['amount']);
                $remaining = $this->ledger->remainingMinor((int) $due->id);
                if ($due->status === 'cancelled') {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'الاستحقاق ملغى.']);
                }
                if ($amount <= 0 || $amount > $remaining) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'المبلغ يتجاوز المتبقي على الاستحقاق ('.number_format($remaining / 100, 2).'). الزيادة تبقى رصيدًا دائنًا للمستأجر في '.$system.' وتُخصص لاحقًا أو بتسوية معتمدة.']);
                }
                try {
                    $id = DB::table('aims_rental_receipt_allocations')->insertGetId([
                        'due_id' => $due->id, 'accounting_reference' => $data['reference'], 'amount' => number_format($amount / 100, 2, '.', ''),
                        'currency_code' => $due->currency_code, 'accounting_posted_at' => $data['posted_on'].' 00:00:00', 'recorded_by' => $actor->id,
                        'external_system' => $system, 'payment_method_value_id' => $data['payment_method_value_id'] ?? null, 'note' => $data['note'] ?? null,
                        'request_key' => $first ? $data['request_key'] : null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['reference' => 'السند '.$data['reference'].' مسجل مسبقًا على أحد هذه الاستحقاقات.']);
                }
                $first = false;
                $this->ledger->refreshStatus((int) $due->id);
                $this->guard->audit('rental_due', (int) $due->id, (int) $actor->id, 'rental.receipt.recorded', ['allocation' => $id, 'reference' => $data['reference'], 'amount' => $amount / 100, 'system' => $system]);
                $this->audit->log((int) $actor->id, 'rental.receipt.recorded', 'aims_rental_receipt_allocations', $id, null,
                    ['due_id' => (int) $due->id, 'reference' => $data['reference'], 'amount' => $amount / 100, 'currency' => $due->currency_code, 'posted_on' => $data['posted_on']]);
            }

            return count($data['allocations']);
        });
    }

    /** Reverse a recorded allocation (the external voucher was corrected or reversed there); the record stays. */
    public function reverse(User $actor, int $allocationId, string $reason, ?string $reference): void
    {
        $this->guard->assert($actor, 'finance.rentals.record');
        DB::transaction(function () use ($actor, $allocationId, $reason, $reference): void {
            $allocation = DB::table('aims_rental_receipt_allocations')->where('id', $allocationId)->lockForUpdate()->first();
            abort_unless($allocation, 404);
            if ($allocation->reversed_at !== null) {
                throw ValidationException::withMessages(['reason' => 'هذا القبض معكوس مسبقًا.']);
            }
            DB::table('aims_rental_dues')->where('id', $allocation->due_id)->lockForUpdate()->first();
            DB::table('aims_rental_receipt_allocations')->where('id', $allocationId)->update(['reversed_at' => now(), 'reversed_by' => $actor->id,
                'reversal_reason' => $reason, 'reversal_reference' => $reference, 'updated_at' => now()]);
            $this->ledger->refreshStatus((int) $allocation->due_id);
            $this->guard->audit('rental_due', (int) $allocation->due_id, (int) $actor->id, 'rental.receipt.reversed', ['allocation' => $allocationId, 'reason' => $reason, 'reference' => $reference]);
            $this->audit->log((int) $actor->id, 'rental.receipt.reversed', 'aims_rental_receipt_allocations', $allocationId,
                ['reference' => $allocation->accounting_reference, 'amount' => $allocation->amount], ['reversed' => true, 'reason' => $reason, 'reversal_reference' => $reference]);
        });
    }
}
