<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orphan_import_rows', function (Blueprint $table): void {
            $table->text('corrections_payload')->nullable();
            $table->unsignedInteger('correction_version')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orphan_import_rows')->where('correction_version', '>', 0)->exists()) {
            throw new RuntimeException('Corrections exist: retain these columns and roll back application code only.');
        }
        Schema::table('orphan_import_rows', function (Blueprint $table): void {
            $table->dropColumn(['corrections_payload', 'correction_version']);
        });
    }
};
