<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceDocumentCycle;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceSod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts limited to the documentary cycle: the cycle overview, the work waiting for the user, the archive, and
 * recording the payment voucher issued in the external accounting software against a supplier invoice.
 */
class FinanceDocumentCycleController extends Controller
{
    /** Permissions of the documentary cycle: whoever holds one of them works in it. */
    private const CYCLE_PERMISSIONS = [
        'finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
        'finance.purchase.receive', 'finance.purchase.invoice', 'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue',
        'finance.stock.count', 'finance.stock.count.approve', 'finance.monitor',
    ];

    private const ARCHIVE_READERS = ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.invoice', 'finance.monitor'];

    public function __construct(private readonly FinanceGuard $guard, private readonly FinanceDocumentCycle $cycle) {}

    public function cycle(Request $request): JsonResponse
    {
        $this->authorizeCycle($request);

        return response()->json(['stages' => $this->cycle->stages(), 'pending' => $this->cycle->pendingFor($request->user())]);
    }

    public function archive(Request $request): JsonResponse
    {
        // The closed purchase files (supplier, amount, invoices) belong to procurement and the accountant; the
        // stores see their receipts and movements instead.
        abort_unless(collect(self::ARCHIVE_READERS)->contains(fn (string $permission): bool => $this->guard->allows($request->user(), $permission)), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->cycle->archive($data['q'] ?? null));
    }

    /**
     * The invoice is settled by a voucher issued in the external accounting software; its number is recorded here.
     */
    public function externalVoucher(Request $request, int $invoiceId): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.purchase.invoice');
        if (! $this->guard->documentaryMode()) {
            throw ValidationException::withMessages(['invoice' => 'تسجيل سند خارجي متاح في وضع الدورة المستندية فقط؛ استخدم سند الصرف الداخلي.']);
        }
        $data = $request->validate([
            'voucher_no' => ['required', 'string', 'max:80'],
            'voucher_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $sod = app(FinanceSod::class);
        $actor = (int) $request->user()->id;
        $orderId = (int) DB::table('finance_purchase_invoices')->where('id', $invoiceId)->value('order_id');
        $sod->assertDistinct($actor, $sod->orderActors($orderId), 'تسجيل سند الصرف', 'purchase_invoice', $invoiceId);

        return DB::transaction(function () use ($request, $invoiceId, $data, $sod, $actor): JsonResponse {
            $invoice = DB::table('finance_purchase_invoices')->lockForUpdate()->find($invoiceId);
            abort_unless($invoice, 404);
            $sod->assertDistinct($actor, $sod->orderActors((int) $invoice->order_id), 'تسجيل سند الصرف', 'purchase_invoice', $invoiceId, false);
            if ($invoice->state === 'paid') {
                throw ValidationException::withMessages(['invoice' => 'الفاتورة مسددة ومسجل سندها من قبل.']);
            }
            if ($data['voucher_on'] < (string) $invoice->invoice_on) {
                throw ValidationException::withMessages(['voucher_on' => 'تاريخ سند الصرف يسبق تاريخ الفاتورة.']);
            }
            DB::table('finance_purchase_invoices')->where('id', $invoiceId)->update([
                'state' => 'paid', 'external_voucher_no' => trim($data['voucher_no']), 'external_voucher_on' => $data['voucher_on'],
                'external_recorded_by' => $request->user()->id, 'external_recorded_at' => now(), 'updated_at' => now(),
            ]);
            $this->guard->audit('invoice', $invoiceId, (int) $request->user()->id, 'external_voucher', [
                'voucher_no' => trim($data['voucher_no']), 'voucher_on' => $data['voucher_on'], 'system' => $this->guard->externalSystemName(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    private function authorizeCycle(Request $request): void
    {
        $this->guard->assert($request->user(), 'finance.view');
        abort_unless(collect(self::CYCLE_PERMISSIONS)->contains(fn (string $permission): bool => $this->guard->allows($request->user(), $permission)), 403,
            'لا تملك صلاحية في الدورة المستندية لقسم الحسابات.');
    }
}
