<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orphan_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('operation', 10);
            $table->json('capabilities');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
        });
        Schema::create('orphan_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('capability', 30);
            $table->boolean('is_active')->default(true);
            $table->foreignId('access_request_id')->constrained('orphan_access_requests')->restrictOnDelete();
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'user_id', 'capability'], 'orphan_staff_capability_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Delegation history must be preserved; use a reviewed forward migration.');
    }
};
