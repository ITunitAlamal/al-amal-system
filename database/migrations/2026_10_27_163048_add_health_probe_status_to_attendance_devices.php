<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table): void {
            $table->boolean('probe_enabled')->default(false);
            $table->unsignedSmallInteger('probe_interval_seconds')->default(30);
            $table->string('assigned_agent', 100)->default('local');
            $table->timestampTz('last_probe_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->unsignedInteger('probe_latency_ms')->nullable();
            $table->string('probe_result', 20)->nullable();
            $table->string('probe_error_code', 80)->nullable();
            $table->timestampTz('agent_last_seen_at')->nullable();
            $table->timestampTz('last_sync_success_at')->nullable();
            $table->timestampTz('probe_requested_at')->nullable();
            $table->timestampTz('probe_started_at')->nullable();
            $table->uuid('probe_lock_token')->nullable();
            $table->index(['probe_enabled', 'assigned_agent']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table): void {
            $table->dropIndex(['probe_enabled', 'assigned_agent']);
            $table->dropColumn([
                'probe_enabled',
                'probe_interval_seconds',
                'assigned_agent',
                'last_probe_at',
                'last_success_at',
                'probe_latency_ms',
                'probe_result',
                'probe_error_code',
                'agent_last_seen_at',
                'last_sync_success_at',
                'probe_requested_at',
                'probe_started_at',
                'probe_lock_token',
            ]);
        });
    }
};
