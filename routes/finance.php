<?php

use App\Http\Controllers\Finance\DepartmentBudgetController;
use App\Http\Controllers\Finance\FinanceCompletionController as C;
use App\Http\Controllers\Finance\FinanceDocumentCycleController as D;
use App\Http\Controllers\Finance\FinanceExtensionsController as E;
use App\Http\Controllers\Finance\FinanceOperationsController as F;
use App\Http\Controllers\Finance\FinancePayrollController;
use App\Http\Controllers\Finance\FinanceProcurementController as P;
use App\Http\Controllers\Finance\FinanceRentalsController;
use App\Http\Controllers\Finance\FinanceReportsController;
use App\Http\Controllers\Finance\FinanceSetupController;
use App\Http\Controllers\Finance\FinanceStaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('finance')->name('finance.')->group(function () {
    Route::get('/', [F::class, 'index'])->name('index');
    Route::get('/department-budgets', [DepartmentBudgetController::class, 'index'])->name('department-budgets.index');
    Route::post('/department-budgets', [DepartmentBudgetController::class, 'store'])->name('department-budgets.store');
    Route::patch('/department-budgets/{id}', [DepartmentBudgetController::class, 'update'])->whereNumber('id')->name('department-budgets.update');
    Route::get('/approvals', [C::class, 'approvals'])->name('approvals');
    Route::get('/bootstrap', [C::class, 'bootstrap'])->name('bootstrap');
    Route::get('/cycle', [D::class, 'cycle'])->name('cycle');
    Route::get('/archive', [D::class, 'archive'])->name('archive');
    Route::post('/invoices/{id}/external-voucher', [D::class, 'externalVoucher'])->whereNumber('id')->name('invoice.external-voucher');
    Route::get('/sponsorships', [D::class, 'sponsorships'])->name('sponsorships');
    Route::post('/sponsorships/receipts', [D::class, 'sponsorshipReceipt'])->name('sponsorships.receipts');
    Route::post('/sponsorships/receipts/{id}/reverse', [D::class, 'reverseSponsorshipReceipt'])->whereNumber('id')->name('sponsorships.receipts.reverse');
    Route::get('/rentals', [FinanceRentalsController::class, 'index'])->name('rentals');
    Route::post('/rentals/receipts', [FinanceRentalsController::class, 'store'])->name('rentals.receipts');
    Route::post('/rentals/receipts/{id}/reverse', [FinanceRentalsController::class, 'reverse'])->whereNumber('id')->name('rentals.receipts.reverse');
    Route::get('/catalog/{module}', [F::class, 'browse'])->name('browse');
    Route::post('/catalog/{module}', [F::class, 'create'])->name('create');
    Route::get('/records/{module}', [F::class, 'records'])->name('records');
    Route::post('/purchases', [F::class, 'purchase'])->name('purchase.create');
    Route::get('/purchases/{id}', [P::class, 'show'])->whereNumber('id')->name('purchase.show');
    Route::post('/purchases/{id}/quotes', [P::class, 'quote'])->whereNumber('id')->name('purchase.quote');
    Route::post('/purchases/{id}/quotes/{quoteId}/select', [P::class, 'selectQuote'])->whereNumber('id')->name('purchase.quote.select');
    Route::post('/purchases/{id}/orders', [P::class, 'issueOrder'])->whereNumber('id')->name('purchase.order');
    Route::post('/purchases/{id}/valuation', [C::class, 'purchaseValuation'])->whereNumber('id')->name('purchase.valuation');
    Route::post('/purchases/{id}/items', [F::class, 'purchaseLines'])->whereNumber('id')->name('purchase.items');
    Route::post('/purchases/{id}/decision/{decision}', [F::class, 'purchaseDecision'])->whereNumber('id')->whereIn('decision', ['reject', 'return'])->name('purchase.decision');
    Route::post('/purchases/{id}/cancel', [F::class, 'purchaseCancel'])->whereNumber('id')->middleware('throttle:20,1')->name('purchase.cancel');
    Route::post('/purchases/{id}/{action}', [F::class, 'purchaseAction'])->whereNumber('id')->whereIn('action', ['submit', 'review', 'director', 'board'])->name('purchase.action');
    Route::post('/purchase-rules', [C::class, 'purchaseRules'])->name('purchase.rules');
    Route::post('/orders/{id}/receipts', [C::class, 'purchaseReceipt'])->whereNumber('id')->name('purchase.receipt');
    Route::post('/orders/{id}/close', [C::class, 'closeOrder'])->whereNumber('id')->name('order.close');
    Route::post('/orders/{id}/invoices', [C::class, 'invoice'])->whereNumber('id')->name('purchase.invoice');
    Route::post('/invoices/{id}/payments/draft', [C::class, 'invoicePaymentDraft'])->whereNumber('id')->name('invoice.payment.draft');
    Route::post('/invoices/{id}/payments', [C::class, 'invoicePayment'])->whereNumber('id')->name('invoice.payment');
    Route::post('/receivables', [C::class, 'receivable'])->name('receivable.create');
    Route::post('/receipts/{id}/allocations', [E::class, 'allocate'])->whereNumber('id')->name('receipt.allocate');
    Route::post('/transfers', [C::class, 'createTransfer'])->name('transfer.create');
    Route::post('/transfers/{id}/approve', [C::class, 'approveTransfer'])->whereNumber('id')->name('transfer.approve');
    Route::post('/transfers/{id}/execute', [C::class, 'executeTransfer'])->whereNumber('id')->name('transfer.execute');
    Route::post('/advances', [E::class, 'advance'])->name('advance.create');
    Route::post('/advances/{id}/disburse', [E::class, 'disburseAdvance'])->whereNumber('id')->name('advance.disburse');
    Route::post('/advances/{id}/settlements', [E::class, 'settleAdvance'])->whereNumber('id')->name('advance.settle');
    Route::post('/reconciliations', [E::class, 'reconcile'])->name('reconcile.create');
    Route::post('/reconciliations/{id}/resolve', [C::class, 'resolveReconciliation'])->whereNumber('id')->name('reconcile.resolve');
    Route::post('/budgets/exceptions', [C::class, 'budgetException'])->name('budget.exception');
    Route::post('/budgets/exceptions/{id}/approve', [C::class, 'approveBudgetException'])->whereNumber('id')->name('budget.exception.approve');
    Route::post('/budgets/transfers', [C::class, 'budgetTransfer'])->name('budget.transfer');
    Route::post('/budgets/transfers/{id}/approve', [C::class, 'approveBudgetTransfer'])->whereNumber('id')->name('budget.transfer.approve');
    Route::post('/stock-transfers', [C::class, 'stockTransfer'])->name('stock.transfer');
    Route::post('/stock-movements', [F::class, 'movement'])->name('stock.move');
    Route::post('/stock-counts', [C::class, 'stockCount'])->name('stock.count');
    Route::post('/stock-counts/{id}/approve', [C::class, 'approveStockCount'])->whereNumber('id')->name('stock.count.approve');
    Route::post('/payroll', [C::class, 'payrollBatch'])->name('payroll.batch');
    Route::post('/payroll/{id}/approve', [C::class, 'approvePayroll'])->whereNumber('id')->name('payroll.approve');
    Route::post('/payroll-items/{id}/pay', [C::class, 'payrollPay'])->whereNumber('id')->name('payroll.pay');
    Route::post('/cheques', [C::class, 'cheque'])->name('cheques.create');
    Route::post('/cheques/{id}/status', [C::class, 'chequeStatus'])->whereNumber('id')->name('cheques.status');
    Route::post('/funds/{id}/movements', [C::class, 'fundMovement'])->whereNumber('id')->name('fund.movements');
    Route::post('/fund-movements/{id}/approve', [C::class, 'fundApprove'])->whereNumber('id')->name('fund.approve');
    Route::post('/transactions', [F::class, 'createTransaction'])->name('transaction.create');
    Route::post('/transactions/{id}/reverse', [C::class, 'requestReversal'])->whereNumber('id')->name('transaction.reverse');
    Route::post('/transactions/{id}/{action}', [F::class, 'transactionAction'])->whereNumber('id')->whereIn('action', ['submit', 'review', 'approve', 'execute'])->name('transaction.action');
    // «موظفو القسم وصلاحياتهم»: head of Accounts (within the department) and system administrator; ED decides sensitive grants.
    Route::get('/staff', [FinanceStaffController::class, 'index'])->name('staff');
    Route::get('/staff/{user}', [FinanceStaffController::class, 'show'])->whereNumber('user')->name('staff.show');
    Route::post('/staff/preview', [FinanceStaffController::class, 'preview'])->middleware('throttle:60,1')->name('staff.preview');
    Route::post('/staff/grants', [FinanceStaffController::class, 'store'])->middleware('throttle:30,1')->name('staff.grants');
    Route::post('/staff/{user}/end', [FinanceStaffController::class, 'end'])->whereNumber('user')->middleware('throttle:30,1')->name('staff.end');
    Route::post('/staff/{user}/withdraw', [FinanceStaffController::class, 'withdraw'])->whereNumber('user')->middleware('throttle:30,1')->name('staff.withdraw');
    Route::get('/access-grants/{publicId}', [FinanceStaffController::class, 'grant'])->whereUuid('publicId')->name('access-grants.show');
    Route::post('/access-grants/{publicId}/decide', [FinanceStaffController::class, 'decide'])->whereUuid('publicId')->middleware('throttle:30,1')->name('access-grants.decide');
    // «مسير الرواتب والقسائم»: the finalized payroll from the external accounting software.
    Route::get('/payroll-runs', [FinancePayrollController::class, 'index'])->name('payroll-runs.index');
    Route::get('/payroll-runs/{id}', [FinancePayrollController::class, 'show'])->whereNumber('id')->name('payroll-runs.show');
    Route::post('/payroll-runs/upload', [FinancePayrollController::class, 'upload'])->middleware('throttle:20,1')->name('payroll-runs.upload');
    Route::post('/payroll-runs/inspect', [FinancePayrollController::class, 'inspect'])->middleware('throttle:60,1')->name('payroll-runs.inspect');
    Route::post('/payroll-runs/analyze', [FinancePayrollController::class, 'analyze'])->middleware('throttle:30,1')->name('payroll-runs.analyze');
    Route::post('/payroll-runs', [FinancePayrollController::class, 'store'])->middleware('throttle:10,1')->name('payroll-runs.store');
    Route::post('/payroll-runs/{id}/review', [FinancePayrollController::class, 'review'])->whereNumber('id')->middleware('throttle:10,1')->name('payroll-runs.review');
    Route::post('/payroll-runs/{id}/authorize', [FinancePayrollController::class, 'authorize'])->whereNumber('id')->middleware('throttle:10,1')->name('payroll-runs.authorize');
    Route::post('/payroll-runs/{id}/publish', [FinancePayrollController::class, 'publish'])->whereNumber('id')->middleware('throttle:10,1')->name('payroll-runs.publish');
    Route::post('/payroll-runs/{id}/return', [FinancePayrollController::class, 'return'])->whereNumber('id')->middleware('throttle:10,1')->name('payroll-runs.return');
    Route::get('/reports/{module}.csv', [C::class, 'exportReport'])->name('report.csv');
    Route::get('/reports', [FinanceReportsController::class, 'index'])->name('report.index');
    Route::get('/reports/{report}', [FinanceReportsController::class, 'show'])->where('report', '[a-z_]+')->middleware('throttle:120,1')->name('report.show');
    Route::get('/reports/{report}/export/{format}', [FinanceReportsController::class, 'export'])->where('report', '[a-z_]+')
        ->whereIn('format', ['xlsx', 'pdf', 'csv', 'print'])->middleware('throttle:20,1')->name('report.export');
});

// Finance staff and board treasurer: administrative assignments only.
Route::middleware('auth')->get('/departments/budgets', [DepartmentBudgetController::class, 'summary'])->name('department.budgets');

Route::middleware('auth')->prefix('admin/finance')->name('admin.finance.')->group(function () {
    Route::get('/access', [FinanceSetupController::class, 'index'])->name('access');
    Route::post('/assign', [FinanceSetupController::class, 'assign'])->name('assign');
    Route::get('/users/{user}', [FinanceSetupController::class, 'userAccess'])->whereNumber('user')->name('user');
    Route::post('/permissions', [FinanceSetupController::class, 'permission'])->middleware('throttle:60,1')->name('permission');
    Route::post('/assignments/{id}/end', [FinanceSetupController::class, 'endAssignment'])->whereNumber('id')->name('assignment.end');
    Route::post('/board', [FinanceSetupController::class, 'board'])->name('board');
    Route::post('/threshold', [FinanceSetupController::class, 'threshold'])->name('threshold');
});
