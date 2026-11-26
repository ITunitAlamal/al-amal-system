<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Finance\FinanceMoney;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The four rental requests the file officer prepares — application, contract (new, renewal, amendment),
 * termination and eviction, settlement. Each is saved as a draft, may be edited while a draft or returned, and is
 * sent to the central workflow engine when ready; from then on only the workflow moves it.
 */
final class RentalRequests
{
    /** @var array<string, array{table: string, definition: string, subject: string}> */
    public const TYPES = [
        'application' => ['table' => 'aims_rental_applications', 'definition' => RentalApplicationWorkflow::DEFINITION, 'subject' => RentalApplicationWorkflow::SUBJECT_TYPE],
        'contract' => ['table' => 'aims_rental_contracts', 'definition' => RentalContractWorkflow::DEFINITION, 'subject' => RentalContractWorkflow::SUBJECT_TYPE],
        'case' => ['table' => 'aims_rental_cases', 'definition' => RentalTerminationWorkflow::DEFINITION, 'subject' => RentalTerminationWorkflow::SUBJECT_TYPE],
        'settlement' => ['table' => 'aims_rental_settlements', 'definition' => RentalSettlementWorkflow::DEFINITION, 'subject' => RentalSettlementWorkflow::SUBJECT_TYPE],
    ];

    public const EDITABLE = ['draft', 'returned'];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RentalsAccess $access,
        private readonly RentalLedger $ledger,
        private readonly RentalConstants $constants,
        private readonly RentalAudit $audit,
    ) {}

    /** @param array{tenant_id: int, unit_id?: ?int, purpose?: ?string, study?: ?string} $data */
    public function createApplication(User $actor, array $data): int
    {
        $this->checkApplication($data);
        $id = DB::table('aims_rental_applications')->insertGetId([
            'tenant_id' => $data['tenant_id'], 'unit_id' => $data['unit_id'] ?? null, 'purpose' => $data['purpose'] ?? null, 'study' => $data['study'] ?? null,
            'status' => 'draft', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->log((int) $actor->id, 'rental.application.created', 'aims_rental_applications', $id, null, $data);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data  contract fields, items (unit_id, rent_share, rent_per_m2) and services (service_value_id, billing, amount)
     */
    public function createContract(User $actor, array $data): int
    {
        return DB::transaction(function () use ($actor, $data): int {
            $this->checkContract($data, null);
            $id = DB::table('aims_rental_contracts')->insertGetId($this->contractRow($data) + ['status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->saveContractLines($id, $data);
            $this->audit->log((int) $actor->id, 'rental.contract.created', 'aims_rental_contracts', $id, null, $this->contractRow($data));

            return $id;
        });
    }

    /** @param array{contract_id: int, termination_reason_value_id: int, departure_on: string, reason: string, details?: ?string} $data */
    public function createTermination(User $actor, array $data): int
    {
        return DB::transaction(function () use ($actor, $data): int {
            $this->checkTermination($data, null);
            $id = DB::table('aims_rental_cases')->insertGetId([
                'contract_id' => $data['contract_id'], 'unit_id' => null, 'kind' => 'eviction', 'status' => 'draft', 'reason' => $data['reason'],
                'details' => $data['details'] ?? null, 'effective_on' => $data['departure_on'], 'departure_on' => $data['departure_on'],
                'termination_reason_value_id' => $data['termination_reason_value_id'], 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->log((int) $actor->id, 'rental.termination.created', 'aims_rental_cases', $id, null, $data);

            return $id;
        });
    }

    /** @param array{due_id: int, type_value_id: int, effect: string, amount: string, reason: string} $data */
    public function createSettlement(User $actor, array $data): int
    {
        return DB::transaction(function () use ($actor, $data): int {
            $due = $this->checkSettlement($data);
            $id = DB::table('aims_rental_settlements')->insertGetId([
                'contract_id' => $due->contract_id, 'due_id' => $due->id, 'type_value_id' => $data['type_value_id'], 'effect' => $data['effect'],
                'amount' => $data['amount'], 'currency_code' => $due->currency_code, 'reason' => $data['reason'], 'status' => 'draft',
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->log((int) $actor->id, 'rental.settlement.created', 'aims_rental_settlements', $id, null, $data);

            return $id;
        });
    }

    /**
     * Edit a request while it is a draft or returned to the officer.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, string $type, int $id, array $data): void
    {
        DB::transaction(function () use ($actor, $type, $id, $data): void {
            $table = self::TYPES[$type]['table'];
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (! in_array($row->status, self::EDITABLE, true)) {
                throw ValidationException::withMessages(['status' => 'لا يُعدَّل الطلب إلا وهو مسودة أو مُعاد لمسؤول الملف.']);
            }
            $after = match ($type) {
                'application' => $this->updateApplication($id, $data),
                'contract' => $this->updateContract($id, $data),
                'case' => $this->updateTermination($row, $data),
                'settlement' => $this->updateSettlement($row, $data),
            };
            $this->audit->log((int) $actor->id, 'rental.'.($type === 'case' ? 'termination' : $type).'.updated', $table, $id, (array) $row, $after);
        });
    }

    /** Send a draft to its workflow; returned requests go back through the workflow's own action. */
    public function submit(User $actor, string $type, int $id): object
    {
        return DB::transaction(function () use ($actor, $type, $id): object {
            ['table' => $table, 'definition' => $definition, 'subject' => $subject] = self::TYPES[$type];
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'draft' || $row->workflow_instance_id) {
                throw ValidationException::withMessages(['status' => 'أُرسل الطلب مسبقًا؛ تابعه من مراحله.']);
            }
            match ($type) {
                'application' => $this->checkApplication((array) $row),
                'contract' => $this->checkContractReady($row),
                'case' => $this->checkTermination(['contract_id' => (int) $row->contract_id, 'termination_reason_value_id' => (int) $row->termination_reason_value_id, 'departure_on' => $row->departure_on, 'reason' => $row->reason], $id),
                'settlement' => $this->checkSettlement(['due_id' => (int) $row->due_id, 'type_value_id' => (int) $row->type_value_id, 'effect' => $row->effect, 'amount' => (string) $row->amount, 'reason' => $row->reason]),
            };
            $instance = $this->engine->startForSubject($actor, $definition, $subject, $id, $this->access->departmentId($actor));
            DB::table($table)->where('id', $id)->update(['workflow_instance_id' => $instance->id, 'updated_at' => now()]);

            return $instance;
        });
    }

    /** Custom schedule lines of a draft or returned contract (frequency «custom»). */
    public function addCustomDue(User $actor, int $contractId, string $dueOn, string $amount): void
    {
        DB::transaction(function () use ($actor, $contractId, $dueOn, $amount): void {
            $contract = DB::table('aims_rental_contracts')->where('id', $contractId)->lockForUpdate()->first();
            abort_unless($contract, 404);
            if ($contract->frequency !== 'custom' || ! in_array($contract->status, self::EDITABLE, true)) {
                throw ValidationException::withMessages(['due_on' => 'الاستحقاقات المخصصة لعقد بجدول مخصص وهو مسودة أو مُعاد.']);
            }
            if ($dueOn < $contract->starts_on || $dueOn > $contract->ends_on) {
                throw ValidationException::withMessages(['due_on' => 'تاريخ الاستحقاق خارج مدة العقد.']);
            }
            $inserted = DB::table('aims_rental_dues')->insertOrIgnore(['contract_id' => $contractId, 'due_on' => $dueOn, 'kind' => 'rent', 'currency_code' => $contract->currency_code,
                'amount' => $amount, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted === 0) {
                throw ValidationException::withMessages(['due_on' => 'يوجد استحقاق إيجار بهذا التاريخ.']);
            }
            $this->audit->log((int) $actor->id, 'rental.due.custom.created', 'aims_rental_contracts', $contractId, null, ['due_on' => $dueOn, 'amount' => $amount]);
        });
    }

    public function removeCustomDue(User $actor, int $dueId): void
    {
        DB::transaction(function () use ($actor, $dueId): void {
            $due = DB::table('aims_rental_dues')->where('id', $dueId)->lockForUpdate()->first();
            $contract = $due ? DB::table('aims_rental_contracts')->where('id', $due->contract_id)->lockForUpdate()->first() : null;
            abort_unless($due && $contract, 404);
            if (! in_array($contract->status, self::EDITABLE, true)) {
                throw ValidationException::withMessages(['due' => 'لا يُحذف استحقاق إلا من عقد مسودة أو مُعاد؛ بعد التفعيل يُعالج بتسوية.']);
            }
            DB::table('aims_rental_dues')->where('id', $dueId)->delete();
            $this->audit->log((int) $actor->id, 'rental.due.custom.removed', 'aims_rental_contracts', (int) $contract->id, ['due_on' => $due->due_on, 'amount' => $due->amount], []);
        });
    }

    /** @param array<string, mixed> $data */
    private function checkApplication(array $data): void
    {
        if (! DB::table('aims_rental_tenants')->where('id', $data['tenant_id'] ?? 0)->exists()) {
            throw ValidationException::withMessages(['tenant_id' => 'المستأجر غير موجود.']);
        }
        if (! empty($data['unit_id'])) {
            $unit = DB::table('aims_property_units')->where('id', $data['unit_id'])->first();
            if (! $unit || ! $unit->rentable || in_array($unit->availability, ['draft', 'out_of_service'], true)) {
                throw ValidationException::withMessages(['unit_id' => 'العين غير قابلة للتأجير أو لم تُعتمد جاهزيتها بعد.']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function updateApplication(int $id, array $data): array
    {
        $this->checkApplication($data);
        $after = ['tenant_id' => $data['tenant_id'], 'unit_id' => $data['unit_id'] ?? null, 'purpose' => $data['purpose'] ?? null, 'study' => $data['study'] ?? null];
        DB::table('aims_rental_applications')->where('id', $id)->update($after + ['updated_at' => now()]);

        return $after;
    }

    /** @param array<string, mixed> $data */
    private function updateContract(int $id, array $data): array
    {
        $this->checkContract($data, $id);
        DB::table('aims_rental_contracts')->where('id', $id)->update($this->contractRow($data) + ['updated_at' => now()]);
        DB::table('aims_rental_contract_units')->where('contract_id', $id)->delete();
        DB::table('aims_rental_contract_services')->where('contract_id', $id)->delete();
        $this->saveContractLines($id, $data);

        return $this->contractRow($data);
    }

    /** @param array<string, mixed> $data */
    private function updateTermination(object $case, array $data): array
    {
        $data['contract_id'] = (int) $case->contract_id;
        $this->checkTermination($data, (int) $case->id);
        $after = ['termination_reason_value_id' => $data['termination_reason_value_id'], 'departure_on' => $data['departure_on'], 'effective_on' => $data['departure_on'],
            'reason' => $data['reason'], 'details' => $data['details'] ?? null];
        DB::table('aims_rental_cases')->where('id', $case->id)->update($after + ['updated_at' => now()]);

        return $after;
    }

    /** @param array<string, mixed> $data */
    private function updateSettlement(object $settlement, array $data): array
    {
        $data['due_id'] = (int) $settlement->due_id;
        $this->checkSettlement($data);
        $after = ['type_value_id' => $data['type_value_id'], 'effect' => $data['effect'], 'amount' => $data['amount'], 'reason' => $data['reason']];
        DB::table('aims_rental_settlements')->where('id', $settlement->id)->update($after + ['updated_at' => now()]);

        return $after;
    }

    /** @param array<string, mixed> $data */
    private function checkContract(array $data, ?int $contractId): void
    {
        if (DB::table('aims_rental_contracts')->where('number', $data['number'])->when($contractId, fn ($q) => $q->where('id', '<>', $contractId))->exists()) {
            throw ValidationException::withMessages(['number' => 'رقم العقد مستخدم.']);
        }
        if (! DB::table('currencies')->where('code', $data['currency_code'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['currency_code' => 'العملة غير فعالة في الثوابت المالية.']);
        }
        $kind = $data['kind'] ?? 'new';
        $parent = null;
        if ($kind !== 'new') {
            $parent = DB::table('aims_rental_contracts')->where('id', $data['parent_contract_id'] ?? 0)->first();
            if (! $parent || ! in_array($parent->status, RentalContracts::OCCUPYING, true) || (int) $parent->tenant_id !== (int) $data['tenant_id']) {
                throw ValidationException::withMessages(['parent_contract_id' => 'العقد الأصلي يجب أن يكون فعالًا أو منتهي المدة وللمستأجر نفسه.']);
            }
            if ($kind === 'renewal' && $data['starts_on'] <= $parent->ends_on) {
                throw ValidationException::withMessages(['starts_on' => 'يبدأ التجديد بعد نهاية العقد الأصلي ('.$parent->ends_on.')؛ للتعديل خلال المدة استخدم «ملحق / تعديل».']);
            }
            if ($kind === 'amendment' && ($data['starts_on'] <= $parent->starts_on || $data['starts_on'] > $parent->ends_on)) {
                throw ValidationException::withMessages(['starts_on' => 'تاريخ سريان الملحق يقع داخل مدة العقد الأصلي وبعد بدايته.']);
            }
            if (trim((string) ($data['change_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['change_reason' => 'اذكر سبب التجديد أو التعديل.']);
            }
        } elseif (! empty($data['application_id'])) {
            $application = DB::table('aims_rental_applications')->where('id', $data['application_id'])->first();
            if (! $application || $application->status !== 'approved' || (int) $application->tenant_id !== (int) $data['tenant_id']) {
                throw ValidationException::withMessages(['application_id' => 'يجب أن يكون طلب الاستئجار مقبولًا ويتبع المستأجر نفسه.']);
            }
            if ($application->unit_id && ! collect($data['items'])->contains(fn (array $item): bool => (int) $item['unit_id'] === (int) $application->unit_id)) {
                throw ValidationException::withMessages(['items' => 'العقد لا يشمل العين التي قُبل طلبها.']);
            }
        }
        $parentUnits = $parent ? DB::table('aims_rental_contract_units')->where('contract_id', $parent->id)->pluck('unit_id')->map(fn ($id): int => (int) $id)->all() : [];
        foreach ($data['items'] as $index => $item) {
            $unit = DB::table('aims_property_units')->where('id', $item['unit_id'])->first();
            $allowed = $unit && $unit->rentable && (in_array($unit->availability, ['vacant', 'reserved'], true) || in_array((int) $unit->id, $parentUnits, true));
            if (! $allowed) {
                throw ValidationException::withMessages(["items.{$index}.unit_id" => 'العين غير متاحة للتعاقد (يجب أن تكون شاغرة أو محجوزة لهذا الطلب أو من أعيان العقد الأصلي).']);
            }
        }
        $sum = array_sum(array_map(fn (array $item): int => FinanceMoney::cents((string) $item['rent_share']), $data['items']));
        if ($sum !== FinanceMoney::cents((string) $data['total_rent'])) {
            throw ValidationException::withMessages(['total_rent' => 'مجموع حصص الأعيان يجب أن يساوي قيمة الدفعة.']);
        }
        foreach ($data['services'] ?? [] as $index => $service) {
            $this->constants->assert('rental_services', (int) $service['service_value_id'], "services.{$index}.service_value_id", true);
            if ($service['billing'] === 'separate' && (float) ($service['amount'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["services.{$index}.amount" => 'حدد قيمة الخدمة المدفوعة منفصلة لكل دفعة.']);
            }
        }
    }

    private function checkContractReady(object $contract): void
    {
        if (! DB::table('aims_rental_contract_units')->where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages(['items' => 'العقد بلا أعيان.']);
        }
        if ($contract->frequency === 'custom' && ! DB::table('aims_rental_dues')->where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages(['frequency' => 'الجدول مخصص: أدخل الاستحقاقات قبل الرفع للاعتماد.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function contractRow(array $data): array
    {
        $kind = $data['kind'] ?? 'new';

        return [
            'number' => $data['number'], 'tenant_id' => $data['tenant_id'], 'kind' => $kind,
            'application_id' => $kind === 'new' ? ($data['application_id'] ?? null) : null,
            'parent_contract_id' => $kind === 'new' ? null : $data['parent_contract_id'],
            'issued_on' => $data['issued_on'] ?? null, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'currency_code' => $data['currency_code'],
            'frequency' => $data['frequency'], 'total_rent' => $data['total_rent'], 'auto_renew' => (bool) ($data['auto_renew'] ?? false),
            'renewal_clause' => $data['renewal_clause'] ?? null, 'terms' => $data['terms'] ?? null, 'change_reason' => $kind === 'new' ? null : ($data['change_reason'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveContractLines(int $contractId, array $data): void
    {
        foreach ($data['items'] as $item) {
            DB::table('aims_rental_contract_units')->insert(['contract_id' => $contractId, 'unit_id' => $item['unit_id'], 'rent_share' => $item['rent_share'],
                'rent_per_m2' => $item['rent_per_m2'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($data['services'] ?? [] as $service) {
            DB::table('aims_rental_contract_services')->insert(['contract_id' => $contractId, 'service_value_id' => $service['service_value_id'], 'billing' => $service['billing'],
                'amount' => $service['billing'] === 'separate' ? $service['amount'] : null, 'notes' => $service['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<string, mixed> $data */
    private function checkTermination(array $data, ?int $caseId): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $data['contract_id'])->first();
        if (! $contract || ! in_array($contract->status, RentalContracts::OCCUPYING, true)) {
            throw ValidationException::withMessages(['contract_id' => 'الإنهاء لعقد فعال أو منتهي المدة لم يُستلم بعد.']);
        }
        $open = DB::table('aims_rental_cases')->where('contract_id', $contract->id)->where('kind', 'eviction')
            ->whereNotIn('status', ['rejected', 'completed', 'cancelled'])->when($caseId, fn ($q) => $q->where('id', '<>', $caseId))->exists();
        if ($open) {
            throw ValidationException::withMessages(['contract_id' => 'توجد معاملة إنهاء مفتوحة لهذا العقد.']);
        }
        $this->constants->assert('rental_termination_reasons', (int) $data['termination_reason_value_id'], 'termination_reason_value_id', true);
        if ($data['departure_on'] < $contract->starts_on) {
            throw ValidationException::withMessages(['departure_on' => 'تاريخ المغادرة قبل بداية العقد.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function checkSettlement(array $data): object
    {
        $due = DB::table('aims_rental_dues')->where('id', $data['due_id'])->first();
        if (! $due || $due->status === 'cancelled') {
            throw ValidationException::withMessages(['due_id' => 'الاستحقاق غير موجود أو ملغى.']);
        }
        $this->constants->assert('rental_settlement_types', (int) $data['type_value_id'], 'type_value_id', true);
        if (! array_key_exists($data['effect'], RentalSettlementWorkflow::EFFECTS)) {
            throw ValidationException::withMessages(['effect' => 'اختر أثر التسوية.']);
        }
        if ($data['effect'] === 'reduce' && MoneyRules::toMinor((string) $data['amount']) > $this->ledger->remainingMinor((int) $due->id)) {
            throw ValidationException::withMessages(['amount' => 'مبلغ التخفيض يتجاوز المتبقي على الاستحقاق.']);
        }

        return $due;
    }
}
