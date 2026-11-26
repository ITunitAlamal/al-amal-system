<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All tables below are new: the existing AIMS schema and records are never modified destructively.
        Schema::create('finance_receivables', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 100)->unique();
            $t->string('source_module', 60)->default('manual');
            $t->string('source_reference', 140)->nullable();
            $t->string('party', 255);
            $t->string('currency', 3);
            $t->decimal('amount', 18, 2);
            $t->date('due_on');
            $t->string('kind', 40)->default('other');
            $t->text('description')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['source_module', 'source_reference'], 'fin_rec_source_uniq');
        });
        Schema::create('finance_restricted_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fund_id')->constrained('finance_restricted_funds');
            $t->string('kind', 24);
            $t->decimal('amount', 18, 2);
            $t->string('reference', 120);
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('state', 24)->default('pending');
            $t->unique(['fund_id', 'kind', 'reference'], 'fin_restricted_unique');
            $t->timestamps();
        });
        Schema::create('finance_purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('finance_purchase_orders');
            $t->string('number', 60)->unique();
            $t->string('state', 24)->default('received');
            $t->text('notes')->nullable();
            $t->foreignId('received_by')->constrained('users');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_purchase_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained('finance_purchase_receipts');
            $t->foreignId('purchase_item_id')->constrained('finance_purchase_items');
            $t->foreignId('warehouse_id')->constrained('finance_warehouses');
            $t->foreignId('stock_item_id')->constrained('finance_stock_items');
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_cost', 18, 4);
            $t->timestamps();
        });
        Schema::create('finance_purchase_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('finance_purchase_orders');
            $t->string('invoice_no', 80);
            $t->decimal('amount', 18, 2);
            $t->string('currency', 3);
            $t->date('invoice_on');
            $t->string('state', 24)->default('pending');
            $t->foreignId('payment_id')->nullable()->unique()->constrained('finance_transactions');
            $t->foreignId('created_by')->constrained('users');
            $t->unique(['order_id', 'invoice_no']);
            $t->timestamps();
        });
        Schema::create('finance_cheques', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->unique()->constrained('finance_transactions');
            $t->string('cheque_no', 80);
            $t->string('bank_name');
            $t->date('due_on');
            $t->string('state', 24)->default('received');
            $t->foreignId('updated_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_payroll_disbursements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_item_id')->unique()->constrained('finance_payroll_items');
            $t->foreignId('transaction_id')->unique()->constrained('finance_transactions');
            $t->timestamps();
        });
        Schema::create('finance_inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('finance_warehouses');
            $t->foreignId('stock_item_id')->constrained('finance_stock_items');
            $t->decimal('recorded_qty', 18, 3);
            $t->decimal('physical_qty', 18, 3);
            $t->string('state', 24)->default('pending');
            $t->text('reason');
            $t->foreignId('counted_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_budget_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_line_id')->constrained('finance_budget_lines');
            $t->foreignId('to_line_id')->constrained('finance_budget_lines');
            $t->decimal('amount', 18, 2);
            $t->string('state', 24)->default('pending');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::table('finance_internal_transfers', function (Blueprint $t) {
            $t->string('state', 24)->default('draft');
            $t->foreignId('approved_by')->nullable()->constrained('users');
        });
        Schema::table('finance_purchase_requests', function (Blueprint $t) {
            $t->foreignId('valuation_rate_id')->nullable()->constrained('exchange_rates');
        });
        DB::table('finance_purchase_policy')->updateOrInsert(['code' => 'board_treasurer_purchase_threshold'], ['threshold_ils' => '500.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.budget.transfer', 'finance.budget.approve', 'finance.receivable.manage', 'finance.cheque.manage', 'finance.purchase.receive', 'finance.purchase.invoice', 'finance.stock.count.approve', 'finance.payroll.approve', 'finance.fund.approve', 'finance.transfer.approve', 'finance.report.export'] as $p) {
            DB::table('permissions')->updateOrInsert(['code' => $p], ['name' => $p]);
        }
        $roleMap = ['finance_head' => ['finance.budget.transfer', 'finance.reports.view', 'finance.stock.count.approve'], 'finance_accountant' => ['finance.receivable.manage', 'finance.cheque.manage', 'finance.purchase.invoice', 'finance.report.export'], 'finance_warehouse' => ['finance.purchase.receive'], 'finance_director_approver' => ['finance.budget.approve', 'finance.payroll.approve', 'finance.fund.approve', 'finance.transfer.approve']];
        foreach ($roleMap as $role => $codes) {
            $id = DB::table('roles')->where('code', $role)->value('id');
            if (! $id) {
                continue;
            }foreach ($codes as $code) {
                $pid = DB::table('permissions')->where('code', $code)->value('id');
                DB::table('permission_role')->updateOrInsert(['role_id' => $id, 'permission_id' => $pid], []);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Finance data preservation: automatic rollback deliberately disabled. Restore a reviewed backup.');
    }
};
