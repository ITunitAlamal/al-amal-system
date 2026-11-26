<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic fields (SRS CFG03, §85 "الحقول المرنة"). Values are stored in typed
     * columns so they stay indexable and reportable.
     */
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            // Registered entity key, e.g. "aims_request".
            $table->string('entity_type', 60);
            // Narrows the field to a sub-type, e.g. a workflow definition code. Empty = all.
            $table->string('scope_key', 100)->default('');
            // When set, the field is filled during this workflow step instead of at submission.
            $table->string('step_code', 100)->nullable();
            $table->string('code', 100);
            $table->string('label_ar', 200);
            $table->string('label_en', 200)->nullable();
            $table->string('help_text', 500)->nullable();
            // text, textarea, number, date, boolean, select
            $table->string('field_type', 30);
            $table->foreignId('master_data_type_id')->nullable()->constrained('master_data_types')->restrictOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_sensitive')->default(false);
            // {"min": .., "max": .., "max_length": ..}
            $table->json('validation')->nullable();
            // {"field": "code", "operator": "equals|not_equals|filled|empty", "value": ..}
            $table->json('visible_when')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['entity_type', 'scope_key', 'code'], 'custom_field_definition_code_unique');
            $table->index(['entity_type', 'scope_key', 'is_active'], 'custom_field_definition_lookup');
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('custom_field_definitions')->restrictOnDelete();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->foreignId('value_master_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['definition_id', 'entity_id'], 'custom_field_value_unique');
            $table->index(['entity_type', 'entity_id'], 'custom_field_value_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
