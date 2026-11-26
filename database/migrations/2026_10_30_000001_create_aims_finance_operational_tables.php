<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_treasuries', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('kind', 20);
            $t->string('currency', 3);
            $t->string('bank_name')->nullable();
            $t->string('account_mask')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('finance_budget_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('fiscal_year');
            $t->foreignId('department_id')->constrained('departments');
            $t->string('code', 60);
            $t->string('title');
            $t->string('currency', 3);
            $t->decimal('approved_amount', 18, 2)->default(0);
            $t->decimal('adjustment_amount', 18, 2)->default(0);
            $t->unique(['fiscal_year', 'department_id', 'code', 'currency'], 'fin_budget_uniq');
            $t->timestamps();
        });
        Schema::create('finance_budget_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_line_id')->constrained('finance_budget_lines');
            $t->string('kind', 24);
            $t->decimal('amount', 18, 2);
            $t->string('state', 24)->default('draft');
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_sources', function (Blueprint $t) {
            $t->id();
            $t->string('source_module', 55);
            $t->string('source_id', 140);
            $t->string('event_type', 55);
            $t->jsonb('metadata')->nullable();
            $t->unique(['source_module', 'source_id', 'event_type'], 'fin_source_uniq');
            $t->timestamps();
        });
        Schema::create('finance_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('document_no', 50)->unique();
            $t->string('kind', 24);
            $t->string('state', 24)->default('draft');
            $t->foreignId('treasury_id')->constrained('finance_treasuries');
            $t->foreignId('budget_line_id')->nullable()->constrained('finance_budget_lines');
            $t->foreignId('source_id')->nullable()->constrained('finance_sources');
            $t->string('currency', 3);
            $t->decimal('amount', 18, 2);
            $t->date('effective_on');
            $t->string('counterparty')->nullable();
            $t->text('description')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->foreignId('executed_by')->nullable()->constrained('users');
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('finance_transactions');
            $t->timestamp('executed_at')->nullable();
            $t->index(['state', 'kind', 'effective_on']);
            $t->timestamps();
        });
        Schema::create('finance_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained('finance_transactions');
            $t->string('receivable_module', 55);
            $t->string('receivable_id', 140);
            $t->decimal('amount', 18, 2);
            $t->unique(['transaction_id', 'receivable_module', 'receivable_id'], 'fin_alloc_uniq');
            $t->timestamps();
        });
        Schema::create('finance_staff_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('specialization', 50);
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_active')->default(true);
            $t->foreignId('assigned_by')->constrained('users');
            $t->unique(['user_id', 'specialization'], 'fin_staff_uniq');
            $t->timestamps();
        });
        Schema::create('finance_purchase_policy', function (Blueprint $t) {
            $t->id();
            $t->string('code', 70)->unique();
            $t->decimal('threshold_ils', 18, 2)->default(500);
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_board_approvers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users');
            $t->boolean('is_active')->default(true);
            $t->foreignId('assigned_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('registration_no')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('finance_purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->string('number', 45)->unique();
            $t->foreignId('department_id')->constrained('departments');
            $t->foreignId('budget_line_id')->nullable()->constrained('finance_budget_lines');
            $t->string('currency', 3);
            $t->decimal('total_amount', 18, 2)->default(0);
            $t->decimal('evaluated_ils', 18, 2)->nullable();
            $t->string('state', 30)->default('draft');
            $t->text('purpose');
            $t->string('source_module', 55)->nullable();
            $t->string('source_id', 140)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->foreignId('director_approved_by')->nullable()->constrained('users');
            $t->foreignId('board_approved_by')->nullable()->constrained('users');
            $t->index(['source_module', 'source_id']);
            $t->timestamps();
        });
        Schema::create('finance_purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_request_id')->constrained('finance_purchase_requests');
            $t->string('item_name');
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 40);
            $t->decimal('unit_price', 18, 4)->default(0);
            $t->timestamps();
        });
        Schema::create('finance_purchase_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_request_id')->constrained('finance_purchase_requests');
            $t->foreignId('supplier_id')->constrained('finance_suppliers');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 3);
            $t->text('notes')->nullable();
            $t->boolean('selected')->default(false);
            $t->timestamps();
        });
        Schema::create('finance_purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 45)->unique();
            $t->foreignId('purchase_request_id')->constrained('finance_purchase_requests');
            $t->foreignId('supplier_id')->constrained('finance_suppliers');
            $t->unique('purchase_request_id', 'fin_one_order_per_purchase');
            $t->string('state', 24)->default('issued');
            $t->decimal('amount', 18, 2);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 45)->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('finance_stock_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 80)->unique();
            $t->string('name');
            $t->string('unit', 30);
            $t->decimal('minimum_qty', 18, 3)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('finance_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('finance_warehouses');
            $t->foreignId('stock_item_id')->constrained('finance_stock_items');
            $t->string('kind', 24);
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->string('reference_type', 55)->nullable();
            $t->string('reference_id', 140)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('finance_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('treasury_id')->constrained('finance_treasuries');
            $t->string('currency', 3);
            $t->decimal('amount', 18, 2);
            $t->string('state', 22)->default('draft');
            $t->date('due_on')->nullable();
            $t->text('purpose');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_advance_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_id')->constrained('finance_advances');
            $t->decimal('amount', 18, 2);
            $t->string('kind', 24);
            $t->text('reference')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_restricted_funds', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('purpose');
            $t->string('currency', 3);
            $t->decimal('received', 18, 2)->default(0);
            $t->decimal('committed', 18, 2)->default(0);
            $t->decimal('spent', 18, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('finance_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('treasury_id')->constrained('finance_treasuries');
            $t->date('as_of');
            $t->decimal('expected', 18, 2);
            $t->decimal('actual', 18, 2);
            $t->string('state', 25)->default('open');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('finance_payroll_batches', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7);
            $t->string('currency', 3);
            $t->string('state', 25)->default('draft');
            $t->decimal('total', 18, 2)->default(0);
            $t->foreignId('created_by')->constrained('users');
            $t->unique(['period', 'currency']);
            $t->timestamps();
        });
        Schema::create('finance_payroll_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('finance_payroll_batches');
            $t->foreignId('employee_id')->constrained('employees');
            $t->decimal('gross', 18, 2);
            $t->decimal('deductions', 18, 2)->default(0);
            $t->decimal('net', 18, 2);
            $t->string('state', 25)->default('pending');
            $t->unique(['batch_id', 'employee_id']);
            $t->timestamps();
        });
        Schema::create('finance_audit_events', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 60);
            $t->string('entity_id', 70);
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action', 70);
            $t->jsonb('detail')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_events');
        Schema::dropIfExists('finance_payroll_items');
        Schema::dropIfExists('finance_payroll_batches');
        Schema::dropIfExists('finance_reconciliations');
        Schema::dropIfExists('finance_restricted_funds');
        Schema::dropIfExists('finance_advance_settlements');
        Schema::dropIfExists('finance_advances');
        Schema::dropIfExists('finance_stock_movements');
        Schema::dropIfExists('finance_stock_items');
        Schema::dropIfExists('finance_warehouses');
        Schema::dropIfExists('finance_purchase_orders');
        Schema::dropIfExists('finance_purchase_quotes');
        Schema::dropIfExists('finance_purchase_items');
        Schema::dropIfExists('finance_purchase_requests');
        Schema::dropIfExists('finance_suppliers');
        Schema::dropIfExists('finance_board_approvers');
        Schema::dropIfExists('finance_purchase_policy');
        Schema::dropIfExists('finance_staff_assignments');
        Schema::dropIfExists('finance_payment_allocations');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_sources');
        Schema::dropIfExists('finance_budget_movements');
        Schema::dropIfExists('finance_budget_lines');
        Schema::dropIfExists('finance_treasuries');
    }
};
