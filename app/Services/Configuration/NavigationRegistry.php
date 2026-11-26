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
        // Personal self-service of every employee: requests, leaves, own to-do and profile.
        'my_requests' => ['خدماتي', 'clipboard-list', 30],
        // Oversight for heads, the executive director and delegates (each link has its own rule).
        'management' => ['الإدارة والمتابعة', 'chart', 40],
        'hr_group' => ['الموارد البشرية', 'users', 50],
        // One accounts group: its sections (purchasing, stores, accounting, payroll, budgets) are tabs of the accounts
        // page («أقسام الحسابات»), filtered by each user's finance permissions; their old links stay in the editor, hidden.
        'accounts_home' => ['الحسابات', 'coins', 60],
        'rentals' => ['الأصول والمباني والإيجارات', 'building', 100],
        'orphans_group' => ['شؤون الأيتام والكفالات', 'heart', 110],
        'system_settings' => ['الإعدادات والصلاحيات', 'settings', 200],
    ];

    /**
     * Pages: code => [label, route, route params, visibility rule, icon, group code or null, sort, shown by default].
     * «shown by default» = false: the page keeps its route and its own link inside a page (tab, action, panel), and the
     * administrator may show it in the menu again from «القائمة الرئيسية».
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, string>, 3: string, 4: string, 5: ?string, 6: int, 7: bool}>
     */
    public const ITEMS = [
        'dashboard' => ['مساحتي', 'ui.preview', [], 'authenticated', 'layout', null, 10, true],
        'my_tasks' => ['مهامي', 'tasks.index', [], 'authenticated', 'inbox', null, 12, true],
        'official_correspondence' => ['المراسلات الرسمية', 'correspondence.index', [], 'authenticated', 'mail', null, 16, true],

        'my_requests_all' => ['طلباتي', 'requests.index', [], 'authenticated', 'clipboard-list', 'my_requests', 10, true],
        'leaves_requests' => ['الإجازات والموافقات', 'leaves.index', [], 'authenticated', 'calendar', 'my_requests', 20, true],
        'leaves_balances' => ['أرصدة الإجازات', 'leaves.balances', [], 'authenticated', 'list', 'my_requests', 30, true],
        'my_todo' => ['مهامي الشخصية', 'todo.index', [], 'authenticated', 'clipboard-list', 'my_requests', 40, true],
        'my_profile' => ['ملفي الوظيفي', 'my.profile', [], 'employee_profile', 'file-text', 'my_requests', 50, true],
        'my_attendance' => ['دوامي الشهري', 'attendance.monthly', [], 'monthly_attendance', 'calendar', 'my_requests', 55, true],
        // New requests start from «طلباتي ← طلب جديد» and from «مساحتي».
        'leave_request' => ['طلب إجازة', 'leaves.create', [], 'leave_requester', 'calendar', 'my_requests', 60, false],
        'startable_workflows' => ['أنواع الطلبات المفعلة', NavigationBuilder::STARTABLE_WORKFLOWS, [], 'authenticated', 'handshake', 'my_requests', 70, false],
        'maintenance_draft' => ['مسودة طلب صيانة', 'maintenance.requests.create', [], 'authenticated', 'wrench', 'my_requests', 80, false],

        'my_department' => ['قسمي', 'department.workspace', [], 'department_workspace', 'users', 'management', 10, true],
        'shift_roster' => ['جدول المناوبات', 'attendance.shifts', [], 'shift_roster', 'calendar', 'management', 15, true],
        'departments' => ['الهيكل التنظيمي', 'admin.organization.index', [], 'org_structure', 'building', 'management', 20, true],
        'kpi_dashboard' => ['مؤشرات الأداء', 'kpi.index', [], 'kpi_view', 'chart', 'management', 30, true],
        'approval_delegations' => ['التفويضات الإدارية', 'admin.approval-delegations.index', [], 'approval_delegations', 'key', 'management', 40, true],

        'employee_profiles' => ['ملفات الموظفين', 'admin.employee-profiles.index', [], 'manage_employees', 'users', 'hr_group', 10, true],
        'employee_accounts' => ['حسابات الموظفين والأدوار', 'admin.employees.index', [], 'manage_employees', 'key', 'hr_group', 20, true],
        'attendance' => ['الحضور والانصراف', 'admin.attendance.index', [], 'attendance_access', 'calendar', 'hr_group', 30, true],
        'attendance_compensatory' => ['العطل والعمل التعويضي', 'admin.attendance.compensatory.index', [], 'attendance_access', 'calendar', 'hr_group', 40, true],
        'attendance_operations' => ['عمليات الحضور والأجهزة', 'admin.attendance.operations', [], 'manage_employees', 'settings', 'hr_group', 50, true],
        // A button on «ملفات الموظفين».
        'employee_imports' => ['استيراد الموظفين', 'admin.employee-imports.index', [], 'manage_employees', 'list', 'hr_group', 60, false],
        'hr_dashboard' => ['متابعة الموظفين والخدمات', 'hr.dashboard', [], 'hr_dashboard', 'chart', 'hr_group', 70, true],

        'finance_pending' => ['بانتظار إجرائي', 'finance.index', ['view' => 'pending'], 'finance_cycle', 'inbox', 'accounts_home', 10, true],
        'finance_home' => ['أقسام الحسابات', 'finance.index', [], 'finance_view', 'list', 'accounts_home', 20, true],
        'finance_approvals' => ['صندوق الاعتمادات المالية', 'finance.approvals', [], 'finance_approver', 'key', 'accounts_home', 30, true],
        'finance_rentals' => ['قبض الإيجارات وتسوياتها', 'finance.rentals', [], 'finance:finance.rentals.record', 'coins', 'accounts_home', 40, true],
        'finance_reports' => ['التقارير والتصدير', 'finance.index', ['view' => 'reports'], 'finance:finance.reports.view', 'file-spreadsheet', 'accounts_home', 50, true],
        'finance_payroll_runs' => ['مسير الرواتب والقسائم', 'finance.payroll-runs.index', [], 'finance:finance.payroll.record|finance.payroll.publish|finance.monitor', 'users', 'accounts_home', 60, true],
        // Finance settings: the head of Accounts and the system administrator only, never a top-level entry.
        'finance_staff' => ['موظفو القسم وصلاحياتهم', 'finance.staff', [], 'finance:finance.staff.assign', 'key', 'accounts_home', 70, true],
        // Sections of the accounts page (its own navigation, by permission); hidden from the menu by default.
        'finance_overview' => ['الدورة المستندية', 'finance.index', ['view' => 'overview'], 'finance_cycle', 'git-branch', 'accounts_home', 100, false],
        'finance_archive' => ['السجل', 'finance.index', ['view' => 'archive'], 'finance_cycle', 'save', 'accounts_home', 101, false],
        'finance_purchases' => ['طلبات الشراء', 'finance.index', ['view' => 'purchases'], 'finance_purchasing', 'shopping-cart', 'accounts_home', 110, false],
        'finance_quotes' => ['عروض الأسعار', 'finance.index', ['view' => 'quotes'], 'finance:finance.procurement.manage|finance.procurement.review', 'clipboard-list', 'accounts_home', 111, false],
        'finance_orders' => ['أوامر الشراء', 'finance.index', ['view' => 'orders'], 'finance:finance.procurement.manage|finance.procurement.review|finance.purchase.receive|finance.purchase.invoice', 'file-text', 'accounts_home', 112, false],
        'finance_suppliers' => ['الموردون', 'finance.index', ['view' => 'suppliers'], 'finance:finance.procurement.manage|finance.procurement.review', 'handshake', 'accounts_home', 113, false],
        'finance_receipts' => ['الاستلام', 'finance.index', ['view' => 'purchase_receipts'], 'finance:finance.procurement.manage|finance.procurement.review|finance.purchase.receive|finance.purchase.invoice', 'package-check', 'accounts_home', 120, false],
        'finance_stock' => ['الصرف وحركات المخزون', 'finance.index', ['view' => 'stock'], 'finance:finance.stock.receive|finance.stock.issue|finance.warehouse.manage', 'boxes', 'accounts_home', 121, false],
        'finance_items' => ['الأصناف', 'finance.index', ['view' => 'items'], 'finance:finance.warehouse.manage', 'list', 'accounts_home', 122, false],
        'finance_warehouses' => ['تعريف المخازن', 'finance.index', ['view' => 'warehouses'], 'finance:finance.warehouse.manage', 'building', 'accounts_home', 123, false],
        'finance_counts' => ['الجرد', 'finance.index', ['view' => 'counts'], 'finance:finance.stock.count|finance.stock.count.approve', 'clipboard-list', 'accounts_home', 124, false],
        'finance_invoices' => ['فواتير الموردين', 'finance.index', ['view' => 'invoices'], 'finance:finance.purchase.invoice|finance.procurement.review', 'receipt', 'accounts_home', 130, false],
        'finance_treasuries' => ['الصناديق والبنوك', 'finance.index', ['view' => 'treasuries'], 'finance:finance.treasury.manage', 'coins', 'accounts_home', 131, false],
        'finance_transactions' => ['سندات القبض والصرف', 'finance.index', ['view' => 'transactions'], 'finance:finance.transaction.draft', 'list', 'accounts_home', 132, false],
        'finance_receivables' => ['المستحقات', 'finance.index', ['view' => 'receivables'], 'finance:finance.receivable.manage', 'list', 'accounts_home', 133, false],
        'finance_transfers' => ['التحويلات', 'finance.index', ['view' => 'transfers'], 'finance:finance.transfer.execute', 'list', 'accounts_home', 134, false],
        'finance_advances' => ['العهد والسلف', 'finance.index', ['view' => 'advances'], 'finance:finance.advance.manage', 'list', 'accounts_home', 135, false],
        'finance_cheques' => ['الشيكات', 'finance.index', ['view' => 'cheques'], 'finance:finance.cheque.manage', 'list', 'accounts_home', 136, false],
        'finance_reconciliations' => ['المطابقة', 'finance.index', ['view' => 'reconciliations'], 'finance:finance.reconcile', 'list', 'accounts_home', 137, false],
        'finance_payroll' => ['الرواتب', 'finance.index', ['view' => 'payroll'], 'finance:finance.payroll.manage', 'wallet', 'accounts_home', 140, false],
        'finance_payroll_items' => ['بنود الرواتب', 'finance.index', ['view' => 'payroll_items'], 'finance:finance.payroll.manage', 'list', 'accounts_home', 141, false],
        'finance_budgets' => ['الموازنات', 'finance.index', ['view' => 'budgets'], 'finance:finance.budget.manage', 'chart', 'accounts_home', 150, false],
        'finance_budget_exceptions' => ['استثناءات الموازنة', 'finance.index', ['view' => 'budget_exceptions'], 'finance:finance.budget.transfer', 'list', 'accounts_home', 151, false],
        'finance_budget_transfers' => ['مناقلات الموازنة', 'finance.index', ['view' => 'budget_transfers'], 'finance:finance.budget.transfer', 'list', 'accounts_home', 152, false],
        'finance_funds' => ['التمويل المخصص', 'finance.index', ['view' => 'funds'], 'finance:finance.fund.manage', 'coins', 'accounts_home', 153, false],
        'finance_fund_movements' => ['حركات الأموال المقيدة', 'finance.index', ['view' => 'fund_movements'], 'finance:finance.fund.manage', 'list', 'accounts_home', 154, false],

        'rentals_file' => ['ملف الأصول والإيجارات', 'admin.rentals.index', [], 'permission:rentals.view', 'building', 'rentals', 10, true],
        'rentals_buildings' => ['إدارة المباني والأعيان', 'admin.master-data.buildings.index', [], 'permission:rentals.manage', 'settings', 'rentals', 20, true],

        // «شؤون الأيتام والكفالات» (AIMS 2.0): exactly six department entries; settings sit with the settings.
        'orphans_home' => ['الرئيسية', 'orphans.home', [], 'orphans_staff', 'layout', 'orphans_group', 10, true],
        'orphans_registry' => ['إدارة الأيتام والأوصياء', 'orphans.registry.index', [], 'orphans_staff', 'users', 'orphans_group', 20, true],
        'orphans_reviews' => ['الطلبات والمراجعات', 'orphans.reviews.index', [], 'orphans_staff', 'inbox', 'orphans_group', 30, true],
        'orphans_aid' => ['الكفالات والمساعدات', 'orphans.aid.index', [], 'orphans_staff', 'coins', 'orphans_group', 40, true],
        'orphans_campaigns' => ['الحملات الإغاثية', 'orphans.campaigns.index', [], 'orphans_campaigns', 'heart', 'orphans_group', 50, true],
        'orphans_reports' => ['التقارير والإحصائيات', 'orphans.reports', [], 'orphans_staff', 'chart', 'orphans_group', 60, true],
        'orphans_settings' => ['إعدادات قسم الأيتام والكفالات', 'orphans.settings.index', [], 'orphans_settings', 'settings', 'system_settings', 180, true],

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
        'audit_logs' => ['سجل النظام', 'admin.audit-logs.index', [], 'audit_viewer', 'clipboard-list', 'system_settings', 150, true],
    ];

    /** Entries that cannot be hidden or restricted, so nobody locks themselves out of the menu or this page. */
    public const LOCKED = ['dashboard', 'navigation_admin'];

    /** Section headings of the old single accounts group; the functional groups replaced them. */
    public const RETIRED = ['finance_h_follow', 'finance_h_purchasing', 'finance_h_stores', 'finance_h_rentals', 'finance_h_records', 'finance_h_accounting',
        // Split into «المشتريات» and «المخازن» (2026-10-30).
        'purchasing_stores',
        // Merged into «خدماتي»، «الموارد البشرية» and «الحسابات» (2026-11-01).
        'leaves', 'purchasing', 'stores', 'finance', 'payroll_group', 'budgets_group'];

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
