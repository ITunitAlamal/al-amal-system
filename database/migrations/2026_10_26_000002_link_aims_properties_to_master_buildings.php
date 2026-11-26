<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aims_properties', function (Blueprint $table) {
            $table->foreignId('master_building_value_id')->nullable()
                ->unique('aims_properties_master_building_unique')
                ->constrained('master_data_values')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('aims_properties', function (Blueprint $table) {
            $table->dropForeign(['master_building_value_id']);
            $table->dropUnique('aims_properties_master_building_unique');
            $table->dropColumn('master_building_value_id');
        });
    }
};
