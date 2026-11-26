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

    /** Moves the file officer may make by hand, with a reason. */
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
            if ($to === 'vacant' && ! $unit->rentable) {
                throw ValidationException::withMessages(['status' => 'العين معلّمة «غير قابلة للتأجير»؛ عدّل بياناتها أولًا.']);
            }
            $this->change($unitId, $to, $reason, 'manual', $actorId);
        });
    }
}
