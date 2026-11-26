<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds only the menu entries needed to reach the new orphans and relief-campaign screens, inside the existing
 * «الأيتام والأوصياء» group; the rest of the sidebar is untouched. Visibility follows the services' own checks.
 */
return new class extends Migration
{
    private const CODES = ['orphans_registry', 'relief_campaigns', 'relief_devices', 'orphans_settings'];

    public function up(): void
    {
        app(NavigationRegistry::class)->apply(null);
    }

    public function down(): void
    {
        DB::table('navigation_items')->whereIn('code', self::CODES)->delete();
    }
};
