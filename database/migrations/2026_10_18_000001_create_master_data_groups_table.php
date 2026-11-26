<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'md_groups_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_groups');
    }
};
