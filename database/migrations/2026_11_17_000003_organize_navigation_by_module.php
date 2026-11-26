<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organises the one sidebar by module and gives every page without a menu entry one, each shown by the same rule
 * its route enforces (checked by opening each page as the administrator, the HR head, the executive director, a
 * department head and an employee). Existing items keep their ids and codes; only their parent, order and the
 * leaves item's role (link → group) change, so dashboard templates that hide or pin codes keep working.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_17_000003 (تنظيم القائمة الجانبية حسب الوحدات وإضافة الصفحات غير المدرجة)';

    /** @var array<string, array{0: int, 1: int}> code => original [parent_id(null=0), sort_order] */
    private const MOVED = ['hr_dashboard' => [0, 27], 'employee_profiles' => [0, 40], 'employee_accounts' => [0, 50], 'departments' => [0, 60],
        'attendance' => [0, 70], 'attendance_compensatory' => [0, 80], 'orphans_guardians' => [0, 100], 'orphans_delegations' => [0, 110]];

    private const ADDED = ['my_todo', 'hr_group', 'attendance_operations', 'orphans_group', 'leaves_requests', 'leaves_balances',
        'leave_entitlement_rules', 'career_scales', 'correspondence_policies'];

    public function up(): void
    {
        $now = now();
        $id = fn (string $code): int => (int) DB::table('navigation_items')->where('code', $code)->value('id');
        foreach ([...array_keys(self::MOVED), 'leaves', 'system_settings', 'my_tasks'] as $code) {
            if ($id($code) <= 0) {
                throw new RuntimeException("عنصر القائمة {$code} غير موجود.");
            }
        }
        $insert = fn (array $row): int => DB::table('navigation_items')->insertGetId($row + ['is_active' => true, 'route_params' => null, 'created_at' => $now, 'updated_at' => $now]);

        $insert(['code' => 'my_todo', 'parent_id' => null, 'label_ar' => 'قائمة مهامي الشخصية', 'icon' => 'clipboard-list', 'route_name' => 'todo.index', 'access_rule' => 'authenticated', 'sort_order' => 25]);

        DB::table('navigation_items')->where('code', 'leaves')->update(['label_ar' => 'الإجازات', 'route_name' => null, 'icon' => 'calendar', 'updated_at' => $now]);
        $insert(['code' => 'leaves_requests', 'parent_id' => $id('leaves'), 'label_ar' => 'الإجازات والموافقات', 'icon' => 'calendar', 'route_name' => 'leaves.index', 'access_rule' => 'authenticated', 'sort_order' => 10]);
        $insert(['code' => 'leaves_balances', 'parent_id' => $id('leaves'), 'label_ar' => 'أرصدة الإجازات', 'icon' => 'list', 'route_name' => 'leaves.balances', 'access_rule' => 'authenticated', 'sort_order' => 20]);

        $hr = $insert(['code' => 'hr_group', 'parent_id' => null, 'label_ar' => 'الموارد البشرية والخدمات العامة', 'icon' => 'users', 'route_name' => null, 'access_rule' => 'authenticated', 'sort_order' => 40]);
        foreach (['hr_dashboard' => 10, 'employee_profiles' => 20, 'employee_accounts' => 30, 'departments' => 40, 'attendance' => 50, 'attendance_compensatory' => 70] as $code => $order) {
            DB::table('navigation_items')->where('code', $code)->update(['parent_id' => $hr, 'sort_order' => $order, 'updated_at' => $now]);
        }
        DB::table('navigation_items')->where('code', 'hr_dashboard')->update(['label_ar' => 'لوحة الموارد البشرية والخدمات']);
        $insert(['code' => 'attendance_operations', 'parent_id' => $hr, 'label_ar' => 'عمليات الحضور والأجهزة', 'icon' => 'settings', 'route_name' => 'admin.attendance.operations', 'access_rule' => 'manage_employees', 'sort_order' => 60]);

        $orphans = $insert(['code' => 'orphans_group', 'parent_id' => null, 'label_ar' => 'الكفالات وشؤون الأيتام', 'icon' => 'handshake', 'route_name' => null, 'access_rule' => 'authenticated', 'sort_order' => 100]);
        DB::table('navigation_items')->where('code', 'orphans_guardians')->update(['parent_id' => $orphans, 'sort_order' => 10, 'updated_at' => $now]);
        DB::table('navigation_items')->where('code', 'orphans_delegations')->update(['parent_id' => $orphans, 'sort_order' => 20, 'updated_at' => $now]);

        $settings = $id('system_settings');
        $insert(['code' => 'leave_entitlement_rules', 'parent_id' => $settings, 'label_ar' => 'قواعد استحقاق الإجازات', 'icon' => 'calendar', 'route_name' => 'admin.leave-entitlement-rules.index', 'access_rule' => 'system_admin', 'sort_order' => 110]);
        $insert(['code' => 'career_scales', 'parent_id' => $settings, 'label_ar' => 'السلالم والدرجات الوظيفية', 'icon' => 'list', 'route_name' => 'admin.career-scales.index', 'access_rule' => 'system_admin', 'sort_order' => 120]);
        $insert(['code' => 'correspondence_policies', 'parent_id' => $settings, 'label_ar' => 'سياسات المراسلات', 'icon' => 'inbox', 'route_name' => 'correspondence.policies', 'access_rule' => 'system_admin', 'sort_order' => 130]);

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'navigation.organized', 'subject_type' => 'navigation_items', 'subject_id' => '0',
            'metadata' => json_encode(['before' => self::MOVED, 'after' => ['added' => self::ADDED, 'groups' => ['hr_group', 'orphans_group', 'leaves']], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        foreach (self::MOVED as $code => [$parent, $order]) {
            DB::table('navigation_items')->where('code', $code)->update(['parent_id' => $parent ?: null, 'sort_order' => $order]);
        }
        DB::table('navigation_items')->where('code', 'hr_dashboard')->update(['label_ar' => 'الموارد البشرية والخدمات العامة']);
        DB::table('navigation_items')->whereIn('code', self::ADDED)->delete();
        DB::table('navigation_items')->where('code', 'leaves')->update(['label_ar' => 'الإجازات والموافقات', 'route_name' => 'leaves.index']);
    }
};
