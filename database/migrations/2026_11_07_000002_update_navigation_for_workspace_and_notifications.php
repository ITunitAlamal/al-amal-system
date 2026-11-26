<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu data only (owner decisions of 2026-10-22):
 * - the employee home is named «مساحتي»;
 * - the central notification settings page appears under «إعدادات النظام» for the system admin.
 * Both changes are recorded in the audit log. Admins can still rename or move them from «القوائم الجانبية».
 */
return new class extends Migration
{
    public function up(): void
    {
        $dashboard = DB::table('navigation_items')->where('code', 'dashboard')->first(['id', 'label_ar']);
        if ($dashboard && $dashboard->label_ar !== 'مساحتي') {
            DB::table('navigation_items')->where('id', $dashboard->id)->update(['label_ar' => 'مساحتي', 'updated_at' => now()]);
            $this->audit('navigation.item.updated', $dashboard->id, ['label_ar' => $dashboard->label_ar], ['label_ar' => 'مساحتي']);
        }

        $parentId = DB::table('navigation_items')->where('code', 'system_settings')->value('id');
        if ($parentId && ! DB::table('navigation_items')->where('code', 'notification_settings')->exists()) {
            $sort = (int) DB::table('navigation_items')->where('parent_id', $parentId)->max('sort_order') + 10;
            $id = DB::table('navigation_items')->insertGetId([
                'code' => 'notification_settings', 'parent_id' => $parentId, 'label_ar' => 'إعدادات الإشعارات',
                'icon' => 'inbox', 'route_name' => 'admin.notification-settings.index', 'access_rule' => 'system_admin',
                'sort_order' => $sort, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('navigation.item.created', $id, null, ['code' => 'notification_settings', 'label_ar' => 'إعدادات الإشعارات']);
        }
    }

    public function down(): void
    {
        DB::table('navigation_items')->where('code', 'notification_settings')->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, int $id, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => 'navigation_items', 'subject_id' => $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after, 'source' => 'قرار صاحب المشروع 2026-10-22'], JSON_UNESCAPED_UNICODE),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
