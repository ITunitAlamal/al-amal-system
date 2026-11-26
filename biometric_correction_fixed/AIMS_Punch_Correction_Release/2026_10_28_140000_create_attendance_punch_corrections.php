<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punch_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punch_id')->constrained('attendance_punches')->restrictOnDelete();
            $table->string('original_direction', 20);
            $table->string('corrected_direction', 20);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['punch_id', 'status']);
        });
        // PostgreSQL partial unique indexes enforce one pending and one approved correction per punch.
        DB::statement("CREATE UNIQUE INDEX attendance_punch_correction_pending_one ON attendance_punch_corrections (punch_id) WHERE status = 'pending'");
        DB::statement("CREATE UNIQUE INDEX attendance_punch_correction_approved_one ON attendance_punch_corrections (punch_id) WHERE status = 'approved'");
    }

    public function down(): void
    {
        // The correction history is auditable employment data: never drop it automatically.
        throw new RuntimeException('Manual retention review required before dropping attendance corrections.');
    }
};
