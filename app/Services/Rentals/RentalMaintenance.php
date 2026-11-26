<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance and inspection for a property, unit or contract go through the central «طلب صيانة» workflow
 * (approved document §7/§G: the rentals file keeps no copy of maintenance, stock or purchasing records). The
 * file only keeps the link, so the unit's history shows the request and where it stands.
 */
final class RentalMaintenance
{
    public function __construct(private readonly WorkflowEngine $engine, private readonly RentalAudit $audit) {}

    public function open(User $actor, ?int $unitId, ?int $contractId, string $description, int $priorityValueId): object
    {
        $unit = $unitId ? DB::table('aims_property_units')->where('id', $unitId)->first() : null;
        $contract = $contractId ? DB::table('aims_rental_contracts')->where('id', $contractId)->first() : null;
        if (! $unit && ! $contract) {
            throw ValidationException::withMessages(['unit_id' => 'اختر العين أو العقد.']);
        }
        if (! $unit && $contract) {
            $unit = DB::table('aims_property_units as u')->join('aims_rental_contract_units as cu', 'cu.unit_id', '=', 'u.id')
                ->where('cu.contract_id', $contract->id)->orderBy('u.id')->first(['u.*']);
        }
        $property = $unit ? DB::table('aims_properties')->where('id', $unit->property_id)->first() : null;
        $location = trim(($property->name ?? '').' / '.($unit ? $unit->code.' '.$unit->name : '').($contract ? ' — العقد '.$contract->number : ''), ' /');

        return DB::transaction(function () use ($actor, $unit, $contract, $property, $location, $description, $priorityValueId): object {
            $instance = $this->engine->start($actor, 'MAINTENANCE', ['title' => 'صيانة أو فحص: '.$location, 'description' => $description],
                ['location' => $location, 'priority' => $priorityValueId]);
            $id = DB::table('aims_rental_maintenance_links')->insertGetId(['workflow_instance_id' => $instance->id, 'property_id' => $property?->id, 'unit_id' => $unit?->id,
                'contract_id' => $contract?->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->log((int) $actor->id, 'rental.maintenance.opened', 'aims_rental_maintenance_links', $id, null, ['workflow' => $instance->reference_no, 'location' => $location]);

            return $instance;
        });
    }

    /**
     * Maintenance requests linked to a unit or contract, with their workflow status.
     *
     * @return list<array<string, mixed>>
     */
    public function linked(?int $unitId, ?int $contractId): array
    {
        return DB::table('aims_rental_maintenance_links as l')->join('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->when($unitId, fn ($q) => $q->where('l.unit_id', $unitId))->when($contractId, fn ($q) => $q->where('l.contract_id', $contractId))
            ->orderByDesc('l.id')->limit(20)->get(['i.public_id', 'i.reference_no', 'i.status', 's.name as step', 'l.created_at'])
            ->map(fn (object $row): array => (array) $row + ['url' => '/workflows/'.$row->public_id])->all();
    }
}
