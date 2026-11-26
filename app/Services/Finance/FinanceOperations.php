<?php

namespace App\Services\Finance;

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
