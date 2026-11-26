<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['finance_invoice_payments', 'finance_payment_allocations', 'finance_payroll_disbursements', 'finance_advance_disbursements', 'finance_advance_settlements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unsignedBigInteger('reversed_by_transaction_id')->nullable();
                $blueprint->foreign('reversed_by_transaction_id', substr($table, 8).'_reversal_fk')->references('id')->on('finance_transactions')->restrictOnDelete();
            });
        }
        Schema::table('finance_payroll_disbursements', fn (Blueprint $table) => $table->dropUnique(['payroll_item_id']));
        Schema::table('finance_advance_disbursements', fn (Blueprint $table) => $table->dropUnique(['advance_id']));
        DB::statement('CREATE UNIQUE INDEX finance_payroll_active_item_unique ON finance_payroll_disbursements (payroll_item_id) WHERE reversed_by_transaction_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX finance_advance_active_unique ON finance_advance_disbursements (advance_id) WHERE reversed_by_transaction_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Reversal history must be preserved; use a reviewed forward migration.');
    }
};
