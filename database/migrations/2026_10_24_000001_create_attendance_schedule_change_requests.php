<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_schedule_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('attendance_schedules')->restrictOnDelete();
            $t->string('action', 24);
            $t->jsonb('payload');
            $t->string('status', 16)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->timestamps();
            $t->index(['schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_schedule_change_requests');
    }
};
