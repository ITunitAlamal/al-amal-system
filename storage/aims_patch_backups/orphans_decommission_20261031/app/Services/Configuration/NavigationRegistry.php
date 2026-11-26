<?php

namespace App\Services\Configuration;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The canonical list of sidebar entries. Each real page has one entry: its route, route parameters and visibility
 * rule are fixed here so they always mirror the page's own authorization; the administrator controls only how the
 * entry is shown (name, icon, group, order, hidden, extra role restriction) from «القائمة الرئيسية».
 *
 * Groups are named after the task, never after the department that owns it, and the menu has two levels only.
 */
final class NavigationRegistry
{
    /**
     * Functional groups: code => [label, icon, sort].
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const GROUPS = [
        'my_requests' => ['طلباتي', 'clipboard-list', 30],
        'leaves' => ['الدوام والإجازات', 'calendar', 40],
        'hr_group' => ['الموظفون', 'users', 50],
        // «قسم الحسابات» has three functional areas, each with its own group: المشتريات، المخازن، المحاسب العام.
        'accounts_home' => ['قسم الحسابات', 'chart', 58],
        'purchasing' => ['المشتريات', 'shopping-cart', 60],
        'stores' => ['المخازن', 'boxes', 64],
        'finance' => ['المحاسبة والتحصيل', 'coins', 70],
        'payroll_group' => ['الرواتب والأجور', 'wallet', 80],
        'budgets_group' => ['الموازنات والتمويل', 'chart', 90],
        'rentals' => ['الأصول والإيجارات', 'building', 100],
        'orphans_group' => ['شؤون الأيتام والكفالات', 'heart', 110],
        'system_settings' => ['الإعدادات والصلاحيات', 'settings', 200],
    ];

    /**
     * Pages: code => [label, route, route params, visibility rule, icon, group code or null, sort, shown by default].
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, string>, 3: string, 4: string, 5: ?string, 6: int, 7: bool}>
     */
    public const ITEMS = [
        'dashboard' => ['مساحتي', 'ui.preview', [], 'authenticated', 'layout', null, 10, true],
        'my_tasks' => ['مهامي', 'tasks.index', [], 'authenticated', 'inbox', null, 12, true],
        'my_todo' => ['مهامي الشخصية', 'todo.index', [], 'authenticated', 'clipboard-list', null, 14, true],
        'official_correspondence' => ['المراسلات الرسمية', 'correspondence.index', [], 'authenticated', 'mail', null, 16, true],
        'my_profile' => ['ملفي الوظيفي', 'my.profile', [], 'employee_profile', 'file-text', null, 18, true],
        'my_department' => ['قسمي', 'department.workspace', [], 'department_workspace', 'users', null, 20, true],
        'kpi_dashboard' => ['مؤشرات الأداء', 'kpi.index', [], 'kpi_view', 'chart', null, 22, true],
        'approval_delegations' => ['التفويضات الإدارية', 'admin.approval-delegations.index', [], 'approval_delegations', 'key', null, 24, true],

        'my_requests_all' => ['جميع طلباتي', 'requests.index', [], 'authenticated', 'clipboard-list', 'my_requests', 10, true],
        'leave_request' => ['طلب إجازة', 'leaves.create', [], 'leave_requester', 'calendar', 'my_requests', 20, true],
        'startable_workflows' => ['أنواع الطلبات المفعلة', NavigationBuilder::STARTABLE_WORKFLOWS, [], 'authenticated', 'handshake', 'my_requests', 30, true],
        'maintenance_draft' => ['مسودة طلب صيانة', 'maintenance.requests.create', [], 'authenticated', 'wrench', 'my_requests', 40, false],

        'leaves_requests' => ['الإجازات والموافقات', 'leaves.index', [], 'authenticated', 'calendar', 'leaves', 10, true],
        'leaves_balances' => ['أرصدة الإجازات', 'leaves.balances', [], 'authenticated', 'list', 'leaves', 20, true],
        'attendance' => ['الحضور والانصراف', 'admin.attendance.index', [], 'attendance_access', 'calendar', 'leaves', 30, true],
        'attendance_compensatory' => ['العطل والعمل التعويضي', 'admin.attendance.compensatory.index', [], 'attendance_access', 'calendar', 'leaves', 40, true],
        'attendance_operations' => ['عمليات الحضور والأجهزة', 'admin.attendance.operations', [], 'manage_employees', 'settings', 'leaves', 50, true],

        'employee_profiles' => ['ملفات الموظفين', 'admin.employee-profiles.index', [], 'manage_employees', 'users', 'hr_group', 10, true],
        'employee_imports' => ['استيراد الموظفين', 'admin.employee-imports.index', [], 'manage_employees', 'list', 'hr_group', 20, true],
        'employee_accounts' => ['حسابات الموظفين والأدوار', 'admin.employees.index', [], 'manage_employees', 'key', 'hr_group', 30, true],
        'departments' => ['الهيكل التنظيمي', 'admin.organization.index', [], 'org_structure', 'building', 'hr_group', 40, true],
        'hr_dashboard' => ['متابعة الموظفين والخدمات', 'hr.dashboard', [], 'hr_dashboard', 'chart', 'hr_group', 50, true],

        'finance_pending' => ['بانتظار إجرائي', 'finance.index', ['view' => 'pending'], 'finance_cycle', 'inbox', 'accounts_home', 10, true],
        'finance_overview' => ['الدورة المستندية', 'finance.index', ['view' => 'overview'], 'finance_cycle', 'git-branch', 'accounts_home', 20, true],
        'finance_archive' => ['السجل', 'finance.index', ['view' => 'archive'], 'finance_cycle', 'save', 'accounts_home', 30, true],
        'finance_reports' => ['التقارير والتصدير', 'finance.index', ['view' => 'reports'], 'finance:finance.reports.view', 'file-spreadsheet', 'accounts_home', 40, true],

        'finance_purchases' => ['طلبات الشراء', 'finance.index', ['view' => 'purchases'], 'finance_purchasing', 'shopping-cart', 'purchasing', 10, true],
        'finance_quotes' => ['عروض الأسعار', 'finance.index', ['view' => 'quotes'], 'finance:finance.procurement.manage|finance.procurement.review', 'clipboard-list', 'purchasing', 20, true],
        'finance_orders' => ['أوامر الشراء', 'finance.index', ['view' => 'orders'], 'finance:finance.procurement.manage|finance.procurement.review|finance.purchase.receive|finance.purchase.invoice', 'file-text', 'purchasing', 30, true],
        'finance_suppliers' => ['الموردون', 'finance.index', ['view' => 'suppliers'], 'finance:finance.procurement.manage|finance.procurement.review', 'handshake', 'purchasing', 40, true],

        'finance_receipts' => ['الاستلام', 'finance.index', ['view' => 'purchase_receipts'], 'finance:finance.procurement.manage|finance.procurement.review|finance.purchase.receive|finance.purchase.invoice', 'package-check', 'stores', 10, true],
        'finance_stock' => ['الصرف وحركات المخزون', 'finance.index', ['view' => 'stock'], 'finance:finance.stock.receive|finance.stock.issue|finance.warehouse.manage', 'boxes', 'stores', 20, true],
        'finance_items' => ['الأصناف', 'finance.index', ['view' => 'items'], 'finance:finance.warehouse.manage', 'list', 'stores', 30, true],
        'finance_warehouses' => ['تعريف المخازن', 'finance.index', ['view' => 'warehouses'], 'finance:finance.warehouse.manage', 'building', 'stores', 40, true],
        'finance_counts' => ['الجرد', 'finance.index', ['view' => 'counts'], 'finance:finance.stock.count|finance.stock.count.approve', 'clipboard-list', 'stores', 50, true],

        'finance_invoices' => ['فواتير الموردين', 'finance.index', ['view' => 'invoices'], 'finance:finance.purchase.invoice|finance.procurement.review', 'receipt', 'finance', 5, true],

        'finance_approvals' => ['صندوق الاعتمادات المالية', 'finance.approvals', [], 'finance_approver', 'key', 'finance', 10, true],
        'finance_treasuries' => ['الصناديق والبنوك', 'finance.index', ['view' => 'treasuries'], 'finance:finance.treasury.manage', 'coins', 'finance', 20, true],
        'finance_transactions' => ['سندات القبض والصرف', 'finance.index', ['view' => 'transactions'], 'finance:finance.transaction.draft', 'list', 'finance', 30, true],
        'finance_receivables' => ['المستحقات', 'finance.index', ['view' => 'receivables'], 'finance:finance.receivable.manage', 'list', 'finance', 40, true],
        'finance_rentals' => ['قبض الإيجارات وتسوياتها', 'finance.rentals', [], 'finance:finance.rentals.record', 'coins', 'finance', 50, true],
        'finance_sponsorships' => ['قبض الكفالات والتبرعات وصرفها', 'finance.sponsorships', [], 'finance:finance.sponsorships.record', 'coins', 'finance', 55, true],
        'finance_transfers' => ['التحويلات', 'finance.index', ['view' => 'transfers'], 'finance:finance.transfer.execute', 'list', 'finance', 60, true],
        'finance_advances' => ['العهد والسلف', 'finance.index', ['view' => 'advances'], 'finance:finance.advance.manage', 'list', 'finance', 70, true],
        'finance_cheques' => ['الشيكات', 'finance.index', ['view' => 'cheques'], 'finance:finance.cheque.manage', 'list', 'finance', 80, true],
        'finance_reconciliations' => ['المطابقة', 'finance.index', ['view' => 'reconciliations'], 'finance:finance.reconcile', 'list', 'finance', 90, true],

        'finance_payroll' => ['الرواتب', 'finance.index', ['view' => 'payroll'], 'finance:finance.payroll.manage', 'wallet', 'payroll_group', 10, true],
        'finance_payroll_items' => ['بنود الرواتب', 'finance.index', ['view' => 'payroll_items'], 'finance:finance.payroll.manage', 'list', 'payroll_group', 20, true],

        'finance_budgets' => ['الموازنات', 'finance.index', ['view' => 'budgets'], 'finance:finance.budget.manage', 'chart', 'budgets_group', 10, true],
        'finance_budget_exceptions' => ['استثناءات الموازنة', 'finance.index', ['view' => 'budget_exceptions'], 'finance:finance.budget.transfer', 'list', 'budgets_group', 20, true],
        'finance_budget_transfers' => ['مناقلات الموازنة', 'finance.index', ['view' => 'budget_transfers'], 'finance:finance.budget.transfer', 'list', 'budgets_group', 30, true],
        'finance_funds' => ['التمويل المخصص', 'finance.index', ['view' => 'funds'], 'finance:finance.fund.manage', 'coins', 'budgets_group', 40, true],
        'finance_fund_movements' => ['حركات الأموال المقيدة', 'finance.index', ['view' => 'fund_movements'], 'finance:finance.fund.manage', 'list', 'budgets_group', 50, true],

        'rentals_file' => ['ملف الأصول والإيجارات', 'admin.rentals.index', [], 'permission:rentals.view', 'building', 'rentals', 10, true],
        'rentals_buildings' => ['إدارة المباني والأعيان', 'admin.master-data.buildings.index', [], 'permission:rentals.manage', 'settings', 'rentals', 20, true],

        // «شؤون الأيتام والكفالات»: five task entries. Rare pages open from inside them («استيراد الكشوف» and
        // «طلبات الأوصياء» from «المراجعة والمتابعة», «الحملات الإغاثية» from «الكفالات والمساعدات»); settings sit with the settings.
        'orphans_registry' => ['لوحة القسم', 'orphans.index', [], 'orphans_review', 'heart', 'orphans_group', 5, true],
        'orphans_records' => ['الأيتام والأوصياء', 'orphans.records', [], 'orphans_review', 'users', 'orphans_group', 6, true],
        'orphans_staging' => ['المراجعة والمتابعة', 'orphans.staging.index', [], 'orphans_review', 'clipboard-list', 'orphans_group', 7, true],
        'orphans_imports' => ['استيراد الكشوف', 'orphans.imports.index', [], 'orphans_imports', 'file-spreadsheet', 'orphans_group', 8, false],
        'orphans_guardians' => ['طلبات الأوصياء', 'admin.orphans.guardians.index', [], 'orphans_review', 'users', 'orphans_group', 10, false],
        'orphans_sponsorships' => ['الكفالات والمساعدات', 'orphans.aid', [], 'orphans_aid', 'coins', 'orphans_group', 25, true],
        'relief_campaigns' => ['الحملات الإغاثية', 'relief.campaigns.index', [], 'relief_campaigns', 'heart', 'orphans_group', 30, false],
        'orphans_reports' => ['التقارير والإحصائيات', 'orphans.reports', [], 'orphans_review', 'chart', 'orphans_group', 40, true],
        'orphans_delegations' => ['تفويضات الأيتام والكفالات', 'admin.orphans.delegations.index', [], 'orphans_delegations', 'key', 'system_settings', 160, true],
        'relief_devices' => ['أجهزة التوزيع الميداني', 'relief.devices', [], 'relief_devices', 'key', 'system_settings', 170, true],
        'orphans_settings' => ['إعدادات قسم الكفالات', 'orphans.settings', [], 'orphans_settings', 'settings', 'system_settings', 180, true],

        'master_data' => ['ثوابت النظام', 'admin.master-data.index', [], 'system_admin', 'list', 'system_settings', 10, true],
        'job_catalog' => ['الفئات والمسميات الوظيفية', 'admin.job-catalog.index', [], 'system_admin', 'users', 'system_settings', 20, true],
        'career_scales' => ['السلالم والدرجات الوظيفية', 'admin.career-scales.index', [], 'system_admin', 'list', 'system_settings', 30, true],
        'leave_settings' => ['إعدادات الإجازات', 'admin.leaves.settings', [], 'system_admin', 'calendar', 'system_settings', 40, true],
        'leave_entitlement_rules' => ['قواعد استحقاق الإجازات', 'admin.leave-entitlement-rules.index', [], 'system_admin', 'calendar', 'system_settings', 50, true],
        'finance_access' => ['تكليفات الحسابات', 'admin.finance.access', [], 'system_admin', 'key', 'system_settings', 60, true],
        'financial_settings' => ['إعدادات العملات والصلاحيات المالية', 'admin.financial-settings.ui', [], 'system_admin', 'coins', 'system_settings', 70, true],
        'workflows_admin' => ['مسارات العمل', 'admin.workflows.index', [], 'system_admin', 'git-branch', 'system_settings', 80, true],
        'correspondence_policies' => ['سياسات المراسلات', 'correspondence.policies', [], 'system_admin', 'mail', 'system_settings', 90, true],
        'notification_settings' => ['إعدادات الإشعارات', 'admin.notification-settings.index', [], 'system_admin', 'inbox', 'system_settings', 100, true],
        'custom_fields_admin' => ['الحقول الديناميكية', 'admin.custom-fields.index', [], 'system_admin', 'save', 'system_settings', 110, true],
        'navigation_admin' => ['القائمة الرئيسية', 'admin.navigation.index', [], 'system_admin', 'list', 'system_settings', 120, true],
        'dashboard_layouts' => ['تخصيص اللوحات', 'admin.dashboard-layouts.index', [], 'system_admin', 'layout', 'system_settings', 130, true],
        'kpi_definitions' => ['إدارة مؤشرات الأداء', 'admin.kpi.index', [], 'system_admin', 'chart', 'system_settings', 140, true],
        'audit_logs' => ['سجل نشاط النظام', 'admin.audit-logs.index', [], 'system_admin', 'clipboard-list', 'system_settings', 150, true],
    ];

    /** Entries that cannot be hidden or restricted, so nobody locks themselves out of the menu or this page. */
    public const LOCKED = ['dashboard', 'navigation_admin'];

    /** Section headings of the old single accounts group; the functional groups replaced them. */
    public const RETIRED = ['finance_h_follow', 'finance_h_purchasing', 'finance_h_stores', 'finance_h_rentals', 'finance_h_records', 'finance_h_accounting',
        // Split into «المشتريات» and «المخازن» (2026-10-30).
        'purchasing_stores'];

    public function __construct(private readonly AuditService $audit) {}

    public static function isRegistered(string $code): bool
    {
        return isset(self::GROUPS[$code]) || isset(self::ITEMS[$code]);
    }

    /**
     * Brings the table in line with the registry. Always: adds missing entries, fixes routes and visibility rules,
     * drops retired headings. With $reset it also restores the default names, icons, groups, order and visibility.
     *
     * @return array{added: int, fixed: int, reset: int, retired: int}
     */
    public function apply(?int $actorId, bool $reset = false, ?string $ip = null): array
    {
        return DB::transaction(function () use ($actorId, $reset, $ip): array {
            $rows = DB::table('navigation_items')->lockForUpdate()->get()->keyBy('code');
            $counts = ['added' => 0, 'fixed' => 0, 'reset' => 0, 'retired' => 0];
            $ids = [];
            $entries = [];
            foreach (self::GROUPS as $code => [$label, $icon, $sort]) {
                $entries[$code] = ['label' => $label, 'icon' => $icon, 'sort' => $sort, 'group' => null, 'active' => true, 'technical' => ['route_name' => null, 'route_params' => null, 'access_rule' => 'authenticated']];
            }
            foreach (self::ITEMS as $code => [$label, $route, $params, $rule, $icon, $group, $sort, $active]) {
                $entries[$code] = ['label' => $label, 'icon' => $icon, 'sort' => $sort, 'group' => $group, 'active' => $active,
                    'technical' => ['route_name' => $route, 'route_params' => $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE), 'access_rule' => $rule]];
            }
            foreach ($entries as $code => $entry) {
                $row = $rows->get($code);
                $parent = $entry['group'] === null ? null : ($ids[$entry['group']] ?? null);
                $display = ['label_ar' => $entry['label'], 'icon' => $entry['icon'], 'parent_id' => $parent, 'sort_order' => $entry['sort'], 'is_active' => $entry['active'], 'audience_roles' => null];
                if (! $row) {
                    $ids[$code] = (int) DB::table('navigation_items')->insertGetId(['code' => $code, ...$display, ...$entry['technical'],
                        'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                    $counts['added']++;

                    continue;
                }
                $ids[$code] = (int) $row->id;
                $changes = [];
                foreach ($entry['technical'] as $field => $value) {
                    if ($this->differs($row->$field, $value, $field === 'route_params')) {
                        $changes[$field] = $value;
                    }
                }
                $counts['fixed'] += $changes !== [] ? 1 : 0;
                if ($reset) {
                    $resetChanges = array_filter($display, fn ($value, string $field): bool => $this->differs($row->$field, $value, $field === 'audience_roles'), ARRAY_FILTER_USE_BOTH);
                    $counts['reset'] += $resetChanges !== [] ? 1 : 0;
                    $changes += $resetChanges;
                }
                if ($changes !== []) {
                    DB::table('navigation_items')->where('id', $row->id)->update([...$changes, 'updated_by' => $actorId, 'updated_at' => now()]);
                }
            }
            foreach (self::RETIRED as $code) {
                $row = $rows->get($code);
                if ($row && ! DB::table('navigation_items')->where('parent_id', $row->id)->exists()) {
                    DB::table('navigation_items')->where('id', $row->id)->delete();
                    $this->audit->recordRow('navigation.item.retired', 'navigation_items', (int) $row->id, (array) $row, null, $actorId, $ip);
                    $counts['retired']++;
                }
            }
            if (array_sum($counts) > 0) {
                $this->audit->recordRow($reset ? 'navigation.layout.reset' : 'navigation.registry.synced', 'navigation_items', 0, null, $counts, $actorId, $ip);
            }

            return $counts;
        });
    }

    private function differs(mixed $current, mixed $wanted, bool $json): bool
    {
        if ($json) {
            return (json_decode((string) $current, true) ?: null) !== (json_decode((string) $wanted, true) ?: null);
        }
        if (is_bool($wanted)) {
            return (bool) $current !== $wanted;
        }

        return (string) $current !== (string) $wanted || ($current === null) !== ($wanted === null);
    }
}
