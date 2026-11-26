<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One functional sidebar: groups named after the task (الموظفون، الدوام والإجازات، المشتريات والمخازن …) instead of
 * departments, the long accounts group split into four, and an optional role restriction per entry. Additive only:
 * entries keep their codes, no page or route is removed; only the six link-less headings of the old accounts group go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('navigation_items', 'audience_roles')) {
            Schema::table('navigation_items', function (Blueprint $table): void {
                $table->jsonb('audience_roles')->nullable();
            });
        }

        app(NavigationRegistry::class)->apply(null, true);
    }

    public function down(): void
    {
        if (Schema::hasColumn('navigation_items', 'audience_roles')) {
            Schema::table('navigation_items', function (Blueprint $table): void {
                $table->dropColumn('audience_roles');
            });
        }
    }
};
