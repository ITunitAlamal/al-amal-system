<?php

namespace App\Services\Finance;

use App\Services\AuditService;
use App\Services\Rentals\RentalLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integration seam for future AIMS modules. Invoked from approved domain workflows only.
 * Never creates a fake module, independent payment, or externally reachable API endpoint.
 */
final class FinanceSourceBridge
{
    private const MODULES = ['rentals', 'sponsorships', 'housing', 'maintenance', 'human_resources', 'grants'];

    public function registerApprovedReceivable(
        string $module,
        string $reference,
        string $kind,
        string $party,
        string $currency,
        string $amount,
        string $dueOn,
        int $authorizedUserId,
        ?string $description = null,
    ): int {
        if (! in_array($module, self::MODULES, true) || trim($reference) === '' || strlen($reference) > 140) {
            throw ValidationException::withMessages(['source' => 'مرجع القسم المصدر غير مسموح']);
        }
        if (! in_array($kind, ['rent', 'service', 'donation', 'sponsorship', 'grant', 'other'], true)) {
            throw ValidationException::withMessages(['kind' => 'تصنيف المستحق غير معروف']);
        }
        if (FinanceMoney::cents($amount) <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون موجبًا']);
        }
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw ValidationException::withMessages(['currency' => 'عملة غير صالحة']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueOn)) {
            throw ValidationException::withMessages(['due_on' => 'تاريخ استحقاق غير صالح']);
        }

        return DB::transaction(function () use ($module, $reference, $kind, $party, $currency, $amount, $dueOn, $authorizedUserId, $description) {
            $old = DB::table('finance_receivables')
                ->where('source_module', $module)->where('source_reference', $reference)->lockForUpdate()->first();
            if ($old) {
                if ($old->kind !== $kind || $old->currency !== $currency ||
                    FinanceMoney::cents((string) $old->amount) !== FinanceMoney::cents($amount) ||
                    $old->due_on !== $dueOn) {
                    throw ValidationException::withMessages(['source' => 'تغير المستحق الأصلي؛ يلزم مسار تعديل معتمد ولا يجوز إعادة كتابته']);
                }

                return (int) $old->id;
            }

            return (int) DB::table('finance_receivables')->insertGetId([
                'reference' => strtoupper(substr($module, 0, 3)).'-'.strtoupper(bin2hex(random_bytes(10))),
                'source_module' => $module, 'source_reference' => $reference,
                'kind' => $kind, 'party' => $party, 'currency' => $currency,
                'amount' => $amount, 'due_on' => $dueOn, 'description' => $description,
                'created_by' => $authorizedUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /**
     * The source module cancelled the obligation (e.g. a rent due after a termination or replaced by an amendment).
     * Recorded once, with the reason; the database never reopens it. A receivable with collections allocated to it
     * is not cancelled: the allocation is reversed first. False when nothing was registered or it is already cancelled.
     */
    public function cancelReceivable(string $module, string $reference, string $reason, ?int $actorId): bool
    {
        $row = DB::table('finance_receivables')->where('source_module', $module)->where('source_reference', $reference)->lockForUpdate()->first();
        if (! $row || $row->cancelled_at !== null) {
            return false;
        }
        if ($this->allocatedCents((int) $row->id) > 0) {
            throw ValidationException::withMessages(['source' => 'على المستحق '.$row->reference.' قبض مخصص في الحسابات؛ يُعكس التخصيص أولًا ثم يُلغى المستحق.']);
        }
        DB::table('finance_receivables')->where('id', $row->id)->update(['cancelled_at' => now(), 'cancelled_by' => $actorId, 'cancel_reason' => $reason, 'updated_at' => now()]);
        app(AuditService::class)->recordRow('finance.receivable.cancelled', 'finance_receivables', (int) $row->id, ['amount' => $row->amount],
            ['source' => $module.':'.$reference, 'reason' => $reason], $actorId);

        return true;
    }

    /**
     * An approved change of the obligation posted by the source module (e.g. a rent settlement): an append-only row,
     * once per source reference. $delta is signed («-200.00» reduces). False when nothing was registered, the
     * receivable is cancelled, or this source reference was already applied.
     */
    public function adjustReceivable(string $module, string $reference, string $delta, string $sourceReference, string $reason, ?int $actorId): bool
    {
        $cents = FinanceMoney::cents($delta);
        if ($cents === 0 || trim($sourceReference) === '' || strlen($sourceReference) > 150) {
            throw ValidationException::withMessages(['source' => 'تعديل المستحق يحتاج مبلغًا غير صفري ومرجعًا من القسم المصدر.']);
        }
        $row = DB::table('finance_receivables')->where('source_module', $module)->where('source_reference', $reference)->lockForUpdate()->first();
        if (! $row || $row->cancelled_at !== null) {
            return false;
        }
        $inserted = DB::table('finance_receivable_adjustments')->insertOrIgnore([
            'receivable_id' => $row->id, 'delta' => FinanceMoney::format($cents), 'source_reference' => $sourceReference, 'reason' => $reason,
            'created_by' => $actorId, 'created_at' => now(),
        ]);
        if ($inserted > 0) {
            app(AuditService::class)->recordRow('finance.receivable.adjusted', 'finance_receivables', (int) $row->id, ['amount' => $row->amount],
                ['delta' => FinanceMoney::format($cents), 'source' => $module.':'.$reference, 'by' => $sourceReference, 'reason' => $reason], $actorId);
        }

        return $inserted > 0;
    }

    /**
     * What is still open on a receivable, in cents: nothing once cancelled; for a rent due, the rentals ledger's own
     * remainder (it also counts the external receipts and posted settlements of /finance/rentals); otherwise the
     * amount and its adjustments less the collections allocated to it.
     */
    public function openCents(object $receivable): int
    {
        if (($receivable->cancelled_at ?? null) !== null) {
            return 0;
        }
        if (($receivable->source_module ?? null) === 'rentals') {
            return app(RentalLedger::class)->remainingMinor((int) $receivable->source_reference);
        }
        $adjusted = 0;
        foreach (DB::table('finance_receivable_adjustments')->where('receivable_id', $receivable->id)->pluck('delta') as $delta) {
            $adjusted += FinanceMoney::cents((string) $delta);
        }

        return max(0, FinanceMoney::cents((string) $receivable->amount) + $adjusted - $this->allocatedCents((int) $receivable->id));
    }

    private function allocatedCents(int $receivableId): int
    {
        $cents = 0;
        foreach (DB::table('finance_payment_allocations')->where('receivable_module', 'finance')->where('receivable_id', (string) $receivableId)
            ->whereNull('reversed_by_transaction_id')->pluck('amount') as $amount) {
            $cents += FinanceMoney::cents((string) $amount);
        }

        return $cents;
    }
}
