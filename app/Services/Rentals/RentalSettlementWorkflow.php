<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rent settlement (exemption, discount, correction) on the central engine, as the owner's prompt requires:
 * request ← Accounts review ← executive director's decision ← posting recorded by Accounts. The balance moves only
 * when Accounts record the posting reference from the accounting software; a reduction may not exceed what
 * remains on the due at that moment.
 */
final class RentalSettlementWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'rental_settlement';

    public const DEFINITION = 'RENTAL_SETTLEMENT';

    public const LABELS = ['draft' => 'مسودة', 'in_review' => 'قيد المراجعة والقرار', 'returned' => 'مُعادة لمسؤول الملف', 'approved' => 'معتمدة بانتظار الترحيل',
        'rejected' => 'مرفوضة', 'posted' => 'مُرحّلة', 'cancelled' => 'ملغاة'];

    public const EFFECTS = ['reduce' => 'تخفيض المستحق (إعفاء أو خصم أو تصحيح)', 'increase' => 'زيادة المستحق (مديونية أو تعديل)'];

    private const STATUS_BY_STEP = ['officer' => 'returned', 'finance_review' => 'in_review', 'ed_decision' => 'in_review', 'posting' => 'approved', 'rejected' => 'rejected'];

    public function __construct(private readonly RentalLedger $ledger, private readonly RentalsAccess $access, private readonly RentalAudit $audit,
        private readonly RentalContracts $contracts, private readonly RentalFinanceService $finance) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $settlement = DB::table('aims_rental_settlements')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($settlement, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'officer') {
            abort_unless($this->access->canManage($actor), 403, 'طلب التسوية لمسؤول ملف الإيجارات.');
            // Also when a returned settlement is sent again: no settlement goes forward without its document.
            $missing = $this->contracts->missingSupportingDocument('settlement', $settlement);
            if ($missing !== null && $target->code !== 'rejected') {
                throw ValidationException::withMessages(['workflow' => $missing]);
            }
        }
        if ($target->code === 'completed') {
            abort_unless($this->access->canRecordFinance($actor), 403, 'تسجيل الترحيل لموظف الحسابات المخوّل.');
            if (! $settlement->posting_reference || ! $settlement->posting_date) {
                throw ValidationException::withMessages(['workflow' => 'سجّل رقم قيد الترحيل وتاريخه من البرنامج المحاسبي أولًا.']);
            }
            if ($settlement->due_id) {
                DB::table('aims_rental_dues')->where('id', $settlement->due_id)->lockForUpdate()->first();
                if ($settlement->effect === 'reduce' && MoneyRules::toMinor((string) $settlement->amount) > $this->ledger->remainingMinor((int) $settlement->due_id)) {
                    throw ValidationException::withMessages(['workflow' => 'مبلغ التخفيض يتجاوز المتبقي على الاستحقاق الآن؛ أعد التسوية للمراجعة.']);
                }
            }
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $settlement = DB::table('aims_rental_settlements')->where('id', $instance->subject_id)->first();
        if ($target->code === 'completed') {
            DB::table('aims_rental_settlements')->where('id', $settlement->id)->update(['status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now(), 'updated_at' => now()]);
            if ($settlement->due_id) {
                $this->ledger->refreshStatus((int) $settlement->due_id);
                // The receivable registered with Accounts follows the posted settlement (append-only adjustment).
                $this->finance->settleDue($settlement, $actorId);
            }
        } elseif (isset(self::STATUS_BY_STEP[$target->code])) {
            DB::table('aims_rental_settlements')->where('id', $settlement->id)->update(['status' => self::STATUS_BY_STEP[$target->code], 'updated_at' => now()]);
        }
        $after = DB::table('aims_rental_settlements')->where('id', $settlement->id)->value('status');
        $this->audit->log($actorId, 'rental.settlement.'.$transition->action_code, 'aims_rental_settlements', (int) $settlement->id,
            ['status' => $settlement->status], ['status' => $after, 'comment' => $comment, 'workflow' => $instance->reference_no,
                'posting_reference' => $target->code === 'completed' ? $settlement->posting_reference : null]);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/rentals/records/settlement/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('aims_rental_settlements as s')->join('aims_rental_contracts as c', 'c.id', '=', 's.contract_id')->whereIn('s.id', $subjectIds)
            ->pluck('c.number', 's.id')->map(fn (string $number): string => 'تسوية إيجار للعقد '.$number)->all();
    }
}
