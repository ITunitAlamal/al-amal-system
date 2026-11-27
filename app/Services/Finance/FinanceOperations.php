<?php

namespace App\Services\Finance;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Financial mutations are atomic, idempotent where sourced, and never rewrite executed history. */
final class FinanceOperations
{
    public function __construct(private readonly FinanceGuard $guard) {}

    public function budgetAvailable(int $lineId): string
    {
        $b = DB::table('finance_budget_lines')->where('id', $lineId)->first();
        if (! $b) {
            throw ValidationException::withMessages(['budget_line_id' => 'بند الموازنة غير موجود']);
        }
        $mov = DB::table('finance_budget_movements')->where('budget_line_id', $lineId)->where('state', 'approved')->get(['kind', 'amount']);
        $reserved = 0;
        $released = 0;
        $spent = 0;
        $adjustment = 0;
        foreach ($mov as $line) {
            $amount = FinanceMoney::cents((string) $line->amount);
            switch ($line->kind) {
                case 'commitment':$reserved += $amount;
                    break;
                case 'release':$released += $amount;
                    break;
                case 'spend':$spent += $amount;
                    break;
                case 'adjustment':$adjustment += $amount;
                    break;
            }
        }

        return FinanceMoney::format(
            FinanceMoney::cents((string) $b->approved_amount)
            + FinanceMoney::cents((string) $b->adjustment_amount)
            + $adjustment - $reserved + $released - $spent
        );
    }

    public function submitPurchase(int $id, int $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'draft') {
                throw ValidationException::withMessages(['purchase' => 'الطلب غير قابل للإرسال']);
            }
            $lines = DB::table('finance_purchase_items')->where('purchase_request_id', $id)->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'أضف صنفًا واحدًا على الأقل']);
            }
            $total = 0;
            foreach ($lines as $i) {
                $total += FinanceMoney::lineCents((string) $i->quantity, (string) $i->unit_price);
            }
            if ($total <= 0) {
                throw ValidationException::withMessages(['total' => 'قيمة الطلب يجب أن تكون موجبة']);
            }
            // In the documentary cycle the budget is controlled in the external accounting software.
            if (! $p->budget_line_id && ! $this->guard->documentaryMode()) {
                throw ValidationException::withMessages(['budget_line_id' => 'بند الموازنة إلزامي قبل إرسال طلب الشراء']);
            }
            if ($p->budget_line_id && ! $this->guard->documentaryMode()) {
                DB::table('finance_budget_lines')->where('id', $p->budget_line_id)->lockForUpdate()->first();
                if ($total > FinanceMoney::cents($this->budgetAvailable((int) $p->budget_line_id))) {
                    throw ValidationException::withMessages(['budget' => 'الرصيد المتاح لا يكفي']);
                }
            }
            DB::table('finance_purchase_requests')->where('id', $id)->update(['total_amount' => FinanceMoney::format($total), 'state' => 'pending_finance_head', 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'submit', ['total' => FinanceMoney::format($total)]);
            $this->notifyStage($p, 'pending_finance_head', [$actor]);
        });
    }

    public function reviewPurchase(int $id, int $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'pending_finance_head') {
                throw ValidationException::withMessages(['state' => 'مرحلة غير صحيحة']);
            }
            if ((int) $p->created_by === $actor || self::requesterOf($p) === $actor) {
                throw ValidationException::withMessages(['actor' => 'لا يمكن اعتماد الطلب الذي أنشأته أو طلبته']);
            }
            DB::table('finance_purchase_requests')->where('id', $id)->update(['state' => 'pending_director', 'reviewed_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'finance_head_review');
            $this->notifyStage($p, 'pending_director', [$actor]);
        });
    }

    private function commitApprovedPurchase(object $p, int $actor): void
    {
        if ($this->guard->documentaryMode()) {
            return; // no budget commitment here: the books are kept in the external accounting software
        }
        if (! $p->budget_line_id) {
            throw ValidationException::withMessages(['budget' => 'بند الموازنة إلزامي للاعتماد']);
        }
        $line = DB::table('finance_budget_lines')->where('id', $p->budget_line_id)->lockForUpdate()->first();
        if (! $line || $line->currency !== $p->currency || $line->department_id != $p->department_id) {
            throw ValidationException::withMessages(['budget' => 'بند الموازنة لا يطابق القسم أو العملة']);
        }
        if (FinanceMoney::cents($this->budgetAvailable((int) $line->id)) < FinanceMoney::cents((string) $p->total_amount)) {
            throw ValidationException::withMessages(['budget' => 'الرصيد المتاح تغير قبل الاعتماد']);
        }
        DB::table('finance_budget_movements')->insert(['budget_line_id' => $line->id, 'kind' => 'commitment', 'amount' => $p->total_amount, 'state' => 'approved', 'reason' => 'اعتماد طلب شراء '.$p->number, 'created_by' => $p->created_by, 'approved_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function directorPurchase(int $id, int $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'pending_director') {
                throw ValidationException::withMessages(['state' => 'مرحلة غير صحيحة']);
            }
            if ((int) $p->created_by === $actor || (int) $p->reviewed_by === $actor || self::requesterOf($p) === $actor) {
                throw ValidationException::withMessages(['actor' => 'تعارض صلاحيات الاعتماد']);
            }
            $policy = DB::table('finance_purchase_policy')->where('code', 'board_treasurer_purchase_threshold')->where('is_active', true)->first();
            // Fail closed if policy is not configured, or if FX valuation is not approved.
            if (! $policy) {
                throw ValidationException::withMessages(['policy' => 'سياسة مشتريات مجلس الإدارة غير مضبوطة']);
            }
            if ($p->currency === 'ILS') {
                $ils = FinanceMoney::cents((string) $p->total_amount);
            } elseif ($p->evaluated_ils !== null) {
                $ils = FinanceMoney::cents((string) $p->evaluated_ils);
            } else {
                throw ValidationException::withMessages(['currency' => 'يجب تقييم الطلب بالشيكل وتوثيق سعر التحويل قبل اعتماده']);
            }
            if ($ils <= 0) {
                throw ValidationException::withMessages(['currency' => 'قيمة التقييم غير صالحة']);
            }
            $next = $ils > FinanceMoney::cents((string) $policy->threshold_ils) ? 'pending_board_treasurer' : 'approved';
            if ($next === 'approved') {
                $this->commitApprovedPurchase($p, $actor);
            }
            DB::table('finance_purchase_requests')->where('id', $id)->update(['director_approved_by' => $actor, 'state' => $next, 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'director_approve', ['ils' => FinanceMoney::format($ils), 'next' => $next]);
            if ($next === 'approved') {
                $this->notifyOutcome($p, 'اعتُمد طلب الشراء '.$p->number.'؛ جاهز لإصدار أمر الشراء', null, 'approved');
            } else {
                $this->notifyStage($p, $next, [$actor, (int) $p->reviewed_by]);
            }
        });
    }

    public function boardPurchase(int $id, int $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'pending_board_treasurer') {
                throw ValidationException::withMessages(['state' => 'مرحلة غير صحيحة']);
            }
            if (in_array($actor, [(int) $p->created_by, (int) $p->reviewed_by, (int) $p->director_approved_by, self::requesterOf($p)], true)) {
                throw ValidationException::withMessages(['actor' => 'تعارض صلاحيات الاعتماد']);
            }
            $board = DB::table('finance_board_approvers')->where('user_id', $actor)->where('is_active', true)->exists();
            if (! $board) {
                throw ValidationException::withMessages(['actor' => 'المستخدم غير معين أمين صندوق بمجلس الإدارة']);
            }
            $this->commitApprovedPurchase($p, $actor);
            DB::table('finance_purchase_requests')->where('id', $id)->update(['board_approved_by' => $actor, 'state' => 'approved', 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'board_treasurer_approve');
            $this->notifyOutcome($p, 'اعتُمد طلب الشراء '.$p->number.'؛ جاهز لإصدار أمر الشراء', null, 'approved');
        });
    }

    /**
     * The employee who asked for a purchase from «طلباتي» (null for one procurement opened itself). That employee
     * never reviews or approves it; for the order, the requester takes the creator's place (owner decision
     * 2026-11-02), so the procurement officer who only prepared the request may issue its order.
     */
    public static function requesterOf(object $p): ?int
    {
        if (($p->source_module ?? null) !== 'aims_request') {
            return null;
        }
        $id = DB::table('aims_requests')->where('id', (int) $p->source_id)->value('requested_by');

        return $id === null ? null : (int) $id;
    }

    /**
     * Cancels an approved purchase that will not go ahead (ACC-15; SRS: cancellation needs a reason and keeps the
     * history). The decision is the executive director's, who approved it (finance.procurement.director_approve,
     * checked by the caller); no new stage is added. Allowed:
     * - an approved request with no order, or whose order is still 'issued' with nothing received or invoiced: the
     *   order (if any) and the request become 'cancelled', and the budget commitment made on approval is released;
     * - an order partly received ('partial_received'): the undelivered remainder is cancelled and the order counts as
     *   received, so it is invoiced and closed on what was actually delivered (closeOrder releases the rest).
     * Nobody cancels a request they created or asked for. Every cancellation is audited and the people involved told.
     */
    public function cancelPurchase(int $id, int $actor, string $reason): string
    {
        return DB::transaction(function () use ($id, $actor, $reason): string {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'approved') {
                throw ValidationException::withMessages(['state' => 'يُلغى طلب الشراء المعتمد فقط؛ الطلب قيد الاعتماد يُرفض من مرحلته.']);
            }
            if (in_array($actor, array_map('intval', array_filter([$p->created_by, self::requesterOf($p)])), true)) {
                throw ValidationException::withMessages(['actor' => 'لا تلغي طلب شراء أنشأته أو طلبته.']);
            }
            $order = DB::table('finance_purchase_orders')->where('purchase_request_id', $id)->lockForUpdate()->first();
            $stamp = ['cancelled_by' => $actor, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_at' => now()];
            // Accepted goods only: a delivery rejected in full leaves nothing in stock and does not block cancelling.
            $received = $order && (float) DB::table('finance_purchase_receipt_items as x')->join('finance_purchase_receipts as y', 'y.id', '=', 'x.receipt_id')
                ->where('y.order_id', $order->id)->sum('x.quantity') > 0;
            $invoiced = $order && DB::table('finance_purchase_invoices')->where('order_id', $order->id)->exists();

            if ($order && $order->state === 'partial_received' && $received) {
                DB::table('finance_purchase_orders')->where('id', $order->id)->update(['state' => 'received'] + $stamp);
                $this->guard->audit('purchase_order', $order->id, $actor, 'remainder_cancelled', ['reason' => $reason, 'purchase_request_id' => $id]);
                $outcome = 'remainder';
            } elseif (! $order || ($order->state === 'issued' && ! $received && ! $invoiced)) {
                if ($order) {
                    DB::table('finance_purchase_orders')->where('id', $order->id)->update(['state' => 'cancelled'] + $stamp);
                    $this->guard->audit('purchase_order', $order->id, $actor, 'cancelled', ['reason' => $reason, 'purchase_request_id' => $id]);
                }
                DB::table('finance_purchase_requests')->where('id', $id)->update(['state' => 'cancelled'] + $stamp);
                $this->releaseCommitment($p, $actor);
                $this->guard->audit('purchase', $id, $actor, 'cancelled', ['reason' => $reason, 'order_id' => $order?->id]);
                $outcome = 'cancelled';
            } else {
                throw ValidationException::withMessages(['order' => 'لا يُلغى أمر شراء استُلمت أصنافه كاملة أو سُجّلت عليه فاتورة دون استلام؛ يُغلق الأمر أو يُرجَع للمورد بمحضر الاستلام.']);
            }

            $title = $outcome === 'cancelled' ? 'أُلغي طلب الشراء '.$p->number : 'أُلغي المتبقي غير المورَّد من أمر الشراء '.$order->number;
            $people = array_filter([(int) $p->created_by, self::requesterOf($p), (int) $p->reviewed_by, (int) $p->board_approved_by, $order ? (int) $order->created_by : null]);
            $this->notifyDecided($p, array_values(array_diff($people, [$actor])), ['title' => $title, 'body' => $reason, 'dedupe' => 'purchase-cancel:'.$id.':'.$outcome]);

            return $outcome;
        });
    }

    /** Releases the commitment the approval made (full accounting mode only; none exists in the documentary cycle). */
    private function releaseCommitment(object $p, int $actor): void
    {
        if ($this->guard->documentaryMode() || ! $p->budget_line_id) {
            return;
        }
        DB::table('finance_budget_lines')->where('id', $p->budget_line_id)->lockForUpdate()->first();
        $committed = DB::table('finance_budget_movements')->where('budget_line_id', $p->budget_line_id)->where('kind', 'commitment')
            ->where('state', 'approved')->where('reason', 'اعتماد طلب شراء '.$p->number)->exists();
        if (! $committed) {
            return;
        }
        DB::table('finance_budget_movements')->insert(['budget_line_id' => $p->budget_line_id, 'kind' => 'release', 'amount' => $p->total_amount, 'state' => 'approved',
            'reason' => 'إلغاء طلب الشراء '.$p->number, 'created_by' => $actor, 'approved_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Tells whoever decides the request's next stage that it waits for them (ACC-12). Holders come from
     * FinancePayrollRuns::holders, which includes the structural executive director. The request's creator, its
     * «طلباتي» requester and the given actors are never told to decide it.
     *
     * @param  list<int>  $exclude
     */
    private function notifyStage(object $p, string $stage, array $exclude): void
    {
        $permission = self::PURCHASE_STAGES[$stage] ?? null;
        if ($permission === null) {
            return;
        }
        $skip = array_map('intval', array_filter([...$exclude, $p->created_by, self::requesterOf($p)]));
        $holders = app(FinancePayrollRuns::class)->holders($permission)->reject(fn (int $uid): bool => in_array($uid, $skip, true));
        $label = ['pending_finance_head' => 'مراجعة رئيس الحسابات', 'pending_director' => 'اعتماد المدير التنفيذي', 'pending_board_treasurer' => 'موافقة أمين الصندوق'][$stage];
        app(NotificationCenter::class)->notifyMany($holders, 'finance_purchase_pending', [
            'title' => 'طلب الشراء '.$p->number.' بانتظار '.$label, 'url' => route('finance.index', ['view' => 'purchases'], false),
            'dedupe' => 'purchase-stage:'.$p->id.':'.$stage.':'.now()->format('YmdHis'),
        ]);
    }

    /** The procurement officer who prepared the request and the employee who asked for it hear the outcome. */
    private function notifyOutcome(object $p, string $title, ?string $body, string $outcome): void
    {
        $this->notifyDecided($p, array_filter([(int) $p->created_by, self::requesterOf($p)]),
            ['title' => $title, 'body' => $body, 'dedupe' => 'purchase-outcome:'.$p->id.':'.$outcome.':'.now()->format('YmdHis')]);
    }

    /**
     * Sends finance_purchase_decided: finance staff open the purchases view; the «طلباتي» requester, who holds no
     * finance.view, opens their own request page, where the purchase progress is shown.
     *
     * @param  list<int>  $people
     * @param  array{title: string, body?: ?string, dedupe?: ?string}  $payload
     */
    private function notifyDecided(object $p, array $people, array $payload): void
    {
        $center = app(NotificationCenter::class);
        $requester = self::requesterOf($p);
        $staff = array_values(array_filter(array_map('intval', $people), fn (int $uid): bool => $uid !== $requester));
        $center->notifyMany($staff, 'finance_purchase_decided', $payload + ['url' => route('finance.index', ['view' => 'purchases'], false)]);
        if ($requester === null || ! in_array($requester, array_map('intval', $people), true)) {
            return;
        }
        $publicId = DB::table('workflow_instances')->where('subject_type', 'aims_request')->where('subject_id', (int) $p->source_id)
            ->orderByDesc('id')->value('public_id');
        $center->notifyMany([$requester], 'finance_purchase_decided', $payload + ['url' => $publicId ? route('workflows.show', ['publicId' => $publicId], false) : null]);
    }

    /** Approval stage => the permission that decides it. */
    public const PURCHASE_STAGES = [
        'pending_finance_head' => 'finance.procurement.review',
        'pending_director' => 'finance.procurement.director_approve',
        'pending_board_treasurer' => 'finance.procurement.board_approve',
    ];

    /**
     * Rejection or return to procurement by whoever decides the current stage, with a written reason. Nobody decides
     * a request they created or already approved. A return sends it back to draft; its earlier approvals are cleared
     * and stay in the audit trail, so the revised request goes through every stage again.
     */
    public function decidePurchase(int $id, int $actor, string $decision, string $note): void
    {
        DB::transaction(function () use ($id, $actor, $decision, $note) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || ! isset(self::PURCHASE_STAGES[$p->state])) {
                throw ValidationException::withMessages(['state' => 'الطلب ليس في مرحلة اعتماد.']);
            }
            if (in_array($actor, array_map('intval', array_filter([$p->created_by, $p->reviewed_by, $p->director_approved_by, self::requesterOf($p)])), true)) {
                throw ValidationException::withMessages(['actor' => 'لا تقرر طلبًا أنشأته أو سبق أن اعتمدته.']);
            }
            if ($p->state === 'pending_board_treasurer' && ! DB::table('finance_board_approvers')->where('user_id', $actor)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['actor' => 'المستخدم غير معين أمين صندوق بمجلس الإدارة']);
            }
            $update = $decision === 'reject'
                ? ['state' => 'rejected', 'rejected_by' => $actor]
                : ['state' => 'draft', 'reviewed_by' => null, 'director_approved_by' => null, 'board_approved_by' => null];
            DB::table('finance_purchase_requests')->where('id', $id)->update($update + ['decision_note' => $note, 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, $decision === 'reject' ? 'rejected' : 'returned', ['stage' => $p->state, 'note' => $note]);
            $this->notifyOutcome($p, ($decision === 'reject' ? 'رُفض طلب الشراء ' : 'أُعيد إليك طلب الشراء للتصحيح: ').$p->number, $note, $decision);
        });
    }

    /**
     * Procurement's own draft: replace its lines (and purpose) before sending it, e.g. a request from «طلباتي»
     * priced after quotes, or a request returned for revision.
     *
     * @param  list<array{item_name: string, quantity: string, unit: string, unit_price: string}>  $items
     */
    public function replacePurchaseLines(int $id, int $actor, array $items, ?string $purpose): void
    {
        DB::transaction(function () use ($id, $actor, $items, $purpose) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            if (! $p || $p->state !== 'draft') {
                throw ValidationException::withMessages(['purchase' => 'تُعدّل بنود الطلب وهو مسودة فقط.']);
            }
            if ((int) $p->created_by !== $actor) {
                throw ValidationException::withMessages(['purchase' => 'يعدّل البنود من أعدّ الطلب فقط.']);
            }
            DB::table('finance_purchase_items')->where('purchase_request_id', $id)->delete();
            foreach ($items as $i) {
                DB::table('finance_purchase_items')->insert([
                    'purchase_request_id' => $id, 'item_name' => $i['item_name'], 'quantity' => $i['quantity'], 'unit' => $i['unit'],
                    'unit_price' => $i['unit_price'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('finance_purchase_requests')->where('id', $id)->update(array_filter(['purpose' => $purpose]) + ['updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'lines_updated', ['lines' => count($items)]);
        });
    }

    public function stockMovement(array $data, int $actor): int
    {
        return DB::transaction(function () use ($data, $actor) {
            $id = StockExact::movement($data, $actor);
            $this->guard->audit('stock_movement', $id, $actor, 'create');

            return $id;
        });
    }

    public function createSource(string $module, string $id, string $event, array $metadata): int
    {
        return DB::transaction(function () use ($module, $id, $event, $metadata) {
            DB::table('finance_sources')->insertOrIgnore([
                'source_module' => $module, 'source_id' => $id, 'event_type' => $event,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::table('finance_sources')->where(['source_module' => $module, 'source_id' => $id, 'event_type' => $event])->value('id');
        });
    }
}
