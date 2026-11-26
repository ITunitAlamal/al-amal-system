<?php

use App\Services\AuditService;
use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «الأيتام والأوصياء» menu: nine entries become five. Adds «السجلات»; «استيراد الكشوف» and «طلبات الأوصياء» are
 * hidden from the menu (their pages, routes and permissions are unchanged and linked from inside the department's
 * pages); the department's three settings pages move to «الإعدادات والصلاحيات».
 *
 * Navigation display rows only — no schema change, no record or permission change. An entry the administrator has
 * already moved or hidden from «القائمة الرئيسية» is left as it is.
 */
return new class extends Migration
{
    private const HIDE = ['orphans_imports', 'orphans_guardians'];

    private const MOVE = ['orphans_delegations' => 160, 'relief_devices' => 170, 'orphans_settings' => 180];

    public function up(): void
    {
        app(NavigationRegistry::class)->apply(null);
        $ids = DB::table('navigation_items')->whereIn('code', ['orphans_group', 'system_settings'])->pluck('id', 'code');
        $changed = [];
        DB::transaction(function () use ($ids, &$changed): void {
            $changed['hidden'] = DB::table('navigation_items')->whereIn('code', self::HIDE)->where('parent_id', $ids['orphans_group'])->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
            $changed['moved'] = 0;
            foreach (self::MOVE as $code => $sort) {
                $changed['moved'] += DB::table('navigation_items')->where('code', $code)->where('parent_id', $ids['orphans_group'])
                    ->update(['parent_id' => $ids['system_settings'], 'sort_order' => $sort, 'updated_at' => now()]);
            }
            DB::table('navigation_items')->where('code', 'orphans_records')->update(['sort_order' => 6]);
            DB::table('navigation_items')->where('code', 'orphans_staging')->where('parent_id', $ids['orphans_group'])->update(['sort_order' => 7]);
        });
        app(AuditService::class)->recordRow('navigation.orphans.consolidated', 'navigation_items', 0, null, $changed, null, null);
    }

    public function down(): void
    {
        $ids = DB::table('navigation_items')->whereIn('code', ['orphans_group', 'system_settings'])->pluck('id', 'code');
        DB::table('navigation_items')->whereIn('code', self::HIDE)->where('parent_id', $ids['orphans_group'])->update(['is_active' => true, 'updated_at' => now()]);
        foreach (['orphans_delegations' => 20, 'relief_devices' => 40, 'orphans_settings' => 90] as $code => $sort) {
            DB::table('navigation_items')->where('code', $code)->where('parent_id', $ids['system_settings'])->update(['parent_id' => $ids['orphans_group'], 'sort_order' => $sort, 'updated_at' => now()]);
        }
        DB::table('navigation_items')->where('code', 'orphans_records')->delete();
    }
};
