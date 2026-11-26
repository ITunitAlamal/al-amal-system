<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;

/** Adds the «شؤون الأيتام والكفالات» group and its entries from the navigation registry (additive, no reset). */
return new class extends Migration
{
    public function up(): void
    {
        app(NavigationRegistry::class)->apply(null);
    }

    public function down(): void
    {
        // Menu rows are managed in «القائمة الرئيسية»; nothing to undo automatically.
    }
};
