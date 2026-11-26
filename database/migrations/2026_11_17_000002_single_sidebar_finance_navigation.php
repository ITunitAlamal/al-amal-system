<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One sidebar for the whole application. The Finance page drew a second, page-level vertical menu whose sections
 * existed nowhere else; they now live in the global sidebar as the «الحسابات» group, each a deep link
 * (/finance?view=…) shown by the same Finance permissions the server enforces (access rule finance:…). Section
 * headings are link-less children. The rentals file becomes a group with its two pages. Additive: one nullable
 * column (route_params) and new rows; existing items keep their ids and codes.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_17_000002 (قائمة جانبية واحدة: أقسام الحسابات والأصول في القائمة الرئيسية)';

    /** Named rules (NavigationAccessRules) for permission lists too long for access_rule. */
    private const CYCLE = 'finance_cycle';

    private const PROCUREMENT = 'finance.procurement.manage|finance.procurement.review';

    /** @return list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: string, 5: string}> code, label, route, view, rule, icon */
    private function financeChildren(): array
    {
        $p = self::PROCUREMENT;

        return [
            ['finance_h_follow', 'متابعتي', null, null, 'authenticated', 'list'],
            ['finance_pending', 'بانتظار إجرائي', 'finance.index', 'pending', self::CYCLE, 'inbox'],
            ['finance_overview', 'الدورة المستندية', 'finance.index', 'overview', self::CYCLE, 'git-branch'],
            ['finance_approvals', 'صندوق الاعتمادات المالية', 'finance.approvals', null, 'finance_approver', 'key'],
            ['finance_h_purchasing', 'المشتريات', null, null, 'authenticated', 'list'],
            ['finance_purchases', 'طلبات الشراء', 'finance.index', 'purchases', 'finance_purchasing', 'shopping-cart'],
            ['finance_quotes', 'عروض الأسعار', 'finance.index', 'quotes', "finance:{$p}", 'clipboard-list'],
            ['finance_orders', 'أوامر الشراء', 'finance.index', 'orders', "finance:{$p}|finance.purchase.receive|finance.purchase.invoice", 'clipboard-list'],
            ['finance_invoices', 'فواتير الموردين', 'finance.index', 'invoices', 'finance:finance.purchase.invoice|finance.procurement.review', 'list'],
            ['finance_suppliers', 'الموردون', 'finance.index', 'suppliers', "finance:{$p}", 'handshake'],
            ['finance_h_stores', 'المخازن', null, null, 'authenticated', 'list'],
            ['finance_receipts', 'الاستلام', 'finance.index', 'purchase_receipts', "finance:{$p}|finance.purchase.receive|finance.purchase.invoice", 'inbox'],
            ['finance_stock', 'الصرف وحركات المخزون', 'finance.index', 'stock', 'finance:finance.stock.receive|finance.stock.issue|finance.warehouse.manage', 'list'],
            ['finance_items', 'الأصناف', 'finance.index', 'items', 'finance:finance.warehouse.manage', 'list'],
            ['finance_warehouses', 'تعريف المخازن', 'finance.index', 'warehouses', 'finance:finance.warehouse.manage', 'building'],
            ['finance_counts', 'الجرد', 'finance.index', 'counts', 'finance:finance.stock.count|finance.stock.count.approve', 'clipboard-list'],
            ['finance_h_rentals', 'الإيجارات', null, null, 'authenticated', 'list'],
            ['finance_rentals', 'قبض الإيجارات وتسوياتها', 'finance.rentals', null, 'finance:finance.rentals.record', 'coins'],
            ['finance_h_records', 'السجل والتقارير', null, null, 'authenticated', 'list'],
            ['finance_archive', 'السجل', 'finance.index', 'archive', self::CYCLE, 'save'],
            ['finance_reports', 'التقارير والتصدير', 'finance.index', 'reports', 'finance:finance.report.export', 'chart'],
            // Bookkeeping: refused to everyone while Accounts run the documentary cycle, so these stay hidden until
            // the accounting mode returns to «full».
            ['finance_h_accounting', 'المحاسبة', null, null, 'authenticated', 'list'],
            ['finance_treasuries', 'الصناديق والبنوك', 'finance.index', 'treasuries', 'finance:finance.treasury.manage', 'coins'],
            ['finance_budgets', 'الموازنات', 'finance.index', 'budgets', 'finance:finance.budget.manage', 'chart'],
            ['finance_transactions', 'سندات القبض والصرف', 'finance.index', 'transactions', 'finance:finance.transaction.draft', 'list'],
            ['finance_receivables', 'المستحقات', 'finance.index', 'receivables', 'finance:finance.receivable.manage', 'list'],
            ['finance_transfers', 'التحويلات', 'finance.index', 'transfers', 'finance:finance.transfer.execute', 'list'],
            ['finance_advances', 'العهد والسلف', 'finance.index', 'advances', 'finance:finance.advance.manage', 'list'],
            ['finance_payroll', 'الرواتب', 'finance.index', 'payroll', 'finance:finance.payroll.manage', 'users'],
            ['finance_payroll_items', 'بنود الرواتب', 'finance.index', 'payroll_items', 'finance:finance.payroll.manage', 'users'],
            ['finance_funds', 'التمويل المخصص', 'finance.index', 'funds', 'finance:finance.fund.manage', 'coins'],
            ['finance_fund_movements', 'حركات الأموال المقيدة', 'finance.index', 'fund_movements', 'finance:finance.fund.manage', 'list'],
            ['finance_reconciliations', 'المطابقة', 'finance.index', 'reconciliations', 'finance:finance.reconcile', 'list'],
            ['finance_cheques', 'الشيكات', 'finance.index', 'cheques', 'finance:finance.cheque.manage', 'list'],
            ['finance_budget_exceptions', 'استثناءات الموازنة', 'finance.index', 'budget_exceptions', 'finance:finance.budget.transfer', 'list'],
            ['finance_budget_transfers', 'مناقلات الموازنة', 'finance.index', 'budget_transfers', 'finance:finance.budget.transfer', 'list'],
        ];
    }

    public function up(): void
    {
        Schema::table('navigation_items', function (Blueprint $table): void {
            $table->jsonb('route_params')->nullable();
        });

        $finance = DB::table('navigation_items')->where('code', 'finance')->first();
        $rentals = DB::table('navigation_items')->where('code', 'rentals')->first();
        if (! $finance || ! $rentals) {
            throw new RuntimeException('عنصرا القائمة «finance» و«rentals» مطلوبان.');
        }
        $now = now();
        DB::table('navigation_items')->where('id', $finance->id)->update(['label_ar' => 'الحسابات', 'route_name' => null, 'access_rule' => 'authenticated', 'updated_at' => $now]);
        foreach ($this->financeChildren() as $order => [$code, $label, $route, $view, $rule, $icon]) {
            DB::table('navigation_items')->insert([
                'code' => $code, 'parent_id' => $finance->id, 'label_ar' => $label, 'icon' => $icon, 'route_name' => $route,
                'route_params' => $view ? json_encode(['view' => $view]) : null, 'access_rule' => $rule, 'sort_order' => ($order + 1) * 10,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('navigation_items')->where('id', $rentals->id)->update(['label_ar' => 'الأصول والمباني والإيجارات', 'route_name' => null, 'access_rule' => 'authenticated', 'updated_at' => $now]);
        foreach ([
            ['rentals_file', 'ملف الأصول والإيجارات', 'admin.rentals.index', 'permission:rentals.view', 'building'],
            ['rentals_buildings', 'إدارة المباني والأعيان', 'admin.master-data.buildings.index', 'permission:rentals.manage', 'settings'],
        ] as $order => [$code, $label, $route, $rule, $icon]) {
            DB::table('navigation_items')->insert(['code' => $code, 'parent_id' => $rentals->id, 'label_ar' => $label, 'icon' => $icon, 'route_name' => $route,
                'access_rule' => $rule, 'sort_order' => ($order + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'navigation.single_sidebar', 'subject_type' => 'navigation_items', 'subject_id' => (string) $finance->id,
            'metadata' => json_encode(['before' => ['finance' => (array) $finance, 'rentals' => (array) $rentals],
                'after' => ['finance_children' => count($this->financeChildren()), 'rentals_children' => 2], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $codes = [...array_column($this->financeChildren(), 0), 'rentals_file', 'rentals_buildings'];
        DB::table('navigation_items')->whereIn('code', $codes)->delete();
        DB::table('navigation_items')->where('code', 'finance')->update(['label_ar' => 'الحسابات والمشتريات والمخازن', 'route_name' => 'finance.index', 'access_rule' => 'finance_view']);
        DB::table('navigation_items')->where('code', 'rentals')->update(['label_ar' => 'العقارات والإيجارات', 'route_name' => 'admin.rentals.index', 'access_rule' => 'permission:rentals.view']);
        Schema::table('navigation_items', fn (Blueprint $table) => $table->dropColumn('route_params'));
    }
};
