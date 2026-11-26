<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Call inside a transaction while holding the financial transaction's row lock. */
final class FinanceSettlementService
{
    private const USES = [
        'invoice' => 'finance_invoice_payments',
        'payroll' => 'finance_payroll_disbursements',
        'advance' => 'finance_advance_disbursements',
        'advance_refund' => 'finance_advance_settlements',
        'collection' => 'finance_payment_allocations',
    ];

    public function assertAvailable(object $transaction, string $purpose, bool $executing = false): void
    {
        if ($transaction->state !== ($executing ? 'approved' : 'executed')
            || $transaction->reversal_of_id
            || DB::table('finance_transactions')->where('reversal_of_id', $transaction->id)->exists()
            || DB::table('finance_internal_transfers')->where('out_transaction_id', $transaction->id)->orWhere('in_transaction_id', $transaction->id)->exists()) {
            throw ValidationException::withMessages(['transaction_id' => 'السند معكوس أو مخصص لتحويل داخلي أو غير قابل للتسوية.']);
        }
        foreach (self::USES as $use => $table) {
            if ($use !== $purpose && DB::table($table)->where('transaction_id', $transaction->id)->exists()) {
                throw ValidationException::withMessages(['transaction_id' => 'سند الحركة مستخدم في التزام آخر، ولا يجوز احتسابه مرتين.']);
            }
        }
        if ($purpose !== 'invoice' && $transaction->source_id
            && DB::table('finance_sources')->where('id', $transaction->source_id)->where('source_module', 'finance_purchase_invoice')->exists()) {
            throw ValidationException::withMessages(['transaction_id' => 'هذا السند مخصص لسداد فاتورة شراء.']);
        }
    }

    public function reverse(object $reversal, int $actor): void
    {
        $original = DB::table('finance_transactions')->where('id', $reversal->reversal_of_id)->lockForUpdate()->first();
        if (! $original || $original->state !== 'executed' || $original->reversal_of_id
            || $original->kind === $reversal->kind || $original->currency !== $reversal->currency
            || (int) $original->treasury_id !== (int) $reversal->treasury_id
            || FinanceMoney::cents($original->amount) !== FinanceMoney::cents($reversal->amount)) {
            throw ValidationException::withMessages(['transaction_id' => 'سند العكس لا يطابق السند الأصلي.']);
        }
        $payments = DB::table('finance_invoice_payments')->where('transaction_id', $original->id)->whereNull('reversed_by_transaction_id')->get();
        foreach ($payments as $payment) {
            $invoice = DB::table('finance_purchase_invoices')->where('id', $payment->invoice_id)->lockForUpdate()->first();
            DB::table('finance_invoice_payments')->where('id', $payment->id)->update(['reversed_by_transaction_id' => $reversal->id]);
            $paid = DB::table('finance_invoice_payments')->where('invoice_id', $invoice->id)->whereNull('reversed_by_transaction_id')->sum('amount');
            DB::table('finance_purchase_invoices')->where('id', $invoice->id)->update([
                'state' => FinanceMoney::cents((string) $paid) === FinanceMoney::cents($invoice->amount) ? 'paid' : 'pending',
                'updated_at' => now(),
            ]);
            DB::table('finance_purchase_orders')->where('id', $invoice->order_id)->where('state', 'closed')->update(['state' => 'received', 'updated_at' => now()]);
            if ($original->budget_line_id) {
                $this->budgetMovement($original, $reversal, $actor, 'commitment', (string) $payment->amount);
            }
        }
        foreach (DB::table('finance_payroll_disbursements')->where('transaction_id', $original->id)->whereNull('reversed_by_transaction_id')->get() as $payment) {
            DB::table('finance_payroll_items')->where('id', $payment->payroll_item_id)->lockForUpdate()->first();
            DB::table('finance_payroll_items')->where('id', $payment->payroll_item_id)->update(['state' => 'pending', 'updated_at' => now()]);
        }
        foreach (DB::table('finance_advance_disbursements')->where('transaction_id', $original->id)->whereNull('reversed_by_transaction_id')->get() as $payment) {
            DB::table('finance_advances')->where('id', $payment->advance_id)->lockForUpdate()->first();
            if (DB::table('finance_advance_settlements')->where('advance_id', $payment->advance_id)->whereNull('reversed_by_transaction_id')->exists()) {
                throw ValidationException::withMessages(['transaction_id' => 'السلفة لها تسويات قائمة؛ يجب معالجة تسوياتها قبل عكس الصرف.']);
            }
            DB::table('finance_advances')->where('id', $payment->advance_id)->update(['state' => 'draft', 'updated_at' => now()]);
        }
        foreach (DB::table('finance_advance_settlements')->where('transaction_id', $original->id)->whereNull('reversed_by_transaction_id')->get() as $settlement) {
            DB::table('finance_advances')->where('id', $settlement->advance_id)->lockForUpdate()->first();
            DB::table('finance_advances')->where('id', $settlement->advance_id)->update(['state' => 'disbursed', 'updated_at' => now()]);
        }
        foreach (self::USES as $table) {
            DB::table($table)->where('transaction_id', $original->id)->whereNull('reversed_by_transaction_id')->update(['reversed_by_transaction_id' => $reversal->id]);
        }
        if ($original->kind === 'payment' && $original->budget_line_id) {
            $this->budgetMovement($original, $reversal, $actor, 'spend', '-'.(string) $reversal->amount);
        }
    }

    private function budgetMovement(object $original, object $reversal, int $actor, string $kind, string $amount): void
    {
        DB::table('finance_budget_lines')->where('id', $original->budget_line_id)->lockForUpdate()->first();
        DB::table('finance_budget_movements')->insert([
            'budget_line_id' => $original->budget_line_id, 'kind' => $kind, 'amount' => $amount,
            'state' => 'approved', 'created_by' => $actor, 'approved_by' => $reversal->approved_by,
            'reason' => 'عكس سند '.$original->document_no, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
