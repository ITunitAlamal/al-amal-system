<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reports of «قسم الحسابات» by functional area. The screen preview and every export format (Excel, PDF, CSV, print)
 * read the same definition, the same filters and the same permission scope, so a file never holds more than the
 * screen shows. Payroll is a summary of runs only: no employee earnings, deductions or bank details.
 */
final class FinanceReports
{
    public const MAX_ROWS = 20000;

    public const AREAS = ['procurement' => 'المشتريات', 'stores' => 'المخازن', 'accountant' => 'المحاسب العام', 'payments' => 'الصرف', 'receipts' => 'القبض', 'payroll' => 'مسير الرواتب'];

    /** Arabic labels of the state codes the finance services write (same vocabulary as the screens). */
    public const STATES = [
        'draft' => 'مسودة', 'pending' => 'بانتظار الاعتماد', 'pending_review' => 'بانتظار المراجعة', 'pending_approval' => 'بانتظار الاعتماد',
        'pending_director' => 'بانتظار المدير التنفيذي', 'pending_finance_head' => 'بانتظار رئيس الحسابات', 'pending_board_treasurer' => 'بانتظار أمين الصندوق',
        'approved' => 'معتمد', 'executed' => 'منفّذ', 'closed' => 'مغلق', 'issued' => 'صادر', 'partial_received' => 'استلام جزئي', 'received' => 'مستلم',
        'paid' => 'مدفوع', 'reversed' => 'معكوس', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى', 'ordered' => 'صدر أمر الشراء',
    ];

    private const MOVEMENT_KINDS = ['receipt' => 'استلام', 'issue' => 'صرف', 'return_in' => 'مرتجع داخل', 'return_out' => 'مرتجع خارج',
        'adjust_in' => 'تسوية جرد بالزيادة', 'adjust_out' => 'تسوية جرد بالنقص'];

    public function __construct(private readonly FinanceGuard $guard) {}

    /**
     * key => title, area, permissions (any one), date column, search columns, states offered as a filter,
     * columns [alias => [Arabic label, type]] with type text|money|qty|date|datetime|state|kind, money total flag.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'purchases' => ['title' => 'تقرير طلبات الشراء', 'area' => 'procurement', 'any' => ['finance.procurement.manage', 'finance.procurement.review'],
                'date' => 'pr.created_at', 'search' => ['pr.number', 'pr.purpose', 'd.name'],
                'states' => ['draft', 'pending_finance_head', 'pending_director', 'pending_board_treasurer', 'approved', 'rejected', 'closed'],
                'columns' => ['number' => ['رقم الطلب', 'text'], 'department' => ['القسم الطالب', 'text'], 'purpose' => ['سبب الطلب', 'text'],
                    'currency' => ['العملة', 'text'], 'amount' => ['الإجمالي', 'money'], 'state' => ['الحالة', 'state'], 'created_at' => ['تاريخ الإنشاء', 'date']]],
            'orders' => ['title' => 'تقرير أوامر الشراء', 'area' => 'procurement', 'any' => ['finance.procurement.manage', 'finance.procurement.review'],
                'date' => 'po.created_at', 'search' => ['po.number', 'pr.number', 's.name'],
                'states' => ['issued', 'partial_received', 'received', 'closed'],
                'columns' => ['number' => ['رقم أمر الشراء', 'text'], 'request_number' => ['طلب الشراء', 'text'], 'supplier' => ['المورد', 'text'],
                    'currency' => ['العملة', 'text'], 'amount' => ['القيمة', 'money'], 'state' => ['الحالة', 'state'], 'created_at' => ['تاريخ الإصدار', 'date']]],
            'stock' => ['title' => 'تقرير حركات المخزون', 'area' => 'stores', 'any' => ['finance.stock.receive', 'finance.stock.issue', 'finance.warehouse.manage'],
                'date' => 'm.created_at', 'search' => ['i.sku', 'i.name', 'w.name', 'm.reference_id'],
                'states' => [],
                'columns' => ['created_at' => ['التاريخ', 'datetime'], 'warehouse' => ['المخزن', 'text'], 'sku' => ['رمز الصنف', 'text'], 'item' => ['الصنف', 'text'],
                    'kind' => ['نوع الحركة', 'kind'], 'quantity' => ['الكمية', 'qty'], 'unit' => ['الوحدة', 'text'], 'unit_cost' => ['تكلفة الوحدة', 'qty'], 'reference' => ['المرجع', 'text']]],
            'stock_balances' => ['title' => 'تقرير أرصدة المخزون', 'area' => 'stores', 'any' => ['finance.stock.receive', 'finance.stock.issue', 'finance.warehouse.manage', 'finance.stock.count'],
                'date' => 'm.created_at', 'search' => ['i.sku', 'i.name', 'w.name'], 'states' => [],
                'columns' => ['warehouse' => ['المخزن', 'text'], 'sku' => ['رمز الصنف', 'text'], 'item' => ['الصنف', 'text'], 'unit' => ['الوحدة', 'text'],
                    'balance' => ['الرصيد', 'qty'], 'minimum_qty' => ['الحد الأدنى', 'qty'], 'below_minimum' => ['تحت الحد الأدنى', 'text']]],
            'counts' => ['title' => 'تقرير محاضر الجرد', 'area' => 'stores', 'any' => ['finance.stock.count', 'finance.stock.count.approve'],
                'date' => 'c.created_at', 'search' => ['i.sku', 'i.name', 'w.name', 'c.reason'], 'states' => ['pending', 'approved', 'rejected'],
                'columns' => ['created_at' => ['التاريخ', 'date'], 'warehouse' => ['المخزن', 'text'], 'sku' => ['رمز الصنف', 'text'], 'item' => ['الصنف', 'text'],
                    'recorded_qty' => ['الكمية المسجلة', 'qty'], 'physical_qty' => ['الكمية الفعلية', 'qty'], 'difference' => ['الفرق', 'qty'], 'state' => ['الحالة', 'state'], 'reason' => ['السبب', 'text']]],
            'invoices' => ['title' => 'تقرير فواتير الموردين وسندات الصرف', 'area' => 'accountant', 'any' => ['finance.purchase.invoice', 'finance.procurement.review', 'finance.payment.record'],
                'date' => 'inv.invoice_on', 'search' => ['inv.invoice_no', 'inv.external_voucher_no', 'po.number', 's.name'], 'states' => ['pending', 'paid'],
                'columns' => ['invoice_no' => ['رقم الفاتورة', 'text'], 'invoice_on' => ['تاريخ الفاتورة', 'date'], 'order_number' => ['أمر الشراء', 'text'], 'supplier' => ['المورد', 'text'],
                    'currency' => ['العملة', 'text'], 'amount' => ['القيمة', 'money'], 'state' => ['الحالة', 'state'],
                    'voucher_no' => ['رقم سند الصرف', 'text'], 'voucher_on' => ['تاريخ سند الصرف', 'date'], 'voucher_amount' => ['قيمة سند الصرف', 'money']]],
            'receipts' => $this->vouchers('receipt', 'تقرير سندات القبض'),
            'payments' => $this->vouchers('payment', 'تقرير سندات الصرف'),
            'payroll' => ['title' => 'ملخص مسيرات الرواتب', 'area' => 'payroll', 'any' => ['finance.payroll.manage', 'finance.payroll.approve', 'finance.payroll.record', 'finance.payroll.publish', 'finance.payroll.authorize'],
                'date' => 'b.created_at', 'search' => ['b.period', 'b.hr_approval_reference'], 'states' => ['draft', 'pending_approval', 'approved', 'published', 'returned', 'paid'],
                'columns' => ['period' => ['الفترة', 'text'], 'currency' => ['العملة', 'text'], 'amount' => ['إجمالي المسير', 'money'],
                    'hr_reference' => ['مرجع اعتماد الموارد البشرية', 'text'], 'state' => ['الحالة', 'state'], 'created_at' => ['تاريخ الإعداد', 'date']]],
        ];
    }

    /** @return array<string, mixed> */
    private function vouchers(string $kind, string $title): array
    {
        return ['title' => $title, 'area' => $kind === 'payment' ? 'payments' : 'receipts', 'kind' => $kind,
            'any' => array_map(fn (string $action): string => FinanceGuard::transactionPermission($kind, $action), ['draft', 'review', 'approve', 'execute']),
            'date' => 't.effective_on', 'search' => ['t.document_no', 't.counterparty'],
            'states' => ['draft', 'pending_review', 'pending_director', 'approved', 'executed', 'reversed'],
            'columns' => ['document_no' => ['رقم السند', 'text'], 'effective_on' => ['التاريخ', 'date'], 'counterparty' => [$kind === 'receipt' ? 'الدافع' : 'المستفيد', 'text'],
                'currency' => ['العملة', 'text'], 'amount' => ['المبلغ', 'money'], 'state' => ['الحالة', 'state']]];
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        $definitions = $this->definitions();
        abort_unless(isset($definitions[$key]), 404);

        return $definitions[$key];
    }

    public function canView(?User $user, string $key): bool
    {
        $definition = $this->definition($key);

        return $this->guard->allows($user, 'finance.reports.view')
            // The head's consolidated monitoring reads every unit's report (totals only for payroll).
            && collect([...$definition['any'], 'finance.monitor'])->contains(fn (string $permission): bool => $this->guard->allows($user, $permission));
    }

    public function canExport(?User $user, string $key): bool
    {
        return $this->canView($user, $key) && $this->guard->allows($user, 'finance.report.export');
    }

    /** @return list<array{key: string, title: string, area: string, area_name: string, export: bool, states: list<array{0: string, 1: string}>}> */
    public function available(?User $user): array
    {
        $list = [];
        foreach ($this->definitions() as $key => $definition) {
            if ($this->canView($user, $key)) {
                $list[] = ['key' => $key, 'title' => $definition['title'], 'area' => $definition['area'], 'area_name' => self::AREAS[$definition['area']],
                    'export' => $this->guard->allows($user, 'finance.report.export'),
                    'states' => array_map(fn (string $state): array => [$state, $key === 'invoices' && $state === 'pending' ? 'بانتظار سند الصرف' : (self::STATES[$state] ?? $state)], $definition['states'])];
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{from: ?string, to: ?string, state: ?string, q: ?string}
     */
    public function filters(string $key, array $input): array
    {
        $definition = $this->definition($key);
        $validator = validator($input, [
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'state' => ['nullable', 'string', 'in:'.implode(',', $definition['states'] ?: ['-'])], 'q' => 'nullable|string|max:100',
        ], [], ['from' => 'من تاريخ', 'to' => 'إلى تاريخ', 'state' => 'الحالة', 'q' => 'البحث']);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $data = $validator->validated();

        return ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'state' => $data['state'] ?? null, 'q' => isset($data['q']) && trim($data['q']) !== '' ? trim($data['q']) : null];
    }

    /** @param array{from: ?string, to: ?string, state: ?string, q: ?string} $filters */
    public function query(string $key, array $filters): Builder
    {
        $definition = $this->definition($key);
        $query = match ($key) {
            'purchases' => DB::table('finance_purchase_requests as pr')->leftJoin('departments as d', 'd.id', '=', 'pr.department_id')
                ->select(['pr.id', 'pr.number', 'd.name as department', 'pr.purpose', 'pr.currency', 'pr.total_amount as amount', 'pr.state', 'pr.created_at'])->orderByDesc('pr.id'),
            'orders' => DB::table('finance_purchase_orders as po')->join('finance_purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
                ->leftJoin('finance_suppliers as s', 's.id', '=', 'po.supplier_id')
                ->select(['po.id', 'po.number', 'pr.number as request_number', 's.name as supplier', 'pr.currency', 'po.amount', 'po.state', 'po.created_at'])->orderByDesc('po.id'),
            'stock' => DB::table('finance_stock_movements as m')->join('finance_warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('finance_stock_items as i', 'i.id', '=', 'm.stock_item_id')
                ->select(['m.id', 'm.created_at', 'w.name as warehouse', 'i.sku', 'i.name as item', 'm.kind', 'm.quantity', 'i.unit', 'm.unit_cost',
                    DB::raw("concat_ws(' ', m.reference_type, m.reference_id) as reference")])->orderByDesc('m.id'),
            'stock_balances' => DB::table('finance_stock_movements as m')->join('finance_warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('finance_stock_items as i', 'i.id', '=', 'm.stock_item_id')
                ->groupBy('w.id', 'w.name', 'i.id', 'i.sku', 'i.name', 'i.unit', 'i.minimum_qty')
                ->select(['w.name as warehouse', 'i.sku', 'i.name as item', 'i.unit', 'i.minimum_qty',
                    DB::raw("SUM(CASE WHEN m.kind IN ('receipt','return_in','adjust_in') THEN m.quantity ELSE -m.quantity END) as balance")])
                ->orderBy('w.name')->orderBy('i.name'),
            'counts' => DB::table('finance_inventory_counts as c')->join('finance_warehouses as w', 'w.id', '=', 'c.warehouse_id')->join('finance_stock_items as i', 'i.id', '=', 'c.stock_item_id')
                ->select(['c.id', 'c.created_at', 'w.name as warehouse', 'i.sku', 'i.name as item', 'c.recorded_qty', 'c.physical_qty',
                    DB::raw('c.physical_qty - c.recorded_qty as difference'), 'c.state', 'c.reason'])->orderByDesc('c.id'),
            'invoices' => DB::table('finance_purchase_invoices as inv')->join('finance_purchase_orders as po', 'po.id', '=', 'inv.order_id')
                ->leftJoin('finance_suppliers as s', 's.id', '=', 'po.supplier_id')
                ->select(['inv.id', 'inv.invoice_no', 'inv.invoice_on', 'po.number as order_number', 's.name as supplier', 'inv.currency', 'inv.amount', 'inv.state',
                    'inv.external_voucher_no as voucher_no', 'inv.external_voucher_on as voucher_on', 'inv.external_voucher_amount as voucher_amount'])->orderByDesc('inv.id'),
            'receipts', 'payments' => DB::table('finance_transactions as t')->where('t.kind', $definition['kind'])
                ->select(['t.id', 't.document_no', 't.effective_on', 't.counterparty', 't.currency', 't.amount', 't.state'])->orderByDesc('t.id'),
            'payroll' => DB::table('finance_payroll_batches as b')
                ->select(['b.id', 'b.period', 'b.currency', 'b.total as amount', 'b.hr_approval_reference as hr_reference', 'b.state', 'b.created_at'])->orderByDesc('b.id'),
        };
        // A balance is "as of" the end date; the start date does not apply to it.
        if ($filters['from'] && $key !== 'stock_balances') {
            $query->whereDate($definition['date'], '>=', $filters['from']);
        }
        if ($filters['to']) {
            $query->whereDate($definition['date'], '<=', $filters['to']);
        }
        if ($filters['state'] && isset($definition['columns']['state'])) {
            $query->where(explode('.', $definition['date'])[0].'.state', $filters['state']);
        }
        if ($filters['q']) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(function (Builder $where) use ($definition, $term): void {
                foreach ($definition['search'] as $column) {
                    $where->orWhere($column, 'ilike', $term);
                }
            });
        }

        return $query;
    }

    /**
     * Totals of the money column per currency, over the whole filtered set (not only the shown page).
     *
     * @param  array{from: ?string, to: ?string, state: ?string, q: ?string}  $filters
     * @return array{count: int, money: list<array{currency: string, amount: string}>}
     */
    public function totals(string $key, array $filters): array
    {
        $definition = $this->definition($key);
        $base = $this->query($key, $filters);
        $count = (int) DB::query()->fromSub($base, 'r')->count();
        $money = [];
        if (isset($definition['columns']['amount'], $definition['columns']['currency'])) {
            $money = DB::query()->fromSub($this->query($key, $filters), 'r')->groupBy('currency')->orderBy('currency')
                ->get([DB::raw('currency'), DB::raw('SUM(amount) as amount')])
                ->map(fn (object $row): array => ['currency' => (string) $row->currency, 'amount' => self::decimal((string) $row->amount, 2)])->all();
        }

        return ['count' => $count, 'money' => $money];
    }

    /** Formats a database decimal string with thousands separators, without binary floating point. */
    public static function decimal(string $value, int $places, bool $trim = false): string
    {
        $value = trim($value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            return $value;
        }
        $fraction = substr(str_pad($m[3] ?? '', $places, '0'), 0, $places);
        if ($trim) {
            $fraction = rtrim($fraction, '0');
        }
        $whole = strrev(implode(',', str_split(strrev(ltrim($m[2], '0') ?: '0'), 3)));

        return $m[1].$whole.($fraction !== '' ? '.'.$fraction : '');
    }

    /**
     * Rows as display strings, keyed by the definition's columns. With $raw, money and quantity cells stay plain
     * decimal strings (spreadsheet numbers and CSV).
     *
     * @return list<array<string, string>>
     */
    public function present(string $key, Collection $rows, bool $raw = false): array
    {
        $columns = $this->definition($key)['columns'];

        return $rows->map(function (object $row) use ($columns, $key, $raw): array {
            $out = [];
            foreach ($columns as $alias => [, $type]) {
                if ($key === 'stock_balances' && $alias === 'below_minimum') {
                    $out[$alias] = (float) $row->balance < (float) $row->minimum_qty ? 'نعم' : '—';

                    continue;
                }
                $value = $row->$alias ?? null;
                $out[$alias] = match (true) {
                    $value === null || $value === '' => $raw ? '' : '—',
                    $raw && in_array($type, ['money', 'qty'], true) => (string) $value,
                    $type === 'money' => self::decimal((string) $value, 2),
                    $type === 'qty' => self::decimal((string) $value, 4, true),
                    $type === 'date' => substr((string) $value, 0, 10),
                    $type === 'datetime' => substr((string) $value, 0, 16),
                    $type === 'state' && $key === 'invoices' && $value === 'pending' => 'بانتظار سند الصرف',
                    $type === 'state' => self::STATES[$value] ?? (string) $value,
                    $type === 'kind' => self::MOVEMENT_KINDS[$value] ?? (string) $value,
                    default => (string) $value,
                };
            }

            return $out;
        })->all();
    }

    /**
     * Human-readable list of the applied filters, printed on the exported document.
     *
     * @param  array{from: ?string, to: ?string, state: ?string, q: ?string}  $filters
     */
    public function describeFilters(array $filters): string
    {
        $parts = [];
        if ($filters['from'] || $filters['to']) {
            $parts[] = 'الفترة: '.($filters['from'] ?? 'البداية').' — '.($filters['to'] ?? 'اليوم');
        }
        if ($filters['state']) {
            $parts[] = 'الحالة: '.(self::STATES[$filters['state']] ?? $filters['state']);
        }
        if ($filters['q']) {
            $parts[] = 'البحث: '.$filters['q'];
        }

        return $parts === [] ? 'بدون تصفية' : implode(' · ', $parts);
    }
}
