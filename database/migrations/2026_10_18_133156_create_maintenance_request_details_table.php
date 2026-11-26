<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_request_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('aims_request_id')
                ->unique()
                ->constrained('aims_requests')
                ->restrictOnDelete();

            $table->string('location', 255);

            $table->string('fault_type', 100);

            $table->string('priority', 30)
                ->default('normal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_request_details');
    }
};
