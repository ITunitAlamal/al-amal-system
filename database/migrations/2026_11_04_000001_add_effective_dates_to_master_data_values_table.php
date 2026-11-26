<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Effective dating for reference values (SRS §80): a value can be scheduled
     * to start or stop being selectable without deleting it.
     */
    public function up(): void
    {
        Schema::table('master_data_values', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('master_data_values', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
