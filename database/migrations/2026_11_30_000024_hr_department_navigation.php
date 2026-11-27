<?php

use App\Services\AuditService;
use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sidebar of the unified department (HR, general services and maintenance). Menu rows only: no table changes.
 *
 * - «متابعة القسم» (code "hr_dashboard", page /hr-services) is a working page again under that name. Its row stays
 *   in «الموارد البشرية», shown by its own rule "hr_dashboard" to whoever the page accepts (the department's head;
 *   the executive director and the system administrator read it); the department's own people see it at the top
 *   level through NavigationPriorities::LIFTED, without moving the row.
 * - A group «الخدمات العامة والصيانة» with «طلبات الخدمات العامة» and «طلبات الصيانة»: the same page with its type
 *   filter set, under the same rule.
 * - «مهامي الشخصية» becomes «قائمتي الشخصية» (the private to-do list, often taken for «مهامي») and «الإجازات والموافقات»
 *   becomes «الإجازات».
 * - The inactive row «مسودة طلب صيانة» goes: its screen was removed, and the old address opens a new maintenance
 *   request instead.
 *
 * The rows come from NavigationRegistry (its apply() adds what is missing and drops the retired row). A name is
 * changed only while it is still a default the system installed, and no row is moved, so whatever the administrator
 * arranged in «القائمة الرئيسية» is kept. «طلباتي» stays inside «خدماتي» for everyone; the people of this
 * department see it at the top through NavigationPriorities::LIFTED.
 * Safe to run twice: the second run finds nothing to change.
 */
return new class extends Migration
{
    /**
     * code => [names the system installed for the entry, its name now].
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const RELABELS = [
        'hr_dashboard' => [['متابعة الموظفين والخدمات', 'لوحة الموارد البشرية والخدمات', 'الموارد البشرية والخدمات العامة'], 'متابعة القسم'],
        'my_todo' => [['مهامي الشخصية'], 'قائمتي الشخصية'],
        'leaves_requests' => [['الإجازات والموافقات'], 'الإجازات'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            app(NavigationRegistry::class)->apply(null);
            $audit = app(AuditService::class);
            $now = now();

            foreach (self::RELABELS as $code => [$installed, $label]) {
                $row = DB::table('navigation_items')->where('code', $code)->first(['id', 'label_ar']);
                if ($row && in_array($row->label_ar, $installed, true)) {
                    DB::table('navigation_items')->where('id', $row->id)->update(['label_ar' => $label, 'updated_at' => $now]);
                    $audit->recordRow('navigation.item.updated', 'navigation_items', (int) $row->id, ['label_ar' => $row->label_ar], ['code' => $code, 'label_ar' => $label], null);
                }
            }
        });
    }

    /**
     * Menu rows are managed in «القائمة الرئيسية», where the administrator can rename, move or hide any of them;
     * nothing is undone automatically.
     */
    public function down(): void
    {
        // Nothing to undo.
    }
};
