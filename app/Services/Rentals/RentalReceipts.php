<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Finance\FinanceGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * @param  array{request_key: string, reference: string, posted_on: string, voucher_amount?: ?string, payment_method_value_id?: ?int, note?: ?string, allocations: list<array{due_id: int, amount: string}>}  $data
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
            $data['reference'] = trim((string) $data['reference']);
            $this->assertVoucherCovers($actor, $data, (string) $dues->first()->currency_code);
            $first = true;
            foreach ($data['allocations'] as $index => $allocation) {
                $due = $dues[(int) $allocation['due_id']];
                $amount = MoneyRules::toMinor((string) $allocation['amount']);
                $remaining = $this->ledger->remainingMinor((int) $due->id);
                if ($due->status === 'cancelled') {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'الاستحقاق ملغى.']);
                }
                if (! $this->ledger->isObligation((int) $due->id)) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'عقد هذا الاستحقاق لم يُفعَّل بعد؛ لا يُسجَّل قبض إلا على استحقاق عقد نافذ.']);
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

    /**
     * One external voucher, one total (ACC-22): the first recording of a reference stores its amount, currency and
     * date in aims_rental_receipt_vouchers; every later recording of the same reference must state the same voucher,
     * and the live allocations of the reference (earlier ones included) never exceed its amount. Once every allocation
     * of the reference is reversed, a recording that states other voucher data corrects the stored voucher (audited).
     * The voucher row is locked, so two forms citing it cannot both pass. Skipped until the table exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertVoucherCovers(User $actor, array $data, string $currency): void
    {
        if (! Schema::hasTable('aims_rental_receipt_vouchers')) {
            return;
        }
        if (! isset($data['voucher_amount']) || (string) $data['voucher_amount'] === '') {
            throw ValidationException::withMessages(['voucher_amount' => 'أدخل المبلغ الإجمالي للسند كما في '.$this->guard->externalSystemName().'.']);
        }
        $total = MoneyRules::toMinor((string) $data['voucher_amount']);
        if ($total <= 0) {
            throw ValidationException::withMessages(['voucher_amount' => 'مبلغ السند غير صالح.']);
        }
        DB::table('aims_rental_receipt_vouchers')->insertOrIgnore([
            'reference' => $data['reference'], 'amount' => number_format($total / 100, 2, '.', ''), 'currency_code' => $currency,
            'posted_on' => $data['posted_on'], 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $voucher = DB::table('aims_rental_receipt_vouchers')->whereRaw('lower(btrim(reference)) = lower(btrim(?))', [$data['reference']])->lockForUpdate()->first();
        $used = DB::table('aims_rental_receipt_allocations')->whereNull('reversed_at')
            ->whereRaw('lower(btrim(accounting_reference)) = lower(btrim(?))', [$data['reference']])
            ->pluck('amount')->sum(fn ($amount): int => MoneyRules::toMinor((string) $amount));
        if (MoneyRules::toMinor((string) $voucher->amount) !== $total || $voucher->currency_code !== $currency || (string) $voucher->posted_on !== (string) $data['posted_on']) {
            if ($used > 0) {
                throw ValidationException::withMessages(['reference' => 'السند '.$voucher->reference.' مسجل من قبل بمبلغ '.$voucher->amount.' '.$voucher->currency_code.' وتاريخ '.$voucher->posted_on
                    .'؛ أدخل بيانات السند نفسها، أو تحقق من رقم السند. لتصحيح بيانات السند اعكس أولًا كل قبض مسجل عليه.']);
            }
            // No live allocation uses the voucher (all reversed): the corrected voucher replaces the stored one.
            $after = ['amount' => number_format($total / 100, 2, '.', ''), 'currency_code' => $currency, 'posted_on' => $data['posted_on']];
            DB::table('aims_rental_receipt_vouchers')->where('id', $voucher->id)->update($after + ['updated_at' => now()]);
            $this->audit->log((int) $actor->id, 'rental.receipt.voucher_corrected', 'aims_rental_receipt_vouchers', (int) $voucher->id,
                ['reference' => $voucher->reference, 'amount' => $voucher->amount, 'currency_code' => $voucher->currency_code, 'posted_on' => (string) $voucher->posted_on], $after);
            $voucher = (object) (['reference' => $voucher->reference] + $after);
        }
        $asked = collect($data['allocations'])->sum(fn (array $row): int => MoneyRules::toMinor((string) $row['amount']));
        if ($used + $asked > $total) {
            throw ValidationException::withMessages(['voucher_amount' => 'التوزيع يتجاوز مبلغ السند '.$voucher->reference.': المبلغ '.number_format($total / 100, 2)
                .'، والموزع منه سابقًا '.number_format($used / 100, 2).'، والمتاح '.number_format(max(0, $total - $used) / 100, 2).'.']);
        }
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
