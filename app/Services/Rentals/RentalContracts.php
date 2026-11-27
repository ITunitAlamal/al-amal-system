<?php

namespace App\Services\Rentals;

use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens to a contract outside the approval chain itself: activation after signing and handover, ending by
 * termination or eviction, expiry, and releasing a reservation. A contract's history is never deleted: a renewal
 * or an amendment is a new contract linked to the previous one, and dues already due are never recalculated.
 */
final class RentalContracts
{
    public const LABELS = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار اعتماد المدير التنفيذي', 'returned' => 'مُعاد للتعديل', 'approved' => 'معتمد بانتظار التوقيع والتفعيل',
        'active' => 'فعال', 'expired' => 'منتهي المدة', 'renewed' => 'جُدِّد بعقد لاحق', 'superseded' => 'استُبدل بملحق', 'terminated' => 'مُنهى', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى'];

    public const KINDS = ['new' => 'عقد جديد', 'renewal' => 'تجديد', 'amendment' => 'ملحق / تعديل'];

    public const FREQUENCY_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    /** Contracts that still occupy their units (a renewal or a termination is opened against them). */
    public const OCCUPYING = ['active', 'expired'];

    /** Contracts not yet decided or activated: a renewal or an amendment in one of these is an open successor. */
    public const OPEN = ['draft', 'pending_approval', 'returned', 'approved'];

    public function __construct(
        private readonly RentalUnits $units,
        private readonly RentalSchedule $schedule,
        private readonly RentalFinanceService $finance,
        private readonly RentalLedger $ledger,
        private readonly RentalsAccess $access,
        private readonly NotificationCenter $notifications,
        private readonly RentalAudit $audit,
    ) {}

    /** Whether a termination or eviction of the contract is still running (any case not rejected, completed or cancelled). */
    public function openTermination(int $contractId, ?int $exceptCaseId = null): bool
    {
        return DB::table('aims_rental_cases')->where('contract_id', $contractId)->where('kind', 'eviction')
            ->whereNotIn('status', ['rejected', 'completed', 'cancelled'])->when($exceptCaseId, fn ($q) => $q->where('id', '<>', $exceptCaseId))->exists();
    }

    /**
     * A renewal or an amendment goes ahead only while its parent still occupies its units and no termination of the
     * parent is running: an approval given earlier never revives a contract that has since been terminated.
     */
    public function assertParentOpen(object $contract): void
    {
        if (! $contract->parent_contract_id) {
            return;
        }
        $parent = DB::table('aims_rental_contracts')->where('id', $contract->parent_contract_id)->first(['id', 'number', 'status']);
        $kind = self::KINDS[$contract->kind] ?? 'العقد';
        if (! $parent || ! in_array($parent->status, self::OCCUPYING, true)) {
            throw ValidationException::withMessages(['workflow' => 'العقد الأصلي '.($parent->number ?? '').' لم يعد فعالًا (حالته: '.(self::LABELS[$parent->status ?? ''] ?? '—')
                .')؛ لا يمضي «'.$kind.'» عليه. يُرفض أو يُلغى.']);
        }
        if ($this->openTermination((int) $parent->id)) {
            throw ValidationException::withMessages(['workflow' => 'توجد معاملة إنهاء مفتوحة للعقد الأصلي '.$parent->number.'؛ لا يمضي «'.$kind.'» قبل البت فيها.']);
        }
    }

    /**
     * Activate an approved contract once it is signed and handed over: occupy its units (the database refuses two
     * occupying contracts over overlapping dates), generate its rent and separate-service dues, register them with
     * Accounts and mark the units rented. An amendment replaces its parent from its own start date.
     */
    public function activate(int $contractId, int $actorId): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $contractId)->lockForUpdate()->first();
        abort_unless($contract && $contract->status === 'approved', 409, 'العقد غير معتمد أو فُعّل مسبقًا.');
        foreach (['signed_contract' => 'النسخة الموقعة من العقد', 'handover_record' => 'محضر التسليم'] as $type => $label) {
            if (! $this->hasDocument('contract', $contractId, $type)) {
                throw ValidationException::withMessages(['workflow' => "أرفق {$label} في ملف العقد قبل التفعيل."]);
            }
        }
        // Units carried over from the contract a renewal or an amendment follows are not handed over again.
        $carried = $contract->parent_contract_id ? DB::table('aims_rental_contract_units')->where('contract_id', $contract->parent_contract_id)
            ->pluck('unit_id')->map(fn ($id): int => (int) $id)->all() : [];
        $missing = $this->unitsWithoutReading($contractId, 'handover', 'contract_id', $contractId, $carried);
        if ($missing !== []) {
            throw ValidationException::withMessages(['workflow' => 'سجّل قراءة العداد عند التسليم لكل عين قبل التفعيل؛ ناقصة: '.implode('، ', $missing).'.']);
        }
        $parent = $contract->parent_contract_id ? DB::table('aims_rental_contracts')->where('id', $contract->parent_contract_id)->lockForUpdate()->first() : null;
        $this->assertParentOpen($contract);
        $items = DB::table('aims_rental_contract_units')->where('contract_id', $contractId)->orderBy('unit_id')->get();
        $application = $contract->application_id ? DB::table('aims_rental_applications')->where('id', $contract->application_id)->first() : null;
        $this->assertUnitsAvailable($contract);

        if ($parent && $contract->kind === 'amendment') {
            $this->closeParentForAmendment($parent, $contract, $actorId);
        }
        try {
            DB::table('aims_rental_contract_units')->where('contract_id', $contractId)
                ->update(['starts_on' => $contract->starts_on, 'ends_on' => $contract->ends_on, 'occupies' => true, 'updated_at' => now()]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['workflow' => 'تعارض: إحدى الأعيان مشغولة بعقد آخر في الفترة نفسها.']);
            }
            throw $exception;
        }

        $this->generateDues($contract);
        $this->finance->registerContract($contractId, $actorId);
        foreach ($items as $item) {
            $this->units->change((int) $item->unit_id, 'rented', 'تفعيل العقد '.$contract->number, 'contract', $actorId, 'contract', $contractId);
        }
        DB::table('aims_rental_contracts')->where('id', $contractId)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
        // A renewal of a contract already past its end closes it now; one signed early waits for the daily expiry.
        if ($parent && $contract->kind === 'renewal' && $parent->status === 'expired') {
            DB::table('aims_rental_contracts')->where('id', $parent->id)->update(['status' => 'renewed', 'closed_at' => now(), 'updated_at' => now()]);
        }
        if ($application) {
            DB::table('aims_rental_applications')->where('id', $application->id)->update(['status' => 'contracted', 'updated_at' => now()]);
        }
    }

    /**
     * End a contract through a completed termination or eviction: the occupancy ends on the departure date,
     * later dues with nothing paid or settled are cancelled with the reason, and the units take the state the
     * handover recorded. Dues with payments stay for Accounts to settle.
     */
    public function terminate(object $case, int $actorId): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $case->contract_id)->lockForUpdate()->first();
        abort_unless($contract && in_array($contract->status, self::OCCUPYING, true), 409, 'العقد ليس فعالًا ولا منتهي المدة.');
        $departure = $case->departure_on ?? now()->toDateString();
        $endsOn = min(max($departure, $contract->starts_on), $contract->ends_on);

        DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->where('occupies', true)->update(['ends_on' => $endsOn, 'updated_at' => now()]);
        $later = $this->ledger->dues()->where('d.contract_id', $contract->id)->where('d.due_on', '>', $endsOn)->where('d.status', '<>', 'cancelled')->get();
        foreach ($later as $due) {
            if ((float) $due->paid === 0.0 && (float) $due->reduced === 0.0 && (float) $due->increased === 0.0) {
                $reason = 'إنهاء العقد في '.$endsOn.' (معاملة '.$case->id.')';
                DB::table('aims_rental_dues')->where('id', $due->id)->update(['status' => 'cancelled', 'cancelled_at' => now(),
                    'cancel_reason' => $reason, 'updated_at' => now()]);
                $this->finance->cancelDue((int) $due->id, $reason, $actorId);
            }
        }
        DB::table('aims_rental_contracts')->where('id', $contract->id)->update(['status' => 'terminated', 'terminated_on' => $endsOn,
            'termination_reason_value_id' => $case->termination_reason_value_id, 'closed_at' => now(), 'updated_at' => now()]);

        foreach (DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->pluck('unit_id') as $unitId) {
            $stillOccupied = DB::table('aims_rental_contract_units as cu')->join('aims_rental_contracts as c', 'c.id', '=', 'cu.contract_id')
                ->where('cu.unit_id', $unitId)->where('cu.occupies', true)->where('c.id', '<>', $contract->id)
                ->whereIn('c.status', self::OCCUPYING)->where('cu.ends_on', '>=', now()->toDateString())->exists();
            if (! $stillOccupied) {
                $this->units->change((int) $unitId, $case->outcome_unit_status ?: 'vacant', 'محضر الاستلام لإنهاء العقد '.$contract->number, 'termination', $actorId, 'case', (int) $case->id);
            }
        }
    }

    /**
     * A rejected or cancelled contract ends its application: the application closes «مغلق دون تعاقد» and gives back
     * the unit it had reserved, so no approved application is left pointing at a unit that is free again.
     */
    public function releaseReservation(object $contract, int $actorId): void
    {
        // Units this contract reserved itself at the executive director's approval are vacant again.
        foreach (DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->pluck('unit_id') as $unitId) {
            $unit = DB::table('aims_property_units')->where('id', $unitId)->lockForUpdate()->first();
            if ($unit && $unit->availability === 'reserved' && $this->reservedBy((int) $unitId, 'contract', (int) $contract->id)) {
                $this->units->change((int) $unitId, 'vacant', 'إلغاء أو رفض العقد '.$contract->number, 'contract', $actorId, 'contract', (int) $contract->id);
            }
        }
        if (! $contract->application_id) {
            return;
        }
        $application = DB::table('aims_rental_applications')->where('id', $contract->application_id)->lockForUpdate()->first();
        if ($application && $application->status === 'approved') {
            $this->closeApplication($application, 'إلغاء أو رفض العقد '.$contract->number, $actorId, 'contract', 'contract', (int) $contract->id);
        }
    }

    /**
     * An approved application that will not become a contract closes («مغلق دون تعاقد») and its reserved unit is
     * vacant again, with the reason in the unit's history. The executive director's decision on the application
     * stays as recorded. Runs inside the caller's transaction, with the application row locked.
     */
    public function closeApplication(object $application, string $reason, int $actorId, string $source, string $referenceType, int $referenceId): void
    {
        DB::table('aims_rental_applications')->where('id', $application->id)->where('status', 'approved')->update(['status' => 'cancelled', 'updated_at' => now()]);
        $unit = $application->unit_id ? DB::table('aims_property_units')->where('id', $application->unit_id)->lockForUpdate()->first() : null;
        if ($unit && $unit->availability === 'reserved') {
            $this->units->change((int) $unit->id, 'vacant', $reason, $source, $actorId, $referenceType, $referenceId);
        }
        $this->audit->log($actorId, 'rental.application.closed', 'aims_rental_applications', (int) $application->id,
            ['status' => $application->status], ['status' => 'cancelled', 'reason' => $reason, 'unit_released' => $unit && $unit->availability === 'reserved']);
    }

    /** A contract rejected or cancelled before activation: its hand-entered schedule lines are cancelled, never left open. */
    public function cancelPlannedDues(object $contract, string $reason): int
    {
        if ($contract->activated_at !== null) {
            return 0;
        }

        return DB::table('aims_rental_dues')->where('contract_id', $contract->id)->where('status', '<>', 'cancelled')
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now()]);
    }

    /**
     * Daily: contracts past their end become «منتهي المدة», or «جُدِّد» when a renewal is active. The units stay
     * occupied until a handover is recorded (approved document: no unit is vacant before the eviction record).
     *
     * @return array{expired: int, renewed: int}
     */
    public function expire(): array
    {
        $result = ['expired' => 0, 'renewed' => 0];
        $today = now()->toDateString();
        foreach (DB::table('aims_rental_contracts')->where('status', 'active')->where('ends_on', '<', $today)->orderBy('id')->get() as $contract) {
            DB::transaction(function () use ($contract, &$result): void {
                $locked = DB::table('aims_rental_contracts')->where('id', $contract->id)->lockForUpdate()->first();
                if ($locked->status !== 'active') {
                    return;
                }
                $renewed = DB::table('aims_rental_contracts')->where('parent_contract_id', $contract->id)->where('kind', 'renewal')->where('status', 'active')->exists();
                DB::table('aims_rental_contracts')->where('id', $contract->id)->update(['status' => $renewed ? 'renewed' : 'expired', 'updated_at' => now()]);
                $result[$renewed ? 'renewed' : 'expired']++;
                if (! $renewed) {
                    $this->notifications->notifyMany($this->access->managers()->pluck('id')->merge($this->access->executiveIds()), 'rental_contract_expired', [
                        'title' => 'انتهت مدة عقد الإيجار '.$contract->number,
                        'body' => 'لا يوجد تجديد مفعّل. تبقى العين مشغولة حتى محضر الاستلام؛ افتح معاملة إنهاء وإخلاء أو تجديدًا.',
                        'url' => '/admin/rentals/records/contract/'.$contract->id,
                        'dedupe' => 'rental:expired:'.$contract->id.':'.$contract->ends_on,
                    ]);
                }
            });
        }

        return $result;
    }

    public function hasDocument(string $ownerType, int $ownerId, string $typeCode): bool
    {
        return DB::table('aims_rental_documents as d')->join('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
            ->where('d.owner_type', $ownerType)->where('d.owner_id', $ownerId)->where('v.code', $typeCode)->exists();
    }

    /**
     * Every unit of the contract can still be let to it, checked with the units locked: at the executive director's
     * approval and again at activation. A unit is free (vacant), reserved for this contract or the application it
     * comes from, or a unit of the parent contract it renews or amends.
     */
    public function assertUnitsAvailable(object $contract): void
    {
        $parentUnits = $contract->parent_contract_id ? DB::table('aims_rental_contract_units')->where('contract_id', $contract->parent_contract_id)
            ->pluck('unit_id')->map(fn ($id): int => (int) $id)->all() : [];
        $application = $contract->application_id ? DB::table('aims_rental_applications')->where('id', $contract->application_id)->first() : null;
        foreach (DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->orderBy('unit_id')->pluck('unit_id') as $unitId) {
            $unit = DB::table('aims_property_units')->where('id', $unitId)->lockForUpdate()->first();
            $fromParent = in_array((int) $unitId, $parentUnits, true);
            $reservedHere = $unit && $unit->availability === 'reserved'
                && (($application && (int) $application->unit_id === (int) $unit->id) || $this->reservedBy((int) $unit->id, 'contract', (int) $contract->id));
            if (! $unit || ! $unit->rentable || ! in_array($unit->availability, RentalUnits::LETTABLE, true)
                || ($unit->availability === 'rented' && ! $fromParent) || ($unit->availability === 'reserved' && ! $reservedHere && ! $fromParent)) {
                throw ValidationException::withMessages(['workflow' => 'العين «'.($unit->name ?? $unitId).'» ليست متاحة لهذا العقد (حالتها: '.(RentalUnits::LABELS[$unit->availability ?? ''] ?? '—').').']);
            }
        }
    }

    /** At the executive director's approval the contract's vacant units are reserved for it until activation or cancellation. */
    public function reserveUnits(object $contract, int $actorId, string $reference): void
    {
        foreach (DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->orderBy('unit_id')->pluck('unit_id') as $unitId) {
            if (DB::table('aims_property_units')->where('id', $unitId)->value('availability') === 'vacant') {
                $this->units->change((int) $unitId, 'reserved', 'حجز للعقد '.$contract->number.' المعتمد ('.$reference.') حتى التوقيع والتفعيل', 'contract', $actorId, 'contract', (int) $contract->id);
            }
        }
    }

    /** Whether the unit's current reservation was made by the given record (its latest status change). */
    public function reservedBy(int $unitId, string $referenceType, int $referenceId): bool
    {
        $last = DB::table('aims_property_unit_status_events')->where('unit_id', $unitId)->orderByDesc('id')->first(['to_status', 'reference_type', 'reference_id']);

        return $last && $last->to_status === 'reserved' && $last->reference_type === $referenceType && (int) $last->reference_id === $referenceId;
    }

    /**
     * Units of the contract without a meter reading of the given purpose (handover on the contract, eviction on the
     * termination). Asked only when the contract carries an electricity service, the metered one in the reference list.
     *
     * @param  list<int>  $except  units not concerned (carried over from the parent contract)
     * @return list<string> unit labels
     */
    public function unitsWithoutReading(int $contractId, string $purpose, string $column, int $value, array $except = []): array
    {
        $metered = DB::table('aims_rental_contract_services as s')->join('master_data_values as v', 'v.id', '=', 's.service_value_id')
            ->where('s.contract_id', $contractId)->where('v.code', 'electricity')->exists();
        if (! $metered) {
            return [];
        }

        return DB::table('aims_rental_contract_units as cu')->join('aims_property_units as u', 'u.id', '=', 'cu.unit_id')
            ->where('cu.contract_id', $contractId)->when($except !== [], fn ($q) => $q->whereNotIn('cu.unit_id', $except))
            ->whereNotExists(fn ($q) => $q->from('aims_rental_meter_readings as m')->whereColumn('m.unit_id', 'cu.unit_id')
                ->where('m.purpose', $purpose)->where('m.'.$column, $value))
            ->orderBy('u.code')->get(['u.code', 'u.name'])->map(fn (object $unit): string => trim($unit->code.' '.$unit->name))->all();
    }

    /**
     * The supporting document a request needs before it is sent (SRS 3.8.3.2: no discount, exemption or settlement
     * without a document; approved document §4: the applicant's identity or registration). Null when present.
     */
    public function missingSupportingDocument(string $type, object $row): ?string
    {
        if ($type === 'settlement') {
            return DB::table('aims_rental_documents')->where('owner_type', 'settlement')->where('owner_id', $row->id)->exists()
                ? null : 'أرفق مستند التسوية (القرار أو الإثبات أو المراسلة) قبل الإرسال.';
        }
        if ($type === 'application') {
            $tenantType = DB::table('aims_rental_tenants')->where('id', $row->tenant_id)->value('type');
            [$code, $label] = $tenantType === 'person' ? ['identity', 'صورة هوية المستأجر'] : ['registration', 'شهادة تسجيل المستأجر (الشركة أو الجمعية أو المؤسسة)'];

            return $this->hasDocument('application', (int) $row->id, $code) || $this->hasDocument('tenant', (int) $row->tenant_id, $code)
                ? null : "أرفق {$label} في ملف الطلب أو ملف المستأجر قبل الإرسال.";
        }

        return null;
    }

    /** Rent dues per period, then the separate services on the same dates; a custom schedule was entered by hand. */
    private function generateDues(object $contract): void
    {
        $months = self::FREQUENCY_MONTHS[$contract->frequency] ?? null;
        if ($months === null) {
            $custom = DB::table('aims_rental_dues')->where('contract_id', $contract->id)->where('kind', 'rent')->count();
            if ($custom === 0) {
                throw ValidationException::withMessages(['workflow' => 'الجدول مخصص: أدخل استحقاقات العقد قبل التفعيل.']);
            }
        }
        $dates = $months === null
            ? DB::table('aims_rental_dues')->where('contract_id', $contract->id)->where('kind', 'rent')->orderBy('due_on')->pluck('due_on')->all()
            : $this->schedule->dates($contract->starts_on, $contract->ends_on, $months);
        $rows = [];
        foreach ($dates as $date) {
            if ($months !== null) {
                $rows[] = ['contract_id' => $contract->id, 'due_on' => $date, 'kind' => 'rent', 'service_value_id' => null, 'currency_code' => $contract->currency_code,
                    'amount' => (string) $contract->total_rent, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (DB::table('aims_rental_contract_services')->where('contract_id', $contract->id)->where('billing', 'separate')->get() as $service) {
                $rows[] = ['contract_id' => $contract->id, 'due_on' => $date, 'kind' => 'service', 'service_value_id' => $service->service_value_id,
                    'currency_code' => $contract->currency_code, 'amount' => (string) $service->amount, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($rows !== []) {
            DB::table('aims_rental_dues')->insertOrIgnore($rows);
        }
    }

    /**
     * An amendment takes over from its start date: the parent stops occupying the day before, and the parent's
     * dues from that date are cancelled. Dues there with money or settlements block it: Accounts settle them first.
     */
    private function closeParentForAmendment(object $parent, object $contract, int $actorId): void
    {
        abort_unless(in_array($parent->status, self::OCCUPYING, true), 409, 'العقد الأصلي ليس فعالًا.');
        $from = CarbonImmutable::parse($contract->starts_on);
        if ($from->lessThanOrEqualTo(CarbonImmutable::parse($parent->starts_on)) || $from->greaterThan(CarbonImmutable::parse($parent->ends_on))) {
            throw ValidationException::withMessages(['workflow' => 'يبدأ الملحق داخل مدة العقد الأصلي وبعد بدايته.']);
        }
        $affected = $this->ledger->dues()->where('d.contract_id', $parent->id)->where('d.due_on', '>=', $from->toDateString())->where('d.status', '<>', 'cancelled')->get();
        if ($affected->contains(fn (object $due): bool => (float) $due->paid > 0 || (float) $due->reduced > 0 || (float) $due->increased > 0)) {
            throw ValidationException::withMessages(['workflow' => 'على استحقاقات العقد الأصلي بعد تاريخ الملحق دفعات أو تسويات؛ تعالجها الحسابات بتسوية قبل التفعيل.']);
        }
        $reason = 'استُبدلت بالملحق '.$contract->number.' من '.$from->toDateString();
        DB::table('aims_rental_dues')->whereIn('id', $affected->pluck('id'))->update(['status' => 'cancelled', 'cancelled_at' => now(),
            'cancel_reason' => $reason, 'updated_at' => now()]);
        foreach ($affected as $due) {
            $this->finance->cancelDue((int) $due->id, $reason, $actorId);
        }
        DB::table('aims_rental_contract_units')->where('contract_id', $parent->id)->where('occupies', true)
            ->update(['ends_on' => $from->subDay()->toDateString(), 'updated_at' => now()]);
        DB::table('aims_rental_contracts')->where('id', $parent->id)->update(['status' => 'superseded', 'closed_at' => now(), 'updated_at' => now()]);
    }
}
