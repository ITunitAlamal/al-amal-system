<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly shift roster «جدول المناوبات» (owner decisions 2026-11-02): a department head plans each employee of the
 * department day by day, effective at once, audited, without another approval. Additive only.
 *
 * - attendance_shift_types: named shifts (e.g. «صباحي 08–16»، «ليلي 18–08»). Organization-wide ones are kept by the
 *   HR head; a department's own ones are kept by its head. Deactivated, never deleted.
 * - attendance_roster_entries: one plan per employee and day (a shift or a rest day). The times are copied from the
 *   shift type when chosen, so a later edit of the type never rewrites a planned or past day.
 *   AttendanceCalendar reads it above general hours and below the employee's own exceptions and swaps.
 * - notification event «attendance_roster_changed» for the employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_shift_types', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->string('name', 60);
            $t->time('start_time');
            $t->time('end_time');
            $t->boolean('ends_next_day')->default(false);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE attendance_shift_types ADD CONSTRAINT attendance_shift_types_times CHECK (ends_next_day <> (end_time > start_time))');
        DB::statement('CREATE UNIQUE INDEX attendance_shift_types_name ON attendance_shift_types (COALESCE(department_id, 0), name)');

        Schema::create('attendance_roster_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('work_date');
            $t->string('kind', 10);
            $t->foreignId('shift_type_id')->nullable()->constrained('attendance_shift_types')->restrictOnDelete();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->boolean('ends_next_day')->default(false);
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
            $t->index('work_date');
        });
        // A shift has a start and an end (at most 24 hours: an overnight shift ends before its start time); a rest day has none.
        DB::statement("ALTER TABLE attendance_roster_entries ADD CONSTRAINT attendance_roster_entries_kind CHECK (
            (kind = 'off' AND start_time IS NULL AND end_time IS NULL AND shift_type_id IS NULL)
            OR (kind = 'shift' AND start_time IS NOT NULL AND end_time IS NOT NULL AND ends_next_day <> (end_time > start_time)))");

        DB::table('notification_settings')->insertOrIgnore(['event_code' => 'attendance_roster_changed', 'label_ar' => 'تحديث جدول مناوباتك',
            'description' => 'للموظف عندما يعدّل رئيس قسمه جدول مناوباته الشهري.', 'in_app_enabled' => true, 'sms_enabled' => false,
            'created_at' => now(), 'updated_at' => now()]);

        // Menu entries «جدول المناوبات» (الإدارة والمتابعة) and «دوامي الشهري» (خدماتي): adds missing entries only.
        app(NavigationRegistry::class)->apply(null);
    }

    public function down(): void
    {
        if (DB::table('attendance_roster_entries')->exists() || DB::table('attendance_shift_types')->exists()) {
            throw new RuntimeException('توجد مناوبات أو أنواع مناوبات مسجلة؛ لا يمكن التراجع.');
        }
        Schema::drop('attendance_roster_entries');
        Schema::drop('attendance_shift_types');
        DB::table('notification_settings')->where('event_code', 'attendance_roster_changed')->delete();
    }
};
