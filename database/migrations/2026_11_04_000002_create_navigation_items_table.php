<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sidebar menu as data (SRS CFG04). Items point to named routes and are
     * shown according to an access rule from NavigationAccessRules.
     */
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->restrictOnDelete();
            $table->string('label_ar', 150);
            $table->string('label_en', 150)->nullable();
            $table->string('icon', 40)->default('folder');
            $table->string('route_name', 150)->nullable();
            $table->string('access_rule', 150)->default('authenticated');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order'], 'navigation_items_tree_index');
        });

        $this->seedCurrentMenu();
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }

    /**
     * Initial items mirror the menu that was previously hard-coded in
     * HandleInertiaRequests and AppLayout.vue.
     */
    private function seedCurrentMenu(): void
    {
        $now = now();
        $insert = function (string $code, string $label, ?string $route, string $icon, string $rule, int $order, ?int $parentId = null) use ($now): int {
            return DB::table('navigation_items')->insertGetId([
                'code' => $code, 'parent_id' => $parentId, 'label_ar' => $label, 'icon' => $icon,
                'route_name' => $route, 'access_rule' => $rule, 'sort_order' => $order, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        };

        $insert('dashboard', 'لوحة التحكم', 'ui.preview', 'layout', 'authenticated', 10);

        $requests = $insert('my_requests', 'طلباتي', null, 'clipboard-list', 'authenticated', 20);
        $insert('my_requests_all', 'جميع طلباتي', 'requests.index', 'clipboard-list', 'authenticated', 10, $requests);
        $insert('maintenance_draft', 'مسودة طلب صيانة', 'maintenance.requests.create', 'wrench', 'authenticated', 20, $requests);
        $insert('leave_request', 'طلب إجازة', 'leaves.create', 'calendar', 'leave_requester', 30, $requests);
        // Expands into one link per workflow that employees may start.
        $insert('startable_workflows', 'أنواع الطلبات المفعلة', '@startable_workflows', 'handshake', 'authenticated', 40, $requests);

        $insert('my_tasks', 'مهامي', 'tasks.index', 'inbox', 'authenticated', 25);
        $insert('leaves', 'الإجازات والموافقات', 'leaves.index', 'calendar', 'authenticated', 30);
        $insert('employee_profiles', 'ملفات الموظفين', 'admin.employee-profiles.index', 'users', 'manage_employees', 40);
        $insert('employee_accounts', 'حسابات الموظفين والأدوار', 'admin.employees.index', 'users', 'manage_employees', 50);
        $insert('departments', 'الأقسام', 'admin.departments.vue', 'building', 'manage_employees', 60);
        $insert('attendance', 'الحضور والانصراف', 'admin.attendance.index', 'calendar', 'attendance_access', 70);
        $insert('attendance_compensatory', 'العطل والعمل التعويضي', 'admin.attendance.compensatory.index', 'calendar', 'attendance_access', 80);
        $insert('finance', 'الحسابات والمشتريات والمخازن', 'finance.index', 'coins', 'finance_view', 90);
        $insert('orphans_guardians', 'طلبات الأوصياء', 'admin.orphans.guardians.index', 'users', 'orphans_review', 100);
        $insert('orphans_delegations', 'تفويضات الكفالات والأيتام', 'admin.orphans.delegations.index', 'key', 'orphans_delegations', 110);
        $insert('rentals', 'العقارات والإيجارات', 'admin.rentals.index', 'building', 'system_admin', 120);

        $settings = $insert('system_settings', 'إعدادات النظام', null, 'settings', 'system_admin', 200);
        $insert('master_data', 'ثوابت النظام', 'admin.master-data.index', 'list', 'system_admin', 10, $settings);
        $insert('job_catalog', 'الوظائف والدرجات', 'admin.job-catalog.index', 'users', 'system_admin', 20, $settings);
        $insert('leave_settings', 'إعدادات الإجازات', 'admin.leaves.settings', 'calendar', 'system_admin', 30, $settings);
        $insert('finance_access', 'تكليفات الحسابات', 'admin.finance.access', 'key', 'system_admin', 40, $settings);
        $insert('financial_settings', 'إعدادات العملات والصلاحيات المالية', 'admin.financial-settings.ui', 'coins', 'system_admin', 50, $settings);
        $insert('navigation_admin', 'القوائم الجانبية', 'admin.navigation.index', 'list', 'system_admin', 60, $settings);
        $insert('custom_fields_admin', 'الحقول الديناميكية', 'admin.custom-fields.index', 'save', 'system_admin', 70, $settings);
        $insert('workflows_admin', 'مسارات العمل', 'admin.workflows.index', 'git-branch', 'system_admin', 80, $settings);
        $insert('audit_logs', 'سجل نشاط النظام', 'admin.audit-logs.index', 'clipboard-list', 'system_admin', 90, $settings);
    }
};
