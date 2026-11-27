<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * Rental contract on the central engine (approved document §5): new contracts, renewals and amendments alike.
 * The file officer prepares, the executive director approves (the units are rechecked and the vacant ones reserved
 * for the contract), then a rentals officer attaches the signed copy and the handover record, records the handover
 * meter readings and activates; only then does the contract occupy its units and produce dues.
 */
final class RentalContractWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'rental_contract';

    public const DEFINITION = 'RENTAL_CONTRACT';

    private const STATUS_BY_STEP = ['officer' => 'returned', 'ed_approval' => 'pending_approval', 'signing' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'];

    public function __construct(private readonly RentalContracts $contracts, private readonly RentalsAccess $access, private readonly RentalAudit $audit) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($contract, 404);
        if (in_array($from->code, ['officer', 'signing'], true)) {
            abort_unless($this->access->canManage(User::findOrFail($actorId)), 403, 'إعداد العقود وتفعيلها لمسؤول ملف الإيجارات.');
        }
        // A renewal or an amendment moves forward only while its parent is still in force and not being terminated.
        if (in_array($target->code, ['ed_approval', 'signing', 'active'], true)) {
            $this->contracts->assertParentOpen($contract);
        }
        // The executive director approves only a contract whose units are still free for it (TRANSFORM §16).
        if ($target->code === 'signing') {
            $this->contracts->assertUnitsAvailable($contract);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $instance->subject_id)->first();
        $before = $contract->status;
        if ($target->code === 'active') {
            $this->contracts->activate((int) $contract->id, $actorId);
        } elseif (isset(self::STATUS_BY_STEP[$target->code])) {
            $update = ['status' => self::STATUS_BY_STEP[$target->code], 'updated_at' => now()];
            if ($target->code === 'signing') {
                $update += ['approved_by' => $actorId, 'approved_at' => now()];
            }
            DB::table('aims_rental_contracts')->where('id', $contract->id)->update($update);
            if ($target->code === 'signing') {
                $this->contracts->reserveUnits($contract, $actorId, (string) $instance->reference_no);
            }
            if (in_array($target->code, ['rejected', 'cancelled'], true)) {
                $this->contracts->releaseReservation($contract, $actorId);
                $this->contracts->cancelPlannedDues($contract, ($target->code === 'rejected' ? 'رفض العقد ' : 'إلغاء العقد ').$contract->number
                    .' ('.$instance->reference_no.')');
            }
        }
        $after = DB::table('aims_rental_contracts')->where('id', $contract->id)->value('status');
        $this->audit->log($actorId, 'rental.contract.'.$transition->action_code, 'aims_rental_contracts', (int) $contract->id,
            ['status' => $before], ['status' => $after, 'comment' => $comment, 'workflow' => $instance->reference_no]);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/rentals/records/contract/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('aims_rental_contracts')->whereIn('id', $subjectIds)->get(['id', 'number', 'kind'])
            ->mapWithKeys(fn (object $contract): array => [(int) $contract->id => (RentalContracts::KINDS[$contract->kind] ?? 'عقد').' إيجار '.$contract->number])->all();
    }
}
