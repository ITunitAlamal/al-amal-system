<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An amendment is a new version that waits for the executive director beside the active one, so the duplicate guard
 * skips it (one open amendment per sponsorship instead). Adds the lateness grace period (a display flag only, the
 * executive director's policy value) and the two menu entries of the sponsorships screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS sponsorships_no_duplicate');
        DB::statement("CREATE UNIQUE INDEX sponsorships_no_duplicate ON sponsorships (sponsor_id, household_id, COALESCE(orphan_child_id, 0))
            WHERE status IN ('active','paused') OR (status = 'pending_approval' AND supersedes_id IS NULL)");
        DB::statement("CREATE UNIQUE INDEX sponsorships_one_open_amendment ON sponsorships (supersedes_id) WHERE supersedes_id IS NOT NULL AND status IN ('draft','pending_approval')");

        if (! Schema::hasColumn('orphans_module_settings', 'sponsorship_grace_days')) {
            Schema::table('orphans_module_settings', function (Blueprint $t): void {
                $t->unsignedSmallInteger('sponsorship_grace_days')->default(30);
            });
        }

        app(NavigationRegistry::class)->apply(null);
    }

    public function down(): void
    {
        if (DB::table('sponsorships')->whereNotNull('supersedes_id')->whereIn('status', ['pending_approval', 'active', 'paused'])->exists()) {
            throw new RuntimeException('توجد تعديلات كفالات قائمة؛ لا يُعاد الفهرس القديم.');
        }
        DB::table('navigation_items')->whereIn('code', ['orphans_sponsorships', 'finance_sponsorships'])->delete();
        if (Schema::hasColumn('orphans_module_settings', 'sponsorship_grace_days')) {
            Schema::table('orphans_module_settings', function (Blueprint $t): void {
                $t->dropColumn('sponsorship_grace_days');
            });
        }
        DB::statement('DROP INDEX IF EXISTS sponsorships_one_open_amendment');
        DB::statement('DROP INDEX IF EXISTS sponsorships_no_duplicate');
        DB::statement("CREATE UNIQUE INDEX sponsorships_no_duplicate ON sponsorships (sponsor_id, household_id, COALESCE(orphan_child_id, 0)) WHERE status IN ('pending_approval','active','paused')");
    }
};
