<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_auto_corrections')) {
            return;
        }
        Schema::create('attendance_auto_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punch_id')->constrained('attendance_punches')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('original_direction', 20);
            $table->string('corrected_direction', 20);
            $table->string('status', 20)->default('applied');
            $table->string('rule_code', 80);
            $table->string('rule_version', 20)->default('1');
            $table->text('reason_ar');
            $table->jsonb('evidence');
            $table->timestampTz('applied_at');
            $table->foreignId('reverted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversion_reason')->nullable();
            $table->timestampTz('reverted_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX attendance_auto_one_active_punch ON attendance_auto_corrections(punch_id) WHERE status = 'applied'");
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic correction history is retained; manual retention review required.');
    }
};
