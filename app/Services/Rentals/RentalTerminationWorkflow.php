<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Termination and eviction on the central engine (approved document §9): inspection by general services and
 * maintenance, clearance by Accounts, the executive director's decision, then the handover record that ends the
 * contract and returns the unit to the state found at inspection.
 */
final class RentalTerminationWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'rental_case';

    public const DEFINITION = 'RENTAL_TERMINATION';

    public const LABELS = ['draft' => 'مسودة', 'in_review' => 'قيد الفحص والمخالصة والقرار', 'returned' => 'مُعاد لمسؤول الملف', 'approved' => 'معتمد بانتظار محضر الاستلام',
        'rejected' => 'مرفوض', 'completed' => 'مكتمل: أُنهي العقد', 'cancelled' => 'ملغى'];

    private const STATUS_BY_STEP = ['officer' => 'returned', 'inspection' => 'in_review', 'clearance' => 'in_review', 'ed_decision' => 'in_review',
        'handover' => 'approved', 'rejected' => 'rejected'];

    public function __construct(private readonly RentalContracts $contracts, private readonly RentalsAccess $access, private readonly RentalAudit $audit) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $case = DB::table('aims_rental_cases')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($case && $case->contract_id, 404);
        if (in_array($from->code, ['officer', 'handover'], true)) {
            abort_unless($this->access->canManage(User::findOrFail($actorId)), 403, 'إعداد الإنهاء ومحضر الاستلام لمسؤول ملف الإيجارات.');
        }
        if ($target->code === 'completed') {
            if (! $case->outcome_unit_status) {
                throw ValidationException::withMessages(['workflow' => 'سجّل في محضر الاستلام حالة العين بعد الإخلاء قبل الإتمام.']);
            }
            // Both the eviction record and, for a metered contract, the eviction reading of every unit (approved document §5/§9).
            if (! $this->contracts->hasDocument('case', (int) $case->id, 'eviction_record')) {
                throw ValidationException::withMessages(['workflow' => 'أرفق محضر الاستلام والإخلاء قبل الإتمام.']);
            }
            $missing = $this->contracts->unitsWithoutReading((int) $case->contract_id, 'eviction', 'case_id', (int) $case->id);
            if ($missing !== []) {
                throw ValidationException::withMessages(['workflow' => 'سجّل قراءة العداد عند الإخلاء لكل عين قبل الإتمام؛ ناقصة: '.implode('، ', $missing).'.']);
            }
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $case = DB::table('aims_rental_cases')->where('id', $instance->subject_id)->first();
        $before = $case->status;
        if ($target->code === 'completed') {
            $this->contracts->terminate($case, $actorId);
            DB::table('aims_rental_cases')->where('id', $case->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        } elseif (isset(self::STATUS_BY_STEP[$target->code])) {
            $update = ['status' => self::STATUS_BY_STEP[$target->code], 'updated_at' => now()];
            if (in_array($target->code, ['handover', 'rejected'], true)) {
                $update += ['decided_by' => $actorId, 'decided_at' => now()];
            }
            DB::table('aims_rental_cases')->where('id', $case->id)->update($update);
        }
        $after = DB::table('aims_rental_cases')->where('id', $case->id)->value('status');
        $this->audit->log($actorId, 'rental.termination.'.$transition->action_code, 'aims_rental_cases', (int) $case->id,
            ['status' => $before], ['status' => $after, 'comment' => $comment, 'workflow' => $instance->reference_no]);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/rentals/records/case/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('aims_rental_cases as k')->leftJoin('aims_rental_contracts as c', 'c.id', '=', 'k.contract_id')->whereIn('k.id', $subjectIds)
            ->pluck('c.number', 'k.id')->map(fn (?string $number): string => 'إنهاء وإخلاء العقد '.($number ?? '—'))->all();
    }
}
