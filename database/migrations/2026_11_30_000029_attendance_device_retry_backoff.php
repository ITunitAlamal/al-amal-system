<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retry backoff for unreachable attendance devices (TRANSFORM §21: no silent failures, safe retries and limits).
 *
 * - attendance_devices: how many sync attempts and how many health probes failed in a row, when the last sync
 *   attempt failed and when the next automatic attempt is due. attendance:sync and attendance:probe-devices wait
 *   longer after each failure (capped at 15 minutes) instead of retrying every minute; an operator's "sync now"
 *   or "probe now" made after the last failure still runs at once.
 * - attendance_sync_runs.attempts: a failure streak with the same error is one row whose attempts grow, instead of
 *   one row per minute.
 *
 * Columns only, all nullable or defaulted, so existing rows are untouched. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_devices', 'sync_failure_count')) {
                $table->unsignedInteger('sync_failure_count')->default(0);
            }
            if (! Schema::hasColumn('attendance_devices', 'sync_failed_at')) {
                $table->timestampTz('sync_failed_at')->nullable();
            }
            if (! Schema::hasColumn('attendance_devices', 'sync_retry_at')) {
                $table->timestampTz('sync_retry_at')->nullable();
            }
            if (! Schema::hasColumn('attendance_devices', 'probe_failure_count')) {
                $table->unsignedInteger('probe_failure_count')->default(0);
            }
            if (! Schema::hasColumn('attendance_devices', 'probe_retry_at')) {
                $table->timestampTz('probe_retry_at')->nullable();
            }
        });
        Schema::table('attendance_sync_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_sync_runs', 'attempts')) {
                $table->unsignedInteger('attempts')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sync_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_sync_runs', 'attempts')) {
                $table->dropColumn('attempts');
            }
        });
        Schema::table('attendance_devices', function (Blueprint $table): void {
            foreach (['sync_failure_count', 'sync_failed_at', 'sync_retry_at', 'probe_failure_count', 'probe_retry_at'] as $column) {
                if (Schema::hasColumn('attendance_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
