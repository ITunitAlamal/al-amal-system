<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceDocumentCycle;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceSod;
use App\Services\Orphans\SponsorshipCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts limited to the documentary cycle: the cycle overview, the work waiting for the user, the archive, and
 * recording the payment voucher issued in the external accounting software against a supplier invoice, and the
 * receipt recorded against a referred sponsorship due (ACC-09).
 */
class FinanceDocumentCycleController extends Controller
{
    /** Permissions of the documentary cycle: whoever holds one of them works in it. */
    private const CYCLE_PERMISSIONS = [
        'finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
        'finance.purchase.receive', 'finance.purchase.invoice', 'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue',
        'finance.stock.count', 'finance.stock.count.approve', 'finance.payment.record', 'finance.monitor', SponsorshipCollections::PERMISSION,
    ];

    private const ARCHIVE_READERS = ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.invoice', 'finance.payment.record', 'finance.monitor'];

    public function __construct(private readonly FinanceGuard $guard, private readonly FinanceDocumentCycle $cycle) {}

    public function cycle(Request $request): JsonResponse
    {
        $this->authorizeCycle($request);

        return response()->json(['stages' => $this->cycle->stages($request->user()), 'pending' => $this->cycle->pendingFor($request->user())]);
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
     * The invoice is settled by a voucher issued in the external accounting software; its number, date and amount are
     * recorded here. One voucher closes one invoice; its actual amount is stored so a voucher below the invoice (a
     * discount or a withholding in the external software) shows in the invoices report. An amount above the invoice is
     * refused, and so is a voucher number already recorded on another invoice (also by the trigger of 000027).
     * Settling one invoice by several vouchers awaits the owner's decision.
     */
    public function externalVoucher(Request $request, int $invoiceId): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.payment.record');
        if (! $this->guard->documentaryMode()) {
            throw ValidationException::withMessages(['invoice' => 'تسجيل سند خارجي متاح في وضع الدورة المستندية فقط؛ استخدم سند الصرف الداخلي.']);
        }
        $data = $request->validate([
            'voucher_no' => ['required', 'string', 'max:80'],
            'voucher_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);
        $data['voucher_no'] = trim($data['voucher_no']);

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
            $amount = FinanceMoney::cents($data['amount']);
            if ($amount <= 0 || $amount > FinanceMoney::cents((string) $invoice->amount)) {
                throw ValidationException::withMessages(['amount' => 'قيمة سند الصرف ('.FinanceMoney::format($amount).') يجب أن تكون أكبر من صفر ولا تتجاوز قيمة الفاتورة ('
                    .$invoice->amount.' '.$invoice->currency.').']);
            }
            $used = DB::table('finance_purchase_invoices')->where('id', '<>', $invoiceId)
                ->whereRaw('lower(btrim(external_voucher_no)) = lower(btrim(?))', [$data['voucher_no']])->value('invoice_no');
            if ($used !== null) {
                throw ValidationException::withMessages(['voucher_no' => 'رقم سند الصرف «'.$data['voucher_no'].'» مسجل من قبل على الفاتورة '.$used.'؛ لا يُسجَّل السند نفسه مرتين.']);
            }
            DB::table('finance_purchase_invoices')->where('id', $invoiceId)->update([
                'state' => 'paid', 'external_voucher_no' => $data['voucher_no'], 'external_voucher_on' => $data['voucher_on'],
                'external_voucher_amount' => FinanceMoney::format($amount),
                'external_recorded_by' => $request->user()->id, 'external_recorded_at' => now(), 'updated_at' => now(),
            ]);
            $this->guard->audit('invoice', $invoiceId, (int) $request->user()->id, 'external_voucher', [
                'voucher_no' => $data['voucher_no'], 'voucher_on' => $data['voucher_on'], 'amount' => FinanceMoney::format($amount),
                'invoice_amount' => (string) $invoice->amount, 'system' => $this->guard->externalSystemName(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    /**
     * «قبض الكفالات» (ACC-09): sponsorship dues referred by the Orphans department, and the receipts recorded on them
     * from the external accounting software. Holders of finance.sponsorships.record only (never the technical role).
     */
    public function sponsorships(Request $request, SponsorshipCollections $collections): Response
    {
        $this->guard->assert($request->user(), SponsorshipCollections::PERMISSION);
        $filters = $request->validate(['filter' => ['nullable', Rule::in(['open', 'overdue', 'collected', 'all'])], 'search' => 'nullable|string|max:100']);
        $filter = $filters['filter'] ?? 'open';
        $search = trim((string) ($filters['search'] ?? ''));
        $dues = DB::query()->fromSub($collections->dues(), 'b')
            ->when($filter === 'open', fn ($q) => $q->where('b.remaining', '>', 0))
            ->when($filter === 'overdue', fn ($q) => $q->where('b.remaining', '>', 0)->where('b.due_on', '<', today()->toDateString()))
            ->when($filter === 'collected', fn ($q) => $q->where('b.remaining', '<=', 0))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('b.sponsorship_reference', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('b.sponsor', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orWhere('b.receivable_reference', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('b.due_on')->limit(500)->get();

        return Inertia::render('Finance/Sponsorships', [
            'dues' => $dues,
            'receipts' => DB::table('sponsorship_receipt_allocations as a')->join('sponsorship_dues as d', 'd.id', '=', 'a.due_id')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')
                ->leftJoin('users as u', 'u.id', '=', 'a.recorded_by')->orderByDesc('a.id')->limit(200)
                ->get(['a.id', 'a.due_id', 'd.due_on', 's.reference_no as sponsorship_reference', 'a.accounting_reference', 'a.amount', 'a.currency', 'a.posted_on',
                    'a.reversed_at', 'a.reversal_reason', 'a.reversal_reference', 'u.name as recorded_by']),
            'documentary' => $this->guard->documentaryMode(),
            'externalSystem' => $this->guard->externalSystemName(),
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    public function sponsorshipReceipt(Request $request, SponsorshipCollections $collections): RedirectResponse
    {
        $this->guard->assert($request->user(), SponsorshipCollections::PERMISSION);
        $data = $request->validate([
            'request_key' => 'required|uuid', 'due_id' => 'required|integer|exists:sponsorship_dues,id', 'reference' => 'required|string|max:150',
            'posted_on' => 'required|date|before_or_equal:today', 'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'note' => 'nullable|string|max:2000',
        ], [], ['reference' => 'رقم سند القبض', 'posted_on' => 'تاريخ السند', 'amount' => 'المبلغ']);
        $recorded = $collections->record($request->user(), $data);

        return back()->with('success', $recorded ? 'سُجل سند القبض '.trim($data['reference']).' على استحقاق الكفالة.' : 'سبق تسجيل هذه العملية؛ لم يُكرَّر القبض.');
    }

    public function reverseSponsorshipReceipt(Request $request, int $id, SponsorshipCollections $collections): RedirectResponse
    {
        $this->guard->assert($request->user(), SponsorshipCollections::PERMISSION);
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000', 'reversal_reference' => 'nullable|string|max:150']);
        $collections->reverse($request->user(), $id, $data['reason'], $data['reversal_reference'] ?? null);

        return back()->with('success', 'عُكس القبض وعاد المبلغ إلى رصيد الاستحقاق؛ بقي السجل الأصلي للتدقيق.');
    }

    private function authorizeCycle(Request $request): void
    {
        $this->guard->assert($request->user(), 'finance.view');
        abort_unless(collect(self::CYCLE_PERMISSIONS)->contains(fn (string $permission): bool => $this->guard->allows($request->user(), $permission)), 403,
            'لا تملك صلاحية في الدورة المستندية لقسم الحسابات.');
    }
}
