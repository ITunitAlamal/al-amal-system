<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceOperations;
use App\Services\Progress\PurchaseRequestProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Procurement quote comparison and PO issuance; no payment is executed here. */
final class FinanceProcurementController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard) {}

    public function show(Request $request, int $id)
    {
        // The head's monitoring reads the request like the other lists; it never acts on it.
        abort_unless(collect(['finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve', 'finance.monitor'])
            ->contains(fn (string $permission): bool => $this->guard->allows($request->user(), $permission)), 403);
        $purchase = DB::table('finance_purchase_requests')->find($id);
        abort_unless($purchase, 404);

        return response()->json([
            'purchase' => $purchase,
            'items' => DB::table('finance_purchase_items')->where('purchase_request_id', $id)->get(),
            'quotes' => DB::table('finance_purchase_quotes')->where('purchase_request_id', $id)->get(),
            'orders' => DB::table('finance_purchase_orders')->where('purchase_request_id', $id)->get(),
            'progress' => app(PurchaseRequestProgress::class)->view($purchase),
        ]);
    }

    public function quote(Request $request, int $id)
    {
        $this->guard->assert($request->user(), 'finance.procurement.manage');
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:finance_suppliers,id',
            'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/D',
            'currency' => 'required|exists:currencies,code',
            'notes' => 'nullable|string|max:4000',
        ]);
        if (FinanceMoney::cents((string) $data['amount']) <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة العرض يجب أن تكون موجبة']);
        }
        $result = DB::transaction(function () use ($request, $id, $data) {
            $purchase = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            abort_unless($purchase, 404);
            if (DB::table('finance_purchase_orders')->where('purchase_request_id', $id)->exists()) {
                throw ValidationException::withMessages(['purchase' => 'لا يمكن إدراج عروض بعد إصدار أمر شراء']);
            }
            if (! in_array($purchase->state, ['draft', 'pending_finance_head', 'pending_director', 'pending_board_treasurer', 'approved'], true)) {
                throw ValidationException::withMessages(['purchase' => 'الطلب لا يقبل عروض أسعار حاليًا']);
            }
            if ($purchase->currency !== $data['currency']) {
                throw ValidationException::withMessages(['currency' => 'عملة العرض يجب أن تطابق عملة طلب الشراء، لحين اعتماد مسار تقييم العملات']);
            }
            $supplier = DB::table('finance_suppliers')->where('id', $data['supplier_id'])->first();
            if (! $supplier || ! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'المورد غير فعّال']);
            }
            $quoteId = DB::table('finance_purchase_quotes')->insertGetId([
                ...$data, 'purchase_request_id' => $id, 'selected' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->guard->audit('purchase_quote', $quoteId, (int) $request->user()->id, 'create', ['purchase_request_id' => $id]);

            return $quoteId;
        });

        return response()->json(['id' => $result], 201);
    }

    public function selectQuote(Request $request, int $id, int $quoteId)
    {
        $this->guard->assert($request->user(), 'finance.procurement.manage');
        DB::transaction(function () use ($request, $id, $quoteId) {
            $purchase = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            abort_unless($purchase, 404);
            if ($purchase->state !== 'approved') {
                throw ValidationException::withMessages(['purchase' => 'اختيار المورد لا يتم إلا بعد اعتماد طلب الشراء']);
            }
            // A request from «طلباتي»: the requesting employee stands for the creator (owner decision 2026-11-02).
            if ((FinanceOperations::requesterOf($purchase) ?? (int) $purchase->created_by) === (int) $request->user()->id ||
                (int) $purchase->reviewed_by === (int) $request->user()->id ||
                (int) $purchase->director_approved_by === (int) $request->user()->id ||
                (int) $purchase->board_approved_by === (int) $request->user()->id) {
                throw ValidationException::withMessages(['actor' => 'لا يمكن جمع إنشاء أو اعتماد الطلب واختيار المورد في هذه المعاملة']);
            }
            if (DB::table('finance_purchase_orders')->where('purchase_request_id', $id)->exists()) {
                throw ValidationException::withMessages(['purchase' => 'يوجد أمر شراء صادر بالفعل']);
            }
            $quote = DB::table('finance_purchase_quotes')->where('purchase_request_id', $id)->where('id', $quoteId)->first();
            abort_unless($quote, 404);
            DB::table('finance_purchase_quotes')->where('purchase_request_id', $id)->update(['selected' => false, 'updated_at' => now()]);
            DB::table('finance_purchase_quotes')->where('id', $quoteId)->update(['selected' => true, 'updated_at' => now()]);
            $this->guard->audit('purchase_quote', $quoteId, (int) $request->user()->id, 'select_supplier', ['purchase_request_id' => $id]);
        });

        return response()->json(['ok' => true]);
    }

    public function issueOrder(Request $request, int $id)
    {
        $this->guard->assert($request->user(), 'finance.procurement.manage');
        $newId = DB::transaction(function () use ($request, $id) {
            $purchase = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            abort_unless($purchase, 404);
            if ($purchase->state !== 'approved') {
                throw ValidationException::withMessages(['purchase' => 'يجب إتمام كل الموافقات قبل إصدار أمر الشراء']);
            }
            if (DB::table('finance_purchase_orders')->where('purchase_request_id', $id)->exists()) {
                throw ValidationException::withMessages(['purchase' => 'لا يمكن إصدار أمر شراء مكرر']);
            }
            // A request from «طلباتي»: the requesting employee stands for the creator (owner decision 2026-11-02).
            $creator = FinanceOperations::requesterOf($purchase) ?? (int) $purchase->created_by;
            if (in_array((int) $request->user()->id, [$creator, (int) $purchase->reviewed_by,
                (int) $purchase->director_approved_by, (int) $purchase->board_approved_by], true)) {
                throw ValidationException::withMessages(['actor' => 'تعارض فصل المهام في إصدار أمر الشراء']);
            }
            $rule = DB::table('finance_purchase_rules')->where('code', 'default_quotes')->first();
            if (! $rule) {
                throw ValidationException::withMessages(['policy' => 'سياسة عروض الأسعار غير مهيأة']);
            }
            $quoteCount = DB::table('finance_purchase_quotes')->where('purchase_request_id', $id)->distinct('supplier_id')->count('supplier_id');
            if ($quoteCount < (int) $rule->min_quotes) {
                throw ValidationException::withMessages(['quotes' => 'عدد العروض أقل من الحد المعتمد']);
            }
            $quotes = DB::table('finance_purchase_quotes')->where('purchase_request_id', $id)->where('selected', true)->get();
            if ($quotes->count() !== 1) {
                throw ValidationException::withMessages(['quote' => 'يجب تحديد عرض واحد فقط للمورد']);
            }
            $quote = $quotes->first();
            if (FinanceMoney::cents((string) $quote->amount) > FinanceMoney::cents((string) $purchase->total_amount)) {
                throw ValidationException::withMessages(['quote' => 'قيمة العرض المختار تتجاوز الطلب المعتمد؛ يلزم إعادة اعتماد القيمة']);
            }
            if ($quote->currency !== $purchase->currency) {
                throw ValidationException::withMessages(['currency' => 'عملة العرض لا تطابق الطلب']);
            }
            $idNew = DB::table('finance_purchase_orders')->insertGetId([
                'number' => 'PO-'.strtoupper(bin2hex(random_bytes(8))),
                'purchase_request_id' => $id,
                'supplier_id' => $quote->supplier_id,
                'amount' => $quote->amount,
                'state' => 'issued',
                'created_by' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->guard->audit('purchase_order', $idNew, (int) $request->user()->id, 'issue', ['purchase_request_id' => $id, 'quote_id' => $quote->id]);

            return $idNew;
        });

        return response()->json(['id' => $newId], 201);
    }
}
