<?php

namespace App\Services\Finance;

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
}
