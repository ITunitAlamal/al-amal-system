<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_budget_exceptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_line_id')->constrained('finance_budget_lines');
            $t->decimal('amount', 18, 2);
            $t->text('reason');
            $t->string('decision_reference', 150);
            $t->string('state', 24)->default('pending');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Finance data preservation: no automatic destructive rollback.');
    }
};
