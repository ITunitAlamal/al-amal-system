<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Orphans\Aid;
use App\Services\Orphans\SponsorshipCollections;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The documentary cycle of Accounts (owner decision 2026-10-23): purchase request → reviews and approvals →
 * quotes → purchase order → receipt into stores → supplier invoice → payment voucher number from the external
 * accounting software → order closed. Read-side only: stage counts, the work waiting for one user (by the
 * finance permissions they hold), and the archive of closed documents.
 */
final class FinanceDocumentCycle
{
    public function __construct(private readonly FinanceGuard $guard) {}

    /**
     * Every stage of the cycle with its count, in order. Counts only, no amounts or parties.
     *
     * @return list<array{key: string, label: string, owner: string, count: int, module: string, area: string}>
     */
    public function stages(User $user): array
    {
        $requests = DB::table('finance_purchase_requests')->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state');
        // Functional area of each stage, for the department overview: المشتريات / المخازن / المحاسب العام.
        $areas = ['draft' => 'procurement', 'pending_finance_head' => 'procurement', 'pending_director' => 'procurement', 'pending_board_treasurer' => 'procurement',
            'awaiting_order' => 'procurement', 'ready_to_close' => 'procurement', 'awaiting_receipt' => 'stores', 'counts_pending' => 'stores',
            'awaiting_invoice' => 'accountant', 'awaiting_voucher' => 'payments', 'closed' => 'archive', 'awaiting_sponsor_receipt' => 'receipts'];
        $stage = fn (string $key, string $label, string $owner, int $count, string $module): array => compact('key', 'label', 'owner', 'count', 'module') + ['area' => $areas[$key]];

        $stages = [
            $stage('draft', 'طلبات شراء قيد الإعداد', 'المشتريات', (int) ($requests['draft'] ?? 0), 'purchases'),
            $stage('pending_finance_head', 'بانتظار مراجعة رئيس الحسابات', 'رئيس الحسابات', (int) ($requests['pending_finance_head'] ?? 0), 'purchases'),
            $stage('pending_director', 'بانتظار اعتماد المدير التنفيذي', 'المدير التنفيذي', (int) ($requests['pending_director'] ?? 0), 'purchases'),
            $stage('pending_board_treasurer', 'بانتظار أمين الصندوق', 'أمين صندوق المجلس', (int) ($requests['pending_board_treasurer'] ?? 0), 'purchases'),
            $stage('awaiting_order', 'معتمدة بانتظار عروض الأسعار وأمر الشراء', 'المشتريات', $this->approvedWithoutOrder()->count(), 'quotes'),
            $stage('awaiting_receipt', 'أوامر شراء بانتظار الاستلام', 'أمين المخزن', $this->ordersInState(['issued', 'partial_received'])->count(), 'orders'),
            $stage('awaiting_invoice', 'مستلمة بانتظار فاتورة المورد', 'المحاسب', $this->receivedWithoutInvoice()->count(), 'orders'),
            $stage('awaiting_voucher', 'فواتير بانتظار رقم سند الصرف من '.$this->guard->externalSystemName(), 'منفّذ الصرف', $this->unpaidInvoices()->count(), 'payment_vouchers'),
            $stage('ready_to_close', 'جاهزة للإغلاق', 'المشتريات', $this->readyToClose()->count(), 'orders'),
            $stage('closed', 'دورات مستندية مغلقة', '—', DB::table('finance_purchase_orders')->where('state', 'closed')->count(), 'archive'),
            $stage('counts_pending', 'محاضر جرد بانتظار الاعتماد', 'رئيس الحسابات', DB::table('finance_inventory_counts')->where('state', 'pending')->count(), 'counts'),
            $stage('awaiting_sponsor_receipt', 'استحقاقات كفالات محالة بانتظار تسجيل القبض من '.$this->guard->externalSystemName(), 'منفّذ القبض', $this->uncollectedDues()->count(), 'sponsorship_receipts'),
        ];
        $permissions = [
            'procurement' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve'],
            'stores' => ['finance.purchase.receive', 'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue', 'finance.stock.count', 'finance.stock.count.approve'],
            'accountant' => ['finance.purchase.invoice'],
            'payments' => ['finance.payment.record'],
            'archive' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.invoice', 'finance.payment.record'],
            'receipts' => [SponsorshipCollections::PERMISSION],
        ];
        $visible = array_keys(array_filter($permissions, fn (array $codes): bool => collect([...$codes, 'finance.monitor'])->contains(fn (string $code): bool => $this->guard->allows($user, $code))));

        return array_values(array_filter($stages, fn (array $stage): bool => in_array($stage['area'], $visible, true)));
    }

    /**
     * Documents waiting for this user now, by the finance permissions they hold. Never their own requests for a
     * review or approval (separation of duties, as in the actions themselves).
     *
     * @return list<array{kind: string, action: string, reference: string, detail: string, module: string, id: int, since: ?string}>
     */
    public function pendingFor(User $user): array
    {
        $allows = fn (string $permission): bool => $this->guard->allows($user, $permission);
        $items = [];
        $add = function (string $kind, string $action, string $module, iterable $rows, callable $reference, callable $detail) use (&$items): void {
            foreach ($rows as $row) {
                $items[] = ['kind' => $kind, 'action' => $action, 'reference' => (string) $reference($row), 'detail' => (string) $detail($row), 'module' => $module, 'id' => (int) $row->id, 'since' => $row->updated_at ?? $row->created_at ?? null];
            }
        };
        $purchaseDetail = fn (object $row): string => trim(($row->total_amount ?? '').' '.($row->currency ?? ''));

        if ($allows('finance.procurement.manage')) {
            $add('submit', 'إرسال طلب الشراء للمراجعة', 'purchases', DB::table('finance_purchase_requests')->where('state', 'draft')->where('created_by', $user->id)->orderBy('id')->limit(100)->get(),
                fn ($row) => $row->number, $purchaseDetail);
            $add('order', 'تسجيل عروض الأسعار وإصدار أمر الشراء', 'purchases', $this->approvedWithoutOrder()->orderBy('p.id')->limit(100)->get(['p.*']),
                fn ($row) => $row->number, $purchaseDetail);
            $add('close', 'إغلاق أمر الشراء بعد الاستلام والسداد', 'orders', $this->readyToClose()->orderBy('o.id')->limit(100)->get(['o.*']),
                fn ($row) => $row->number, fn ($row) => (string) $row->amount);
        }
        if ($allows('finance.procurement.review')) {
            $add('review', 'مراجعة رئيس الحسابات', 'purchases', DB::table('finance_purchase_requests')->where('state', 'pending_finance_head')->where('created_by', '<>', $user->id)->orderBy('id')->limit(100)->get(),
                fn ($row) => $row->number, $purchaseDetail);
        }
        if ($allows('finance.procurement.director_approve')) {
            $add('director', 'اعتماد المدير التنفيذي', 'purchases', DB::table('finance_purchase_requests')->where('state', 'pending_director')->where('created_by', '<>', $user->id)
                ->where(fn ($query) => $query->whereNull('reviewed_by')->orWhere('reviewed_by', '<>', $user->id))->orderBy('id')->limit(100)->get(),
                fn ($row) => $row->number, $purchaseDetail);
        }
        if ($allows('finance.procurement.board_approve') && DB::table('finance_board_approvers')->where('user_id', $user->id)->where('is_active', true)->exists()) {
            $add('board', 'موافقة أمين الصندوق', 'purchases', DB::table('finance_purchase_requests')->where('state', 'pending_board_treasurer')->where('created_by', '<>', $user->id)->orderBy('id')->limit(100)->get(),
                fn ($row) => $row->number, $purchaseDetail);
        }
        // The same per-transaction rule as the actions (FinanceSod): an order is not offered to whoever the step refuses.
        $sod = app(FinanceSod::class);
        $uid = (int) $user->id;
        $actors = [];
        $refused = function (int $orderId) use ($sod, $uid, &$actors): bool {
            $actors[$orderId] ??= $sod->orderActors($orderId);

            return isset($actors[$orderId][$uid]);
        };
        if ($allows('finance.purchase.receive')) {
            $add('receive', 'استلام أصناف أمر الشراء', 'orders', $this->ordersInState(['issued', 'partial_received'])->where('o.created_by', '<>', $user->id)->orderBy('o.id')->limit(100)->get(['o.*'])
                ->reject(fn ($row) => isset($sod->orderOrigin((int) $row->id)[$uid])),
                fn ($row) => $row->number, fn ($row) => $row->state === 'partial_received' ? 'استلام جزئي سابق' : 'لم يُستلم شيء بعد');
        }
        if ($allows('finance.purchase.invoice')) {
            $add('invoice', 'تسجيل فاتورة المورد', 'orders', $this->receivedWithoutInvoice()->orderBy('o.id')->limit(100)->get(['o.*'])->reject(fn ($row) => $refused((int) $row->id)),
                fn ($row) => $row->number, fn ($row) => (string) $row->amount);
        }
        if ($this->guard->documentaryMode() && $allows('finance.payment.record')) {
            $add('voucher', 'تسجيل رقم سند الصرف من '.$this->guard->externalSystemName(), 'payment_vouchers', $this->unpaidInvoices()->orderBy('id')->limit(100)->get()
                ->reject(fn ($row) => $refused((int) $row->order_id)),
                fn ($row) => $row->invoice_no, fn ($row) => trim($row->amount.' '.$row->currency));
        }
        // ACC-09: referred sponsorship dues not fully collected, past or at their date, for whoever records sponsor receipts.
        if ($this->guard->documentaryMode() && $allows(SponsorshipCollections::PERMISSION)) {
            $add('sponsor_receipt', 'تسجيل قبض استحقاق كفالة من '.$this->guard->externalSystemName(), 'sponsorship_receipts',
                $this->uncollectedDues()->where('d.due_on', '<=', today()->toDateString())->where(fn ($q) => $q->whereNull('d.referred_by')->orWhere('d.referred_by', '<>', $user->id))
                    ->orderBy('d.due_on')->limit(100)->get(['d.id', 'd.amount', 'd.currency', 'd.due_on', 'd.referred_at as created_at', 's.reference_no']),
                fn ($row) => $row->reference_no.' · '.$row->due_on, fn ($row) => trim($row->amount.' '.$row->currency));
        }
        if ($allows('finance.stock.count.approve')) {
            $add('count', 'اعتماد محضر الجرد', 'counts', DB::table('finance_inventory_counts')->where('state', 'pending')->where('counted_by', '<>', $user->id)->orderBy('id')->limit(100)->get(),
                fn ($row) => 'جرد #'.$row->id, fn ($row) => 'المسجل '.$row->recorded_qty.' · الفعلي '.$row->physical_qty);
        }

        return $items;
    }

    /**
     * Closed purchase cycles, newest first, searchable by request, order, invoice or voucher number.
     */
    public function archive(?string $search, int $perPage = 30): LengthAwarePaginator
    {
        return DB::table('finance_purchase_orders as o')
            ->join('finance_purchase_requests as p', 'p.id', '=', 'o.purchase_request_id')
            ->leftJoin('finance_suppliers as s', 's.id', '=', 'o.supplier_id')
            ->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->where('o.state', 'closed')
            ->when($search !== null && trim($search) !== '', function ($query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(fn ($inner) => $inner->where('o.number', 'ilike', $term)->orWhere('p.number', 'ilike', $term)
                    ->orWhereExists(fn ($invoice) => $invoice->from('finance_purchase_invoices as i')->whereColumn('i.order_id', 'o.id')
                        ->where(fn ($match) => $match->where('i.invoice_no', 'ilike', $term)->orWhere('i.external_voucher_no', 'ilike', $term))));
            })
            ->orderByDesc('o.updated_at')
            ->select(['o.id', 'p.number as request_number', 'o.number as order_number', 'd.name as department', 's.name as supplier', 'o.amount', 'p.currency', 'o.updated_at as closed_at'])
            ->selectSub(DB::table('finance_purchase_invoices as i')->whereColumn('i.order_id', 'o.id')->selectRaw("string_agg(i.invoice_no || COALESCE(' ← ' || i.external_voucher_no, ''), '، ')"), 'invoices')
            ->paginate($perPage);
    }

    private function approvedWithoutOrder()
    {
        return DB::table('finance_purchase_requests as p')->where('p.state', 'approved')
            ->whereNotExists(fn ($query) => $query->from('finance_purchase_orders as o')->whereColumn('o.purchase_request_id', 'p.id'));
    }

    /**
     * @param  list<string>  $states
     */
    private function ordersInState(array $states)
    {
        return DB::table('finance_purchase_orders as o')->whereIn('o.state', $states);
    }

    private function receivedWithoutInvoice()
    {
        return $this->ordersInState(['partial_received', 'received'])
            ->whereNotExists(fn ($query) => $query->from('finance_purchase_invoices as i')->whereColumn('i.order_id', 'o.id'));
    }

    private function unpaidInvoices()
    {
        return DB::table('finance_purchase_invoices')->where('state', '<>', 'paid');
    }

    /** Sponsorship dues referred to Accounts whose recorded collection is still below the amount. */
    private function uncollectedDues()
    {
        return DB::table('sponsorship_dues as d')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')->where('d.status', 'referred')
            ->whereRaw(Aid::collectedSql('d').' < d.amount');
    }

    private function readyToClose()
    {
        return $this->ordersInState(['received'])
            ->whereExists(fn ($query) => $query->from('finance_purchase_invoices as i')->whereColumn('i.order_id', 'o.id'))
            ->whereNotExists(fn ($query) => $query->from('finance_purchase_invoices as i')->whereColumn('i.order_id', 'o.id')->where('i.state', '<>', 'paid'));
    }
}
