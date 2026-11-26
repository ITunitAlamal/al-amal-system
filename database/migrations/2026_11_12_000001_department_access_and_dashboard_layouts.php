<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Department-head access repair and dashboard layout templates. Additive, and grants nothing to anyone:
 * - rentals.view / rentals.manage replace the hard system-admin lock of the rentals module; they sit only on the
 *   new, unassigned role «مسؤول ملف الإيجارات» until the owner decides who holds it;
 * - Arabic display names for the finance roles (codes unchanged);
 * - «قسمي» and «تخصيص اللوحات» menu links;
 * - dashboard_layouts (one row per scope) and dashboard_layout_versions (every change kept, restorable).
 */
return new class extends Migration
{
    private const FINANCE_NAMES = [
        'finance_head' => 'رئيس قسم الحسابات (وحدة الحسابات)',
        'finance_accountant' => 'محاسب',
        'finance_procurement' => 'مسؤول المشتريات (وحدة الحسابات)',
        'finance_warehouse' => 'أمين المخازن (وحدة الحسابات)',
        'finance_cash_executor' => 'منفّذ الصرف من الصندوق',
        'finance_director_approver' => 'المعتمد المالي',
        'finance_board_treasurer' => 'أمين صندوق مجلس الإدارة',
    ];

    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 20);
            $table->string('scope_key', 100)->default('');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_key']);
        });
        DB::statement("ALTER TABLE dashboard_layouts ADD CONSTRAINT dashboard_layouts_scope_check CHECK (scope_type IN ('organization', 'role', 'department', 'user'))");

        Schema::create('dashboard_layout_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('layout_id')->constrained('dashboard_layouts')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('configuration');
            $table->string('change_reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['layout_id', 'version']);
        });

        DB::transaction(function (): void {
            $now = now();
            $before = ['roles' => DB::table('roles')->whereIn('code', array_keys(self::FINANCE_NAMES))->pluck('name', 'code')->all(),
                'rentals_access_rule' => DB::table('navigation_items')->where('code', 'rentals')->value('access_rule')];

            $view = $this->ensure('permissions', ['code' => 'rentals.view'], ['name' => 'عرض وحدة الأصول والمباني والإيجارات']);
            $manage = $this->ensure('permissions', ['code' => 'rentals.manage'], ['name' => 'إدارة العقارات والعقود والاستحقاقات ومعاملات الإيجار']);
            $role = $this->ensure('roles', ['code' => 'rentals_officer'], ['name' => 'مسؤول ملف الإيجارات']);
            foreach ([$view, $manage] as $permission) {
                if (! DB::table('permission_role')->where('role_id', $role)->where('permission_id', $permission)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
                }
            }
            foreach (self::FINANCE_NAMES as $code => $name) {
                DB::table('roles')->where('code', $code)->where('name', $code)->update(['name' => $name, 'updated_at' => $now]);
            }
            DB::table('navigation_items')->where('code', 'rentals')->where('access_rule', 'system_admin')->update(['access_rule' => 'permission:rentals.view', 'updated_at' => $now]);

            $settings = DB::table('navigation_items')->where('code', 'system_settings')->value('id');
            $this->ensure('navigation_items', ['code' => 'my_department'], ['label_ar' => 'قسمي', 'icon' => 'users', 'route_name' => 'department.workspace', 'access_rule' => 'department_workspace', 'is_active' => true, 'sort_order' => 26]);
            $this->ensure('navigation_items', ['code' => 'dashboard_layouts'], ['label_ar' => 'تخصيص اللوحات', 'icon' => 'layout', 'parent_id' => $settings, 'route_name' => 'admin.dashboard-layouts.index', 'access_rule' => 'system_admin', 'is_active' => true, 'sort_order' => 96]);

            DB::table('audit_logs')->insert([
                'actor_id' => null, 'action' => 'access.configuration.installed', 'subject_type' => 'roles', 'subject_id' => (string) $role,
                'metadata' => json_encode(['before' => $before, 'after' => [
                    'roles' => DB::table('roles')->whereIn('code', array_keys(self::FINANCE_NAMES))->pluck('name', 'code')->all(),
                    'new_role' => 'rentals_officer (غير مسند لأحد)', 'permissions' => ['rentals.view', 'rentals.manage'],
                    'rentals_access_rule' => DB::table('navigation_items')->where('code', 'rentals')->value('access_rule'),
                    'menu' => ['my_department', 'dashboard_layouts'], 'source' => 'ترحيل 2026_11_12_000001',
                ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('dashboard_layouts') && DB::table('dashboard_layouts')->exists()) {
            throw new RuntimeException('توجد قوالب لوحات محفوظة؛ عطّلها من «تخصيص اللوحات» بدل التراجع.');
        }
        $role = DB::table('roles')->where('code', 'rentals_officer')->value('id');
        if ($role && DB::table('department_user_roles')->where('role_id', $role)->exists()) {
            throw new RuntimeException('دور «مسؤول ملف الإيجارات» مسند لمستخدمين؛ ألغِ الإسناد أولًا.');
        }
        DB::transaction(function () use ($role): void {
            DB::table('navigation_items')->whereIn('code', ['my_department', 'dashboard_layouts'])->delete();
            DB::table('navigation_items')->where('code', 'rentals')->where('access_rule', 'permission:rentals.view')->update(['access_rule' => 'system_admin']);
            if ($role) {
                DB::table('permission_role')->where('role_id', $role)->delete();
                DB::table('roles')->where('id', $role)->delete();
            }
            DB::table('permissions')->whereIn('code', ['rentals.view', 'rentals.manage'])
                ->whereNotIn('id', DB::table('permission_role')->select('permission_id'))->delete();
        });
        Schema::dropIfExists('dashboard_layout_versions');
        Schema::dropIfExists('dashboard_layouts');
    }

    private function ensure(string $table, array $key, array $values): int
    {
        $id = DB::table($table)->where($key)->value('id');

        return $id ? (int) $id : (int) DB::table($table)->insertGetId([...$key, ...$values, 'created_at' => now(), 'updated_at' => now()]);
    }
};
