<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_material_addenda', function (Blueprint $table) {
            $table->id();

            $table->uuid('public_id')->unique();

            $table->foreignId('aims_request_id')
                ->constrained('aims_requests')
                ->restrictOnDelete();

            $table->unsignedInteger('addendum_number');

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            // supervisor / hr_head
            $table->string('requester_capacity', 30);

            $table->text('justification');

            // draft / submitted / under_review /
            // approved / rejected / cancelled
            $table->string('status', 30)->default('draft');

            $table->unsignedInteger('revision')->default(1);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['aims_request_id', 'addendum_number'],
                'maintenance_addendum_number_unique'
            );

            $table->index(
                ['aims_request_id', 'status'],
                'maintenance_addendum_request_status_index'
            );
        });

        Schema::create('maintenance_material_addendum_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_material_addendum_id')
                ->constrained('maintenance_material_addenda')
                ->restrictOnDelete();

            $table->string('material_name', 255);

            $table->text('specification')->nullable();

            $table->string('unit', 40);

            $table->decimal('requested_quantity', 15, 3);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                'maintenance_material_addendum_id',
                'maintenance_addendum_items_parent_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_material_addendum_items');
        Schema::dropIfExists('maintenance_material_addenda');
    }
};
