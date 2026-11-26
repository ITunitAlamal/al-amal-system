<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['employee_addresses', 'aims_properties', 'aims_rental_tenants'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'] as $field) {
                    $table->foreignId($field)->nullable()->constrained('master_data_values')->restrictOnDelete();
                    $table->index($field);
                }
                $table->boolean('address_other')->default(false);
                if ($name !== 'employee_addresses') {
                    $table->text('address_details')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Geographic references may be in use; roll back application code without dropping historical address links.');
    }
};
