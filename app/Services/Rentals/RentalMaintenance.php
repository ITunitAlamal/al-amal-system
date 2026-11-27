<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance and inspection for a property, unit or contract go through the central «طلب صيانة» workflow
 * (approved document §7/§G: the rentals file keeps no copy of maintenance, stock or purchasing records). The
 * file keeps the link, so the unit's history shows the request and where it stands, who bears its cost, and when
 * the unit went back to «شاغرة» after the request closed (TRANSFORM §17: updated property status).
 */
final class RentalMaintenance
{
    public const COST_BEARERS = ['institution' => 'المؤسسة', 'tenant' => 'المستأجر'];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RentalAudit $audit,
        private readonly RentalUnits $units,
        private readonly NotificationCenter $notifications,
    ) {}

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
     * Maintenance requests linked to a unit or contract (or the latest of the whole file), with their workflow
     * status, who bears the cost, and whether the unit can now be returned to «شاغرة».
     *
     * @return list<array<string, mixed>>
     */
    public function linked(?int $unitId, ?int $contractId, int $limit = 20): array
    {
        return DB::table('aims_rental_maintenance_links as l')->join('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('aims_property_units as u', 'u.id', '=', 'l.unit_id')
            ->when($unitId, fn ($q) => $q->where('l.unit_id', $unitId))->when($contractId, fn ($q) => $q->where('l.contract_id', $contractId))
            ->orderByDesc('l.id')->limit($limit)
            ->get(['l.id', 'l.unit_id', 'l.contract_id', 'l.cost_bearer', 'l.cost_reference', 'l.unit_released_at', 'i.public_id', 'i.reference_no', 'i.status',
                's.name as step', 'l.created_at', 'u.code as unit_code', 'u.name as unit_name', 'u.availability as unit_availability'])
            ->map(fn (object $row): array => (array) $row + ['url' => '/workflows/'.$row->public_id,
                'releasable' => $row->status !== 'in_progress' && $row->unit_availability === 'maintenance' && ! $this->openOnUnit((int) $row->unit_id)])->all();
    }

    /**
     * Daily: tell the rentals managers once that a linked maintenance request closed while its unit is still
     * «تحت الصيانة», so they can return it to «شاغرة» with one click. A rented unit is never changed automatically.
     *
     * @param  iterable<int>  $recipients
     */
    public function notifyClosed(iterable $recipients): int
    {
        $sent = 0;
        $links = DB::table('aims_rental_maintenance_links as l')->join('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->join('aims_property_units as u', 'u.id', '=', 'l.unit_id')
            ->whereNull('l.closed_notified_at')->where('i.status', '<>', 'in_progress')
            ->orderBy('l.id')->limit(200)->get(['l.id', 'l.unit_id', 'i.reference_no', 'i.status', 'u.code', 'u.name', 'u.availability']);
        foreach ($links as $link) {
            if ($link->availability === 'maintenance' && ! $this->openOnUnit((int) $link->unit_id)) {
                $sent += $this->notifications->notifyMany($recipients, 'rental_maintenance_closed', [
                    'title' => 'أُغلق طلب الصيانة '.$link->reference_no.' للعين '.$link->code,
                    'body' => 'العين «'.$link->name.'» ما زالت «تحت الصيانة». أعدها شاغرة من «الملخص» في ملف الإيجارات، وسجّل من يتحمل التكلفة.',
                    'url' => '/admin/rentals',
                    'dedupe' => 'rental:maintenance-closed:'.$link->id,
                ]);
            }
            DB::table('aims_rental_maintenance_links')->where('id', $link->id)->update(['closed_notified_at' => now(), 'updated_at' => now()]);
        }

        return $sent;
    }

    /**
     * «إعادة العين شاغرة»: after the linked maintenance request closed, the unit that is still «تحت الصيانة» becomes
     * vacant, with the request's reference as the reason. Refused while another maintenance request on it is open.
     */
    public function releaseUnit(User $actor, int $linkId): void
    {
        DB::transaction(function () use ($actor, $linkId): void {
            $link = DB::table('aims_rental_maintenance_links')->where('id', $linkId)->lockForUpdate()->first();
            abort_unless($link && $link->unit_id, 404);
            $instance = DB::table('workflow_instances')->where('id', $link->workflow_instance_id)->first(['reference_no', 'status']);
            $unit = DB::table('aims_property_units')->where('id', $link->unit_id)->lockForUpdate()->first();
            if (! $instance || $instance->status === 'in_progress') {
                throw ValidationException::withMessages(['maintenance' => 'طلب الصيانة ما زال جاريًا؛ تُعاد العين بعد إغلاقه.']);
            }
            if (! $unit || $unit->availability !== 'maintenance') {
                throw ValidationException::withMessages(['maintenance' => 'العين ليست «تحت الصيانة»؛ لا شيء يُعاد.']);
            }
            if (! $unit->rentable) {
                throw ValidationException::withMessages(['maintenance' => 'العين معلّمة «غير قابلة للتأجير»؛ عدّل بياناتها أولًا.']);
            }
            if ($this->openOnUnit((int) $unit->id)) {
                throw ValidationException::withMessages(['maintenance' => 'على العين طلب صيانة آخر ما زال جاريًا.']);
            }
            $this->units->change((int) $unit->id, 'vacant', 'اكتمال طلب الصيانة '.$instance->reference_no, 'maintenance', (int) $actor->id, 'maintenance_link', $linkId);
            DB::table('aims_rental_maintenance_links')->where('id', $linkId)->update(['unit_released_at' => now(), 'updated_at' => now()]);
            $this->audit->log((int) $actor->id, 'rental.maintenance.unit_released', 'aims_rental_maintenance_links', $linkId,
                ['unit_status' => 'maintenance'], ['unit_status' => 'vacant', 'workflow' => $instance->reference_no]);
        });
    }

    /**
     * Who bears the cost of the work (the institution or the tenant) and the accounting reference behind it. A charge
     * to the tenant is billed only through a rent settlement «زيادة المستحق» (RENTAL_SETTLEMENT), never here.
     */
    public function recordCost(User $actor, int $linkId, string $bearer, ?string $reference): void
    {
        DB::transaction(function () use ($actor, $linkId, $bearer, $reference): void {
            $link = DB::table('aims_rental_maintenance_links')->where('id', $linkId)->lockForUpdate()->first();
            abort_unless($link, 404);
            $after = ['cost_bearer' => $bearer, 'cost_reference' => $reference];
            DB::table('aims_rental_maintenance_links')->where('id', $linkId)->update($after + ['cost_recorded_by' => $actor->id, 'cost_recorded_at' => now(), 'updated_at' => now()]);
            $this->audit->log((int) $actor->id, 'rental.maintenance.cost_recorded', 'aims_rental_maintenance_links', $linkId,
                ['cost_bearer' => $link->cost_bearer, 'cost_reference' => $link->cost_reference], $after);
        });
    }

    private function openOnUnit(int $unitId): bool
    {
        return $unitId > 0 && DB::table('aims_rental_maintenance_links as l')->join('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->where('l.unit_id', $unitId)->where('i.status', 'in_progress')->exists();
    }
}
