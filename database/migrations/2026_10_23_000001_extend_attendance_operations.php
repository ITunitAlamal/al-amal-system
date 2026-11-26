<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $t) {
            $t->string('sync_mode', 20)->default('disabled');
            $t->string('remote_user', 100)->nullable();
        });
        Schema::create('attendance_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('attendance_devices')->restrictOnDelete();
            $t->string('status', 20);
            $t->unsignedInteger('received')->default(0);
            $t->unsignedInteger('inserted')->default(0);
            $t->text('error')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('ended_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_daily_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('work_date');
            $t->timestampTz('expected_in')->nullable();
            $t->timestampTz('expected_out')->nullable();
            $t->timestampTz('actual_in')->nullable();
            $t->timestampTz('actual_out')->nullable();
            $t->unsignedInteger('late_minutes')->nullable();
            $t->unsignedInteger('early_minutes')->nullable();
            $t->string('status', 32);
            $t->unsignedInteger('grace_minutes')->nullable();
            $t->jsonb('explanation')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
        });
        // Extend existing HR constants without creating another master-data group or inventing an approved grace value.
        $hr = DB::table('master_data_groups')->where('code', 'hr')->value('id');
        if (! $hr) {
            throw new RuntimeException('HR master-data group not found; migration aborted.');
        }
        DB::table('master_data_types')->updateOrInsert(['code' => 'attendance_grace_minutes'], [
            'group_id' => $hr, 'name_ar' => 'فترة السماح بالتأخير (دقائق)', 'name_en' => 'Attendance grace in minutes',
            'description' => 'فعّل قيمة واحدة بكود min_عدد_الدقائق فقط في code مثل min_10. لا توجد قيمة افتراضية حتى تعتمد HR السياسة.',
            'is_active' => true, 'sort_order' => 900, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_results');
        Schema::dropIfExists('attendance_sync_runs');
        Schema::table('attendance_devices', fn (Blueprint $t) => $t->dropColumn(['sync_mode', 'remote_user']));
        // Preserve manually managed master data.
    }
};
