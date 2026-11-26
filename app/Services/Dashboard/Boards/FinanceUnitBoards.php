<?php

namespace App\Services\Dashboard\Boards;

use App\Models\User;
use App\Services\Finance\FinanceGuard;
use Illuminate\Support\Facades\DB;

/**
 * Role boards of the Accounts units, each from its own records and shown only to holders of that unit's permissions:
 * head (consolidated monitoring), procurement, stores, invoices and vouchers, payroll. A purchasing officer never
 * gets salary or stock widgets, a storekeeper never gets payment or payroll figures, and so on.
 */
final class FinanceUnitBoards
{
    /** unit => permissions that open it */
    public const UNITS = [
        'head' => ['finance.monitor'],
        'procurement' => ['finance.procurement.manage', 'finance.procurement.review'],
        'stores' => ['finance.purchase.receive', 'finance.stock.receive', 'finance.stock.issue', 'finance.stock.count', 'finance.stock.count.approve', 'finance.warehouse.manage'],
        'invoices' => ['finance.purchase.invoice'],
        'payroll' => ['finance.payroll.record', 'finance.payroll.publish'],
    ];

    public function __construct(private readonly FinanceGuard $guard) {}

    /** Units this user works in; the administrator's technical access opens none. @return list<string> */
    public function units(User $user): array
    {
        if ($user->isSystemAdmin()) {
            return [];
        }

        return array_keys(array_filter(self::UNITS, fn (array $codes): bool => collect($codes)->contains(fn (string $c): bool => $this->guard->allows($user, $c))));
    }

    public function build(User $user, string $unit): array
    {
        return match ($unit) {
            'head' => $this->head(),
            'procurement' => $this->procurement($user),
            'stores' => $this->stores(),
            'invoices' => $this->invoices(),
            default => $this->payroll($user),
        };
    }

    private function link(string $view): string
    {
        return route('finance.index', ['view' => $view], false);
    }

    private function head(): array
    {
        $workload = $this->workload();
        $waiting = array_sum(array_column($workload, 'value'));
        $staleOrders = DB::table('finance_purchase_orders')->whereIn('state', ['issued', 'partial_received'])->where('created_at', '<', now()->subDays(14))->count();
        $requests = DB::table('finance_purchase_requests');
        $rows = DB::table('finance_purchase_requests')->whereIn('state', ['pending_finance_head', 'pending_director', 'pending_board_treasurer', 'approved'])
            ->whereNotExists(fn ($q) => $q->from('finance_purchase_orders as o')->whereColumn('o.purchase_request_id', 'finance_purchase_requests.id'))
            ->orderBy('created_at')->limit(8)->get(['id', 'number', 'state', 'created_at'])
            ->map(fn (object $r): array => ['cells' => ['reference' => $r->number, 'unit' => 'المشتريات', 'age' => BoardKit::age($r->created_at)],
                'status' => ['label' => self::PURCHASE_STATES[$r->state] ?? $r->state, 'tone' => 'warning'], 'url' => $this->link('purchases'), 'action' => 'فتح', 'overdue' => $r->created_at < now()->subDays(14)])->all();

        return [
            'key' => 'finance-head', 'title' => 'متابعة قسم الحسابات', 'decision' => 'أين يتعطل العمل بين وحدات القسم اليوم؟', 'scope' => 'كل وحدات الحسابات — اطلاع فقط',
            'hero' => BoardKit::metric('معاملات تنتظر إجراءً في الوحدات', $waiting, 'down', detail: collect($workload)->map(fn (array $w): string => $w['label'].' '.$w['value'])->implode(' · ') ?: 'لا شيء معلق', url: $this->link('overview')),
            'kpis' => [
                BoardKit::metric('أوامر شراء لم تُستلم منذ أكثر من 14 يومًا', $staleOrders, 'down', url: $this->link('orders')),
                BoardKit::metric('طلبات شراء جديدة', (clone $requests)->where('created_at', '>', now()->subDays(7))->count(), null, BoardKit::weekOverWeek(clone $requests, 'created_at'), url: $this->link('purchases')),
                BoardKit::metric('طلبات قسائم راتب بانتظار المسير', DB::table('finance_payslip_requests')->whereIn('state', ['awaiting_payroll', 'not_included'])->count(), 'down', url: route('finance.payroll-runs.index', [], false)),
            ],
            'trend' => BoardKit::trend('الطلب مقابل الإنجاز أسبوعيًا', 'طلبات الشراء المُنشأة مقابل أوامر الشراء المُغلقة في كل أسبوع.', [
                ['label' => 'طلبات شراء', 'values' => BoardKit::weekly(clone $requests, 'created_at')],
                ['label' => 'أوامر مغلقة', 'values' => BoardKit::weekly(DB::table('finance_purchase_orders')->where('state', 'closed'), 'updated_at')],
            ]),
            'bars' => BoardKit::bars('العمل المعلق حسب الوحدة', 'ما ينتظر إجراءً الآن في كل وحدة.', $workload),
            'table' => BoardKit::table('أقدم طلبات الشراء دون أمر شراء', [['key' => 'reference', 'label' => 'الطلب'], ['key' => 'unit', 'label' => 'الوحدة'], ['key' => 'age', 'label' => 'العمر']],
                $rows, 'لا توجد طلبات شراء معلقة.', $this->link('purchases')),
            'actions' => [['label' => 'الدورة المستندية', 'url' => $this->link('overview'), 'icon' => 'git-branch'], ['label' => 'موظفو القسم وصلاحياتهم', 'url' => route('finance.staff', [], false), 'icon' => 'key']],
        ];
    }

    /** @return list<array{label: string, value: int, url: string}> */
    private function workload(): array
    {
        return [
            ['label' => 'المشتريات', 'value' => DB::table('finance_purchase_requests')->whereIn('state', ['pending_finance_head', 'pending_director', 'pending_board_treasurer'])->count(), 'url' => $this->link('purchases')],
            ['label' => 'الاستلام', 'value' => DB::table('finance_purchase_orders')->whereIn('state', ['issued', 'partial_received'])->count(), 'url' => $this->link('orders')],
            ['label' => 'الفواتير والسندات', 'value' => DB::table('finance_purchase_invoices')->where('state', '!=', 'paid')->count(), 'url' => $this->link('invoices')],
            ['label' => 'الجرد', 'value' => DB::table('finance_inventory_counts')->where('state', 'pending')->count(), 'url' => $this->link('counts')],
            ['label' => 'الرواتب', 'value' => DB::table('finance_payroll_batches')->where('state', 'pending_approval')->count(), 'url' => route('finance.payroll-runs.index', [], false)],
        ];
    }

    private const PURCHASE_STATES = ['draft' => 'مسودة', 'pending_finance_head' => 'بانتظار رئيس القسم', 'pending_director' => 'بانتظار المدير التنفيذي',
        'pending_board_treasurer' => 'بانتظار أمين الصندوق', 'approved' => 'معتمد — بانتظار أمر الشراء', 'rejected' => 'مرفوض', 'closed' => 'مغلق'];

    private function procurement(User $user): array
    {
        $requests = DB::table('finance_purchase_requests');
        $needsOrder = DB::table('finance_purchase_requests')->where('state', 'approved')
            ->whereNotExists(fn ($q) => $q->from('finance_purchase_orders as o')->whereColumn('o.purchase_request_id', 'finance_purchase_requests.id'));
        $orders = DB::table('finance_purchase_orders');
        $rows = (clone $needsOrder)->orderBy('created_at')->limit(8)->get(['id', 'number', 'created_at', 'total_amount', 'currency'])
            ->map(fn (object $r): array => ['cells' => ['reference' => $r->number, 'amount' => number_format((float) $r->total_amount, 2).' '.$r->currency, 'age' => BoardKit::age($r->created_at)],
                'status' => ['label' => 'معتمد — يلزم عروض وأمر شراء', 'tone' => 'warning'], 'url' => $this->link('purchases'), 'action' => 'متابعة', 'overdue' => $r->created_at < now()->subDays(14)])->all();

        return [
            'key' => 'finance-procurement', 'title' => 'المشتريات', 'decision' => 'أي الطلبات المعتمدة تحتاج عروض أسعار وأمر شراء الآن؟', 'scope' => 'وحدة المشتريات',
            'hero' => BoardKit::metric('طلبات معتمدة دون أمر شراء', (clone $needsOrder)->count(), 'down', url: $this->link('purchases')),
            'kpis' => [
                BoardKit::metric('طلبات بانتظار الاعتماد', DB::table('finance_purchase_requests')->whereIn('state', ['pending_finance_head', 'pending_director', 'pending_board_treasurer'])->count(), null, url: $this->link('purchases')),
                BoardKit::metric('أوامر شراء صدرت', (clone $orders)->where('created_at', '>', now()->subDays(7))->count(), 'up', BoardKit::weekOverWeek(clone $orders, 'created_at'), url: $this->link('orders')),
                BoardKit::metric('مسودات أعددتها', DB::table('finance_purchase_requests')->where('state', 'draft')->where('created_by', $user->id)->count(), 'down', url: $this->link('purchases')),
            ],
            'trend' => BoardKit::trend('طلبات الشراء وأوامرها أسبوعيًا', 'طلبات الشراء المُنشأة وأوامر الشراء الصادرة في كل أسبوع.', [
                ['label' => 'طلبات', 'values' => BoardKit::weekly(clone $requests, 'created_at')],
                ['label' => 'أوامر صادرة', 'values' => BoardKit::weekly(clone $orders, 'created_at')],
            ]),
            'bars' => BoardKit::bars('طلبات الشراء حسب الحالة', 'كل طلبات الشراء المسجلة حسب حالتها الحالية.',
                DB::table('finance_purchase_requests')->select('state', DB::raw('count(*) as n'))->groupBy('state')->get()
                    ->map(fn (object $r): array => ['label' => self::PURCHASE_STATES[$r->state] ?? $r->state, 'value' => (int) $r->n, 'url' => $this->link('purchases')])->all()),
            'table' => BoardKit::table('طلبات تحتاج أمر شراء', [['key' => 'reference', 'label' => 'الطلب'], ['key' => 'amount', 'label' => 'القيمة المعتمدة'], ['key' => 'age', 'label' => 'العمر']],
                $rows, 'لا توجد طلبات معتمدة تنتظر أمر شراء.', $this->link('purchases')),
            'actions' => [['label' => 'طلبات الشراء', 'url' => $this->link('purchases'), 'icon' => 'shopping-cart']],
        ];
    }

    private function stores(): array
    {
        $toReceive = DB::table('finance_purchase_orders')->whereIn('state', ['issued', 'partial_received']);
        $receipts = DB::table('finance_purchase_receipts');
        $movements = DB::table('finance_stock_movements');
        $below = DB::table('finance_stock_items as i')->where('i.minimum_qty', '>', 0)
            ->whereRaw("COALESCE((SELECT SUM(CASE WHEN m.kind IN ('receipt','return_in','adjust_in','transfer_in') THEN m.quantity ELSE -m.quantity END) FROM finance_stock_movements m WHERE m.stock_item_id = i.id), 0) < i.minimum_qty")
            ->count();
        $rows = (clone $toReceive)->orderBy('created_at')->limit(8)->get(['number', 'state', 'created_at'])
            ->map(fn (object $o): array => ['cells' => ['reference' => $o->number, 'age' => BoardKit::age($o->created_at)],
                'status' => ['label' => $o->state === 'partial_received' ? 'استلام جزئي' : 'بانتظار الاستلام', 'tone' => 'warning'], 'url' => $this->link('orders'), 'action' => 'استلام', 'overdue' => $o->created_at < now()->subDays(14)])->all();

        return [
            'key' => 'finance-stores', 'title' => 'المخازن', 'decision' => 'أي أوامر الشراء تنتظر استلامًا فعليًا، وأي الأصناف تحت الحد الأدنى؟', 'scope' => 'وحدة المخازن',
            'hero' => BoardKit::metric('أوامر شراء بانتظار الاستلام', (clone $toReceive)->count(), 'down', url: $this->link('orders')),
            'kpis' => [
                BoardKit::metric('أصناف تحت الحد الأدنى', $below, 'down', url: $this->link('items')),
                BoardKit::metric('محاضر جرد بانتظار الاعتماد', DB::table('finance_inventory_counts')->where('state', 'pending')->count(), 'down', url: $this->link('counts')),
                BoardKit::metric('سندات استلام', (clone $receipts)->where('created_at', '>', now()->subDays(7))->count(), null, BoardKit::weekOverWeek(clone $receipts, 'created_at'), url: $this->link('purchase_receipts')),
            ],
            'trend' => BoardKit::trend('الوارد والمنصرف أسبوعيًا', 'عدد حركات الإدخال (استلام ومرتجع داخل) مقابل حركات الصرف في كل أسبوع.', [
                ['label' => 'إدخال', 'values' => BoardKit::weekly((clone $movements)->whereIn('kind', ['receipt', 'return_in']), 'created_at')],
                ['label' => 'صرف', 'values' => BoardKit::weekly((clone $movements)->whereIn('kind', ['issue', 'return_out']), 'created_at')],
            ]),
            'bars' => BoardKit::bars('حركات المخزون حسب النوع (30 يومًا)', 'عدد الحركات في آخر 30 يومًا.',
                DB::table('finance_stock_movements')->where('created_at', '>', now()->subDays(30))->select('kind', DB::raw('count(*) as n'))->groupBy('kind')->get()
                    ->map(fn (object $r): array => ['label' => self::MOVES[$r->kind] ?? $r->kind, 'value' => (int) $r->n, 'url' => $this->link('stock')])->all()),
            'table' => BoardKit::table('أوامر تنتظر الاستلام', [['key' => 'reference', 'label' => 'أمر الشراء'], ['key' => 'age', 'label' => 'منذ الإصدار']], $rows, 'لا توجد أوامر بانتظار الاستلام.', $this->link('orders')),
            'actions' => [['label' => 'الاستلام', 'url' => $this->link('purchase_receipts'), 'icon' => 'package-check']],
        ];
    }

    private const MOVES = ['receipt' => 'استلام', 'issue' => 'صرف', 'return_in' => 'مرتجع داخل', 'return_out' => 'مرتجع خارج', 'adjust_in' => 'تسوية زيادة', 'adjust_out' => 'تسوية نقص', 'transfer_in' => 'نقل داخل', 'transfer_out' => 'نقل خارج'];

    private function invoices(): array
    {
        $unpaid = DB::table('finance_purchase_invoices')->where('state', '!=', 'paid');
        $toInvoice = DB::table('finance_purchase_orders as o')->whereIn('o.state', ['partial_received', 'received'])
            ->whereNotExists(fn ($q) => $q->from('finance_purchase_invoices as v')->whereColumn('v.order_id', 'o.id'));
        $vouchers = DB::table('finance_purchase_invoices')->whereNotNull('external_recorded_at');
        $rows = (clone $unpaid)->orderBy('invoice_on')->limit(8)->get(['invoice_no', 'amount', 'currency', 'invoice_on'])
            ->map(fn (object $v): array => ['cells' => ['reference' => $v->invoice_no, 'amount' => number_format((float) $v->amount, 2).' '.$v->currency, 'age' => BoardKit::age($v->invoice_on)],
                'status' => ['label' => 'بانتظار رقم سند الصرف', 'tone' => 'warning'], 'url' => $this->link('invoices'), 'action' => 'تسجيل السند', 'overdue' => $v->invoice_on < now()->subDays(30)->toDateString()])->all();

        return [
            'key' => 'finance-invoices', 'title' => 'الفواتير وسندات الصرف', 'decision' => 'أي البضاعة المستلمة تحتاج فاتورة، وأي الفواتير تنتظر سند الصرف؟', 'scope' => 'المحاسب العام',
            'hero' => BoardKit::metric('فواتير بانتظار سند الصرف', (clone $unpaid)->count(), 'down', url: $this->link('invoices')),
            'kpis' => [
                BoardKit::metric('أوامر مستلمة دون فاتورة', (clone $toInvoice)->count(), 'down', url: $this->link('orders')),
                BoardKit::metric('سندات صرف سُجّلت', (clone $vouchers)->where('external_recorded_at', '>', now()->subDays(7))->count(), null, BoardKit::weekOverWeek(clone $vouchers, 'external_recorded_at'), url: $this->link('invoices')),
                BoardKit::unavailable('أرصدة الصناديق والبنوك', 'الدفاتر تُمسك في البرنامج المحاسبي الخارجي؛ لا مصدر لها في AIMS.'),
            ],
            'trend' => BoardKit::trend('الفواتير والسندات أسبوعيًا', 'فواتير الموردين المسجلة مقابل سندات الصرف المسجلة في كل أسبوع.', [
                ['label' => 'فواتير', 'values' => BoardKit::weekly(DB::table('finance_purchase_invoices'), 'created_at')],
                ['label' => 'سندات صرف', 'values' => BoardKit::weekly(clone $vouchers, 'external_recorded_at')],
            ]),
            'bars' => BoardKit::bars('الفواتير حسب الحالة', 'فواتير الموردين المسجلة.', [
                ['label' => 'بانتظار السند', 'value' => (clone $unpaid)->count(), 'url' => $this->link('invoices')],
                ['label' => 'مسددة', 'value' => DB::table('finance_purchase_invoices')->where('state', 'paid')->count(), 'url' => $this->link('invoices')],
            ]),
            'table' => BoardKit::table('فواتير تنتظر سند الصرف', [['key' => 'reference', 'label' => 'الفاتورة'], ['key' => 'amount', 'label' => 'القيمة'], ['key' => 'age', 'label' => 'منذ الفاتورة']],
                $rows, 'لا توجد فواتير معلقة.', $this->link('invoices')),
            'actions' => [['label' => 'فواتير الموردين', 'url' => $this->link('invoices'), 'icon' => 'receipt']],
        ];
    }

    private function payroll(User $user): array
    {
        $waiting = DB::table('finance_payslip_requests')->whereIn('state', ['awaiting_payroll', 'not_included']);
        $requests = DB::table('finance_payslip_requests');
        $last = DB::table('finance_payroll_batches')->where('state', 'published')->orderByDesc('period')->first(['period']);
        $url = route('finance.payroll-runs.index', [], false);
        $rows = (clone $waiting)->select('period', 'state', DB::raw('count(*) as n'), DB::raw('min(created_at) as since'))->groupBy('period', 'state')->orderByDesc('period')->limit(8)->get()
            ->map(fn (object $r): array => ['cells' => ['reference' => $r->period, 'count' => (string) $r->n, 'age' => BoardKit::age($r->since)],
                'status' => ['label' => $r->state === 'not_included' ? 'لا سطر في المسير المنشور' : 'بانتظار نشر المسير', 'tone' => $r->state === 'not_included' ? 'danger' : 'warning'],
                'url' => $url, 'action' => 'المسير', 'overdue' => false])->all();

        return [
            'key' => 'finance-payroll', 'title' => 'الرواتب والقسائم', 'decision' => 'أي مسير يحتاج إدخالًا أو مراجعة ونشرًا ليحصل الموظفون على قسائمهم؟', 'scope' => 'وحدة الرواتب',
            'hero' => BoardKit::metric('طلبات قسائم بانتظار المسير', (clone $waiting)->count(), 'down', detail: $last ? 'آخر مسير منشور: '.$last->period : 'لم يُنشر أي مسير بعد', url: $url),
            'kpis' => [
                BoardKit::metric('مسيرات بانتظار المراجعة والنشر', DB::table('finance_payroll_batches')->where('state', 'pending_approval')->count(), 'down', url: $url),
                BoardKit::metric('مسيرات مُعادة للتصحيح', DB::table('finance_payroll_batches')->where('state', 'returned')->where('created_by', $user->id)->count(), 'down', url: $url),
                BoardKit::metric('قسائم صدرت هذا الشهر', DB::table('finance_payslips')->where('published_at', '>=', now()->startOfMonth())->count(), null, url: $url),
            ],
            'trend' => BoardKit::trend('طلبات القسائم أسبوعيًا', 'طلبات «قسيمة راتب» المقدمة مقابل المُلبّاة في كل أسبوع.', [
                ['label' => 'مقدمة', 'values' => BoardKit::weekly(clone $requests, 'created_at')],
                ['label' => 'مُلبّاة', 'values' => BoardKit::weekly((clone $requests)->whereNotNull('fulfilled_at'), 'fulfilled_at')],
            ]),
            'bars' => BoardKit::bars('المسيرات حسب الحالة', 'مسيرات الرواتب المسجلة.', DB::table('finance_payroll_batches')->select('state', DB::raw('count(*) as n'))->groupBy('state')->get()
                ->map(fn (object $r): array => ['label' => ['pending_approval' => 'بانتظار المراجعة', 'published' => 'منشور', 'returned' => 'مُعاد'][$r->state] ?? $r->state, 'value' => (int) $r->n, 'url' => $url])->all()),
            'table' => BoardKit::table('طلبات قسائم معلقة حسب الشهر', [['key' => 'reference', 'label' => 'الشهر'], ['key' => 'count', 'label' => 'الطلبات'], ['key' => 'age', 'label' => 'أقدمها']],
                $rows, 'لا توجد طلبات قسائم معلقة.', $url),
            'actions' => [['label' => 'مسير الرواتب والقسائم', 'url' => $url, 'icon' => 'users']],
        ];
    }
}
