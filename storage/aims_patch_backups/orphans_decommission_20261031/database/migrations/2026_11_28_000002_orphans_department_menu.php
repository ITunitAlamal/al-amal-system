<?php

use App\Services\AuditService;
use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «شؤون الأيتام والكفالات» menu: five task entries — «لوحة القسم»، «الأيتام والأوصياء»، «المراجعة والمتابعة»،
 * «الكفالات والمساعدات»، «التقارير والإحصائيات». «الحملات الإغاثية» opens from «الكفالات والمساعدات» (same page,
 * route and permission). Navigation display rows only: no schema, record or permission change. A label the
 * administrator already changed in «القائمة الرئيسية» is left as it is.
 */
return new class extends Migration
{
    /** code => [old label, new label] */
    private const LABELS = [
        'orphans_group' => ['الأيتام والأوصياء', 'شؤون الأيتام والكفالات'],
        'orphans_registry' => ['ملفات الأيتام والأسر', 'لوحة القسم'],
        'orphans_records' => ['السجلات', 'الأيتام والأوصياء'],
        'orphans_staging' => ['تدقيق البيانات', 'المراجعة والمتابعة'],
        'orphans_sponsorships' => ['الكفالات والتبرعات والمساعدات', 'الكفالات والمساعدات'],
    ];

    public function up(): void
    {
        $changed = DB::transaction(function (): array {
            $changed = [];
            foreach (self::LABELS as $code => [$old, $new]) {
                $changed[$code] = DB::table('navigation_items')->where('code', $code)->where('label_ar', $old)->update(['label_ar' => $new, 'updated_at' => now()]);
            }
            $group = DB::table('navigation_items')->where('code', 'orphans_group')->value('id');
            $changed['relief_campaigns_hidden'] = DB::table('navigation_items')->where('code', 'relief_campaigns')->where('parent_id', $group)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            return $changed;
        });
        // Adds «التقارير والإحصائيات» and points «الكفالات والمساعدات» at its route and rule.
        app(NavigationRegistry::class)->apply(null);
        app(AuditService::class)->recordRow('navigation.orphans.department_menu', 'navigation_items', 0, null, $changed, null, null);
    }

    public function down(): void
    {
        foreach (self::LABELS as $code => [$old, $new]) {
            DB::table('navigation_items')->where('code', $code)->where('label_ar', $new)->update(['label_ar' => $old, 'updated_at' => now()]);
        }
        DB::table('navigation_items')->where('code', 'relief_campaigns')->update(['is_active' => true, 'updated_at' => now()]);
        DB::table('navigation_items')->where('code', 'orphans_sponsorships')->update(['route_name' => 'orphans.sponsorships.index', 'access_rule' => 'orphans_sponsorships']);
        DB::table('navigation_items')->where('code', 'orphans_reports')->delete();
    }
};
