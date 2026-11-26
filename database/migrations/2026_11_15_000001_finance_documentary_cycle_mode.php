<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts limited to the documentary cycle (owner decision 2026-10-23): the bookkeeping itself is done in the
 * association's accounting software «برنامج القلعة الأصيل». AIMS keeps the documents from the purchase request to
 * the supplier invoice, and records the number of the payment voucher issued in that software.
 *
 * - finance_module_settings: one row; accounting_mode "documentary" hides funds and banks, budgets, vouchers,
 *   payroll, cheques, transfers, reconciliation, restricted funds, receivables and advances from menus and
 *   permissions. Nothing is deleted; switching the mode to "full" brings them back.
 * - finance_purchase_invoices: the external voucher (number, date, who recorded it) that settles the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_module_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('accounting_mode', 20)->default('documentary');
            $table->string('external_system_name', 150)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE finance_module_settings ADD CONSTRAINT finance_module_settings_mode_check CHECK (accounting_mode IN ('documentary', 'full'))");
        DB::table('finance_module_settings')->insert([
            'id' => 1, 'accounting_mode' => 'documentary', 'external_system_name' => 'برنامج القلعة الأصيل',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('finance_purchase_invoices', function (Blueprint $table): void {
            $table->string('external_voucher_no', 80)->nullable();
            $table->date('external_voucher_on')->nullable();
            $table->foreignId('external_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('external_recorded_at')->nullable();
        });

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'finance.mode.changed', 'subject_type' => 'finance_module_settings', 'subject_id' => '1',
            'metadata' => json_encode(['before' => ['accounting_mode' => 'full'], 'after' => ['accounting_mode' => 'documentary', 'external_system_name' => 'برنامج القلعة الأصيل'],
                'source' => 'ترحيل 2026_11_15_000001 (قصر قسم الحسابات على الدورة المستندية بقرار المالك)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('finance_purchase_invoices')->whereNotNull('external_voucher_no')->exists()) {
            throw new RuntimeException('توجد فواتير مسددة بسندات من البرنامج المحاسبي الخارجي؛ لا يمكن التراجع دون ترحيلها.');
        }
        Schema::table('finance_purchase_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_recorded_by');
            $table->dropColumn(['external_voucher_no', 'external_voucher_on', 'external_recorded_at']);
        });
        Schema::dropIfExists('finance_module_settings');
    }
};
