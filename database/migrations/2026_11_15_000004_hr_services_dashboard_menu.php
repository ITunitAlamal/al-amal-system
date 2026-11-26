<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu entry «الموارد البشرية والخدمات العامة» for the HR head's dashboard, shown by the rule "hr_dashboard"
 * (the appointed HR head, the system administrator and the executive director). No permission is granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('navigation_items')->where('code', 'hr_dashboard')->exists()) {
            return;
        }
        $id = DB::table('navigation_items')->insertGetId([
            'code' => 'hr_dashboard', 'parent_id' => null, 'label_ar' => 'الموارد البشرية والخدمات العامة', 'icon' => 'users',
            'route_name' => 'hr.dashboard', 'access_rule' => 'hr_dashboard', 'sort_order' => 27, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'navigation.item.created', 'subject_type' => 'navigation_items', 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => null, 'after' => ['code' => 'hr_dashboard', 'label_ar' => 'الموارد البشرية والخدمات العامة', 'access_rule' => 'hr_dashboard'],
                'source' => 'ترحيل 2026_11_15_000004 (لوحة الموارد البشرية والخدمات العامة)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('navigation_items')->where('code', 'hr_dashboard')->update(['is_active' => false, 'updated_at' => now()]);
    }
};
