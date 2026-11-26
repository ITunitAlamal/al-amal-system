<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('location')->nullable();
            $t->string('host', 253);
            $t->unsignedSmallInteger('port')->default(4370);
            $t->string('protocol', 8)->default('auto');
            $t->boolean('is_active')->default(false);
            $t->timestampTz('last_synced_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_schedules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('attendance_schedule_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('attendance_schedules')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->time('start_time');
            $t->time('end_time');
            $t->boolean('ends_next_day')->default(false);
            $t->unique(['schedule_id', 'weekday']);
        });
        Schema::create('attendance_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('attendance_schedules')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('status', 20)->default('pending');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_overrides', function (Blueprint $t) {
            $t->id();
            $t->string('label');
            $t->string('kind', 20);
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->boolean('ends_next_day')->default(false);
            $t->string('status', 20)->default('pending');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_employee_device_ids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('attendance_devices')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('device_user_id', 100);
            $t->unique(['device_id', 'device_user_id']);
            $t->unique(['device_id', 'employee_id']);
        });
        Schema::create('attendance_punches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('device_id')->nullable()->constrained('attendance_devices')->restrictOnDelete();
            $t->string('source', 12);
            $t->string('direction', 12);
            $t->timestampTz('occurred_at');
            $t->string('source_key', 180)->unique();
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'occurred_at']);
        });
        Schema::create('attendance_audit_events', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->string('action');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->jsonb('details')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['attendance_audit_events', 'attendance_punches', 'attendance_employee_device_ids', 'attendance_overrides', 'attendance_assignments', 'attendance_schedule_days', 'attendance_schedules', 'attendance_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
