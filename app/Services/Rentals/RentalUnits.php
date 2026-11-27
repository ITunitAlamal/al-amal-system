<?php

namespace App\Services\Rentals;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unit states (approved rentals document §12). «مؤجرة» comes only from activating a contract and is left only by
 * completing a termination or eviction; «محجوزة» comes from an approved application. Every change is kept in
 * aims_property_unit_status_events with its reason and source; nothing is overwritten without a trace.
 */
final class RentalUnits
{
    public const LABELS = ['draft' => 'قيد الإعداد', 'vacant' => 'شاغرة', 'reserved' => 'محجوزة', 'rented' => 'مؤجرة',
        'maintenance' => 'تحت الصيانة', 'suspended' => 'موقوفة', 'out_of_service' => 'غير قابلة للتأجير'];

    /**
     * Moves the file officer may make by hand, with a reason. A reservation held by an application the executive
     * director approved is not released by hand (manual()): it ends with a contract, or by closing that application.
     */
    public const MANUAL = [
        'draft' => ['vacant', 'out_of_service'],
        'vacant' => ['maintenance', 'suspended', 'out_of_service'],
        'reserved' => ['vacant'],
        'maintenance' => ['vacant', 'suspended', 'out_of_service'],
        'suspended' => ['vacant', 'maintenance', 'out_of_service'],
        'out_of_service' => ['vacant', 'maintenance', 'suspended'],
    ];

    /** States a contract may be activated on. */
    public const LETTABLE = ['vacant', 'reserved', 'rented'];

    public function change(int $unitId, string $to, ?string $reason, string $source, ?int $actorId, ?string $referenceType = null, ?int $referenceId = null): void
    {
        $unit = DB::table('aims_property_units')->where('id', $unitId)->lockForUpdate()->first();
        abort_unless($unit, 404);
        if ($unit->availability === $to) {
            return;
        }
        DB::table('aims_property_units')->where('id', $unitId)->update(['availability' => $to, 'updated_at' => now()]);
        DB::table('aims_property_unit_status_events')->insert([
            'unit_id' => $unitId, 'from_status' => $unit->availability, 'to_status' => $to, 'reason' => $reason, 'source' => $source,
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'changed_by' => $actorId, 'created_at' => now(),
        ]);
    }

    /** A manual move by the file officer, limited to MANUAL. */
    public function manual(int $unitId, string $to, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($unitId, $to, $reason, $actorId): void {
            $unit = DB::table('aims_property_units')->where('id', $unitId)->lockForUpdate()->first();
            abort_unless($unit, 404);
            if (! in_array($to, self::MANUAL[$unit->availability] ?? [], true)) {
                throw ValidationException::withMessages(['status' => $unit->availability === 'rented'
                    ? 'العين مؤجرة: لا تتغير حالتها إلا بإكمال معاملة إنهاء أو إخلاء.'
                    : 'لا يجوز نقل العين من «'.(self::LABELS[$unit->availability] ?? $unit->availability).'» إلى «'.(self::LABELS[$to] ?? $to).'».']);
            }
            if ($unit->availability === 'reserved') {
                $held = DB::table('aims_rental_applications as a')->leftJoin('workflow_instances as wi', 'wi.id', '=', 'a.workflow_instance_id')
                    ->where('a.unit_id', $unitId)->where('a.status', 'approved')->first(['a.id', 'wi.reference_no']);
                if ($held) {
                    throw ValidationException::withMessages(['status' => 'العين محجوزة بقرار المدير التنفيذي لطلب الاستئجار '.($held->reference_no ?? '#'.$held->id)
                        .'؛ تُحرَّر بإغلاق الطلب دون تعاقد من صفحته، أو بإلغاء عقده أو رفضه، لا بتغيير يدوي.']);
                }
                $last = DB::table('aims_property_unit_status_events')->where('unit_id', $unitId)->orderByDesc('id')->first(['to_status', 'reference_type', 'reference_id']);
                $contract = $last && $last->to_status === 'reserved' && $last->reference_type === 'contract'
                    ? DB::table('aims_rental_contracts')->where('id', $last->reference_id)->where('status', 'approved')->value('number') : null;
                if ($contract !== null) {
                    throw ValidationException::withMessages(['status' => 'العين محجوزة للعقد '.$contract.' المعتمد من المدير التنفيذي؛ تُحرَّر بتفعيله أو إلغائه، لا بتغيير يدوي.']);
                }
            }
            if ($to === 'vacant' && ! $unit->rentable) {
                throw ValidationException::withMessages(['status' => 'العين معلّمة «غير قابلة للتأجير»؛ عدّل بياناتها أولًا.']);
            }
            $this->change($unitId, $to, $reason, 'manual', $actorId);
        });
    }
}
