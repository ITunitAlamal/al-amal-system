<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links a parent to each family file they belong to. A father with children in two households (two mothers) is
 * one person row linked twice, never copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orphan_household_parents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('parent_id')->constrained('orphan_parents')->restrictOnDelete();
            $t->foreignId('linked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['household_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('orphan_household_parents')->exists()) {
            throw new RuntimeException('توجد روابط آباء وأسر مسجلة؛ لا يُتراجع عن الترحيل.');
        }
        Schema::dropIfExists('orphan_household_parents');
    }
};
