<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Segregation of duties on the same transaction. Holding two permissions is not enough to perform two incompatible
 * steps of one record: each step checks who performed the earlier steps of that record. A refusal is audited and is
 * never waived by the system administrator's technical role.
 *
 * Steps of the purchase-to-pay cycle (documentary mode) and who may not repeat them:
 *   request (created_by) → head review (reviewed_by) → director / board approval → supplier choice and order (orders.created_by)
 *   → goods receipt (receipts.received_by) → invoice (invoices.created_by) → external payment voucher (external_recorded_by).
 *   - order: not the requester or any approver (FinanceProcurementController)
 *   - receipt: not the order's issuer, the request's creator or the employee who asked for it from «طلباتي»
 *     (FinanceCompletionController::purchaseReceipt, with orderOrigin())
 *   - invoice: not the requester, the order's issuer or anyone who received its goods (orderActors())
 *   - external voucher: not the requester, the order's issuer or a receiver (orderActors())
 * Payroll: the person who recorded a run never reviews or publishes it. Stock count: the counter never approves it.
 */
final class FinanceSod
{
    public const MATRIX = [
        ['الطلب', 'إنشاء طلب الشراء', 'المراجعة والاعتماد واختيار المورد وإصدار الأمر والاستلام والفاتورة والسند'],
        ['أمر الشراء', 'اختيار المورد وإصدار الأمر', 'إنشاء الطلب أو اعتماده، والاستلام، والفاتورة، والسند'],
        ['الاستلام', 'استلام الأصناف فعليًا', 'إنشاء الطلب، وإصدار الأمر، وتسجيل الفاتورة والسند'],
        ['الفاتورة', 'تسجيل فاتورة المورد ومطابقتها', 'إنشاء الطلب، وإصدار الأمر، واستلام الأصناف'],
        ['سند الصرف', 'تسجيل سند الصرف الخارجي', 'إنشاء الطلب، وإصدار الأمر، واستلام الأصناف'],
        ['الجرد', 'إعداد محضر الجرد', 'اعتماد المحضر نفسه'],
        ['مسير الرواتب', 'إدخال المسير المعتمد', 'مراجعته ونشر قسائمه'],
        ['سندات القبض والصرف', 'إعداد السند', 'مراجعته أو اعتماده أو تنفيذه'],
    ];

    public function __construct(private readonly FinanceGuard $guard) {}

    /** Users who performed the procurement steps behind a purchase order. @return array<int, string> user id => step */
    public function orderActors(int $orderId): array
    {
        $actors = [];
        foreach (DB::table('finance_purchase_receipts')->where('order_id', $orderId)->pluck('received_by') as $receiver) {
            $actors[(int) $receiver] = 'استلام أصناف الأمر';
        }

        return $this->orderOrigin($orderId) + $actors;
    }

    /**
     * Users behind the order itself: its issuer, the request's creator and the employee who asked for the purchase
     * from «طلباتي» (for such a request the creator is the procurement officer, not the requester). None of them
     * receives the goods; one storekeeper may still receive several partial deliveries of the same order.
     *
     * @return array<int, string> user id => step
     */
    public function orderOrigin(int $orderId): array
    {
        $order = DB::table('finance_purchase_orders')->find($orderId);
        if (! $order) {
            return [];
        }
        $purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
        $actors = [(int) $order->created_by => 'إصدار أمر الشراء'];
        if ($purchase) {
            $actors[(int) $purchase->created_by] = 'إنشاء طلب الشراء';
            $requester = FinanceOperations::requesterOf($purchase);
            if ($requester !== null) {
                $actors[$requester] = 'تقديم طلب الشراء';
            }
        }

        return $actors;
    }

    /**
     * Refuses the step when the actor performed one of the incompatible earlier steps of the same record.
     *
     * Called once before the operation's transaction (the refusal is audited and survives) and once inside it on the
     * locked rows without auditing (a refusal there is rolled back with the operation).
     *
     * @param  array<int, string>  $earlier  user id => earlier step
     */
    public function assertDistinct(int $actor, array $earlier, string $step, string $entity, int $entityId, bool $audit = true): void
    {
        if (! isset($earlier[$actor])) {
            return;
        }
        if ($audit) {
            $this->guard->audit('sod', $entity.':'.$entityId, $actor, 'denied', ['step' => $step, 'earlier_step' => $earlier[$actor]]);
        }
        throw ValidationException::withMessages(['actor' => "فصل المهام: لا يجوز لمن تولّى «{$earlier[$actor]}» أن يتولى «{$step}» في المعاملة نفسها."]);
    }
}
