<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_internal_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 80)->unique();
            $t->foreignId('from_treasury_id')->constrained('finance_treasuries');
            $t->foreignId('to_treasury_id')->constrained('finance_treasuries');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 3);
            $t->foreignId('out_transaction_id')->nullable()->constrained('finance_transactions');
            $t->foreignId('in_transaction_id')->nullable()->constrained('finance_transactions');
            $t->foreignId('executed_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_advance_disbursements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_id')->unique()->constrained('finance_advances');
            $t->foreignId('transaction_id')->unique()->constrained('finance_transactions');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_advance_disbursements');
        Schema::dropIfExists('finance_internal_transfers');
    }
};
