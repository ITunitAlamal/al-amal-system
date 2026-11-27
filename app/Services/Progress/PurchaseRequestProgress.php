<?php

declare(strict_types=1);

namespace App\Services\Progress;

use Illuminate\Support\Facades\DB;

/**
 * Stepper for a finance purchase request, from the finance audit log and the execution records
 * (order, receipt, invoice, payment). Approval and execution are separate steps; the board-treasurer
 * step only appears when the director's decision routed the request there.
 */
final class PurchaseRequestProgress
{
    /**
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function view(object $purchase): array
    {
        $events = DB::table('finance_audit_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->where('e.entity_type', 'purchase')->where('e.entity_id', (string) $purchase->id)->orderBy('e.id')
            ->get(['e.action', 'e.detail', 'e.created_at', 'u.name']);
        $event = fn (string $action): ?object => $events->last(fn (object $row): bool => $row->action === $action);
        $submitted = $event('submit');
        $reviewed = $event('finance_head_review');
        $directed = $event('director_approve');
        $board = $event('board_treasurer_approve');
        $needsBoard = $purchase->state === 'pending_board_treasurer' || $board !== null
            || (($directed ? json_decode((string) $directed->detail, true)['next'] ?? null : null) === 'pending_board_treasurer');

        $order = DB::table('finance_purchase_orders')->where('purchase_request_id', $purchase->id)->orderBy('id')->first(['id', 'number', 'created_at']);
        $receipt = $order ? DB::table('finance_purchase_receipts')->where('order_id', $order->id)->orderBy('id')->first(['number', 'state', 'created_at']) : null;
        $invoice = $order ? DB::table('finance_purchase_invoices')->where('order_id', $order->id)->orderBy('id')->first(['id', 'invoice_no', 'state', 'external_voucher_no', 'updated_at', 'created_at']) : null;
        // In the documentary cycle the supplier is paid in the external accounting software; its voucher number closes it here.
        $payment = $invoice ? (DB::table('finance_invoice_payments')->where('invoice_id', $invoice->id)->whereNull('reversed_by_transaction_id')->orderBy('id')->first(['created_at'])
            ?? ($invoice->external_voucher_no ? (object) ['created_at' => $invoice->updated_at] : null)) : null;
        $creator = DB::table('users')->where('id', $purchase->created_by)->value('name');
        $state = $purchase->state;
        $approved = in_array($state, ['approved', 'ordered', 'closed'], true) || $order !== null;

        $step = fn (string $label, string $status, ?string $owner = null, ?string $done = null, ?string $note = null): array => [
            'label' => $label, 'state' => $status, 'owner' => $owner, 'startedAt' => null, 'completedAt' => $done,
            'dueAt' => null, 'overdue' => false, 'attempt' => 1, 'note' => $note,
        ];
        $flow = [
            $step('إعداد الطلب', $state === 'draft' ? 'current' : 'done', $creator, $submitted?->created_at),
            $step('مراجعة رئيس الحسابات', $state === 'pending_finance_head' ? 'current' : ($reviewed ? 'done' : 'upcoming'), $reviewed?->name ?? ($state === 'pending_finance_head' ? 'رئيس الحسابات' : null), $reviewed?->created_at),
            $step('اعتماد المدير التنفيذي', $state === 'pending_director' ? 'current' : ($directed ? 'done' : 'upcoming'), $directed?->name ?? ($state === 'pending_director' ? 'المدير التنفيذي' : null), $directed?->created_at),
        ];
        if ($needsBoard) {
            $flow[] = $step('موافقة أمين الصندوق', $state === 'pending_board_treasurer' ? 'current' : ($board ? 'done' : 'upcoming'), $board?->name ?? 'أمين الصندوق', $board?->created_at, 'تتطلبها قيمة الطلب وفق سياسة المشتريات.');
        }
        $flow[] = $step('إصدار أمر الشراء', $order ? 'done' : ($approved ? 'current' : 'upcoming'), $order ? 'أمر '.$order->number : 'المشتريات', $order?->created_at);
        $flow[] = $step('استلام المواد', $receipt ? 'done' : ($order ? 'current' : 'upcoming'), $receipt ? 'محضر '.$receipt->number : 'المخازن', $receipt?->created_at);
        $flow[] = $step('فاتورة المورد', $invoice ? 'done' : ($receipt ? 'current' : 'upcoming'), $invoice ? 'فاتورة '.$invoice->invoice_no : 'الحسابات', $invoice?->created_at);
        $flow[] = $step('الدفع للمورد', $payment ? 'done' : ($invoice ? 'current' : 'upcoming'), $invoice?->external_voucher_no ? 'سند '.$invoice->external_voucher_no : 'الحسابات', $payment?->created_at);
        if ($state === 'cancelled') {
            $flow = array_map(fn (array $item): array => $item['state'] === 'current' ? [...$item, 'state' => 'upcoming'] : $item, $flow);

            return ['steps' => $flow, 'summary' => ['status' => 'ملغى', 'tone' => 'muted', 'where' => 'أُلغي الطلب بعد اعتماده'.(($purchase->cancellation_reason ?? null) ? ': '.$purchase->cancellation_reason : ''),
                'next' => 'لا إجراءات متبقية', 'dueAt' => null, 'overdue' => false, 'partial' => $events->isEmpty()]];
        }
        if ($state === 'rejected') {
            $flow = array_map(fn (array $item): array => $item['state'] === 'current' ? [...$item, 'state' => 'upcoming'] : $item, $flow);

            return ['steps' => $flow, 'summary' => ['status' => 'مرفوض', 'tone' => 'danger', 'where' => 'رُفض الطلب'.($purchase->decision_note ?? null ? ': '.$purchase->decision_note : ''),
                'next' => 'لا إجراءات متبقية', 'dueAt' => null, 'overdue' => false, 'partial' => $events->isEmpty()]];
        }

        $current = collect($flow)->firstWhere('state', 'current');
        $next = null;
        foreach ($flow as $index => $item) {
            if ($item['state'] === 'current') {
                $next = $flow[$index + 1]['label'] ?? 'إغلاق الطلب';
            }
        }

        return ['steps' => $flow, 'summary' => [
            'status' => $payment ? 'منجز ومدفوع' : ($current['label'] ?? 'قيد المعالجة'),
            'tone' => $payment ? 'success' : 'info',
            'where' => $payment ? 'اكتملت دورة الشراء والدفع' : ($current ? $current['label'].($current['owner'] ? ' — '.$current['owner'] : '') : 'قيد المعالجة'),
            'next' => $payment ? 'لا إجراءات متبقية' : ($next ?? '—'),
            'dueAt' => null, 'overdue' => false, 'partial' => $events->isEmpty(),
        ]];
    }
}
