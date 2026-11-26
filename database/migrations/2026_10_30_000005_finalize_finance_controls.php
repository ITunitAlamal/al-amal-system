<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_purchase_rules', function (Blueprint $t) {
            $t->id();
            $t->string('code', 70)->unique();
            $t->unsignedSmallInteger('min_quotes')->default(1);
            $t->decimal('threshold_ils', 18, 2)->default(0);
            $t->text('decision_reference')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_purchase_invoices');
            $t->foreignId('transaction_id')->unique()->constrained('finance_transactions');
            $t->decimal('amount', 18, 2);
            $t->timestamps();
        });
        Schema::table('finance_advance_settlements', function (Blueprint $t) {
            $t->foreignId('transaction_id')->nullable()->unique()->constrained('finance_transactions');
        });
        Schema::create('finance_reconciliation_resolutions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reconciliation_id')->unique()->constrained('finance_reconciliations');
            $t->text('reason');
            $t->string('reference', 150);
            $t->foreignId('resolved_by')->constrained('users');
            $t->timestamps();
        });
        Schema::table('finance_payroll_batches', function (Blueprint $t) {
            $t->string('hr_approval_reference', 150)->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
        });
        DB::table('finance_purchase_rules')->insert(['code' => 'default_quotes', 'min_quotes' => 1, 'threshold_ils' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.procurement.policy', 'finance.reversal.request', 'finance.reversal.approve'] as $p) {
            DB::table('permissions')->updateOrInsert(['code' => $p], ['name' => $p]);
        }
        $maps = ['finance_head' => ['finance.reversal.request'], 'finance_director_approver' => ['finance.reversal.approve'], 'finance_procurement' => []];
        foreach ($maps as $role => $codes) {
            $id = DB::table('roles')->where('code', $role)->value('id');
            if (! $id) {
                continue;
            }foreach ($codes as $c) {
                $pid = DB::table('permissions')->where('code', $c)->value('id');
                DB::table('permission_role')->updateOrInsert(['role_id' => $id, 'permission_id' => $pid], []);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Finance preservation: no automatic destructive rollback.');
    }
};
