<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Rentals\RentalApplicationWorkflow;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalConstants;
use App\Services\Rentals\RentalContracts;
use App\Services\Rentals\RentalDocuments;
use App\Services\Rentals\RentalLedger;
use App\Services\Rentals\RentalMaintenance;
use App\Services\Rentals\RentalRequests;
use App\Services\Rentals\RentalsAccess;
use App\Services\Rentals\RentalSettlementWorkflow;
use App\Services\Rentals\RentalTerminationWorkflow;
use App\Services\Rentals\RentalUnits;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One rental request's page — application, contract, termination or settlement: its data, documents, the single
 * progress indicator derived from the workflow version it started on, and the actions the viewer holds now.
 * Opened by the rentals file, or by whoever takes part in that request (no wider access).
 */
class RentalRecordController extends Controller
{
    public function __construct(
        private readonly RentalsAccess $access,
        private readonly RentalDocuments $documents,
        private readonly RentalLedger $ledger,
        private readonly WorkflowEngine $engine,
    ) {}

    public function show(Request $r, string $type, int $id, WorkflowProgress $progress, WorkflowVisibilityService $visibility, RentalConstants $constants, RentalMaintenance $maintenance): Response
    {
        [$row, $instance] = $this->load($r, $type, $id);
        $user = $r->user();
        $level = $instance ? ($visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : 'operational';
        $manage = $this->access->canManage($user);

        return Inertia::render('Admin/Rentals/Record', [
            'type' => $type,
            'record' => $row,
            'context' => $this->context($type, $row, $maintenance),
            'documents' => $this->documents->list($type, $id),
            'progress' => $instance ? $progress->view($instance, $level) : null,
            'workflow' => $instance ? [
                'publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'step' => DB::table('workflow_steps')->where('id', $instance->current_step_id)->value('code'),
                'actions' => array_values(array_filter($this->engine->availableActions($instance, $user), fn (array $action): bool => ! ($type === 'settlement' && $action['action_code'] === 'post'))),
            ] : null,
            'options' => $constants->options(),
            'labels' => ['units' => RentalUnits::LABELS, 'contracts' => RentalContracts::LABELS, 'kinds' => RentalContracts::KINDS, 'applications' => RentalApplicationWorkflow::LABELS,
                'cases' => RentalTerminationWorkflow::LABELS, 'settlements' => RentalSettlementWorkflow::LABELS, 'effects' => RentalSettlementWorkflow::EFFECTS],
            'can' => [
                'manage' => $manage,
                'edit' => $manage && in_array($row->status, RentalRequests::EDITABLE, true),
                'submit' => $manage && $row->status === 'draft' && ! $row->workflow_instance_id,
                'upload' => $this->canUpload($user, $instance),
                'outcome' => $type === 'case' && $manage && $row->status === 'approved',
                'post' => $type === 'settlement' && $instance && (int) $instance->current_assignee_id === (int) $user->id && $row->status === 'approved' && $this->access->canRecordFinance($user),
            ],
            'editor' => $manage && in_array($row->status, RentalRequests::EDITABLE, true) ? $this->editorData($type) : null,
        ]);
    }

    public function update(Request $r, string $type, int $id, RentalRequests $requests): RedirectResponse
    {
        abort_unless(isset(RentalRequests::TYPES[$type]) && $this->access->canManage($r->user()), 403);
        $data = match ($type) {
            'application' => $r->validate(['tenant_id' => 'required|integer|exists:aims_rental_tenants,id', 'unit_id' => 'nullable|integer|exists:aims_property_units,id',
                'purpose' => 'nullable|string|max:4000', 'study' => 'nullable|string|max:8000']),
            'contract' => $r->validate(PropertyRentalsController::contractRules()),
            'case' => $r->validate(['termination_reason_value_id' => 'required|integer', 'departure_on' => 'required|date', 'reason' => 'required|string|max:4000', 'details' => 'nullable|string|max:10000']),
            'settlement' => $r->validate(['type_value_id' => 'required|integer', 'effect' => ['required', Rule::in(array_keys(RentalSettlementWorkflow::EFFECTS))],
                'amount' => 'required|numeric|min:0.01|max:999999999999', 'reason' => 'required|string|max:4000']),
        };
        if ($type === 'settlement') {
            $data['amount'] = number_format((float) $data['amount'], 2, '.', '');
        }
        $requests->update($r->user(), $type, $id, $data);

        return back()->with('success', 'حُفظت التعديلات.');
    }

    public function submit(Request $r, string $type, int $id, RentalRequests $requests): RedirectResponse
    {
        abort_unless(isset(RentalRequests::TYPES[$type]) && $this->access->canManage($r->user()), 403);
        $instance = $requests->submit($r->user(), $type, $id);

        return back()->with('success', 'أُرسل الطلب برقم '.$instance->reference_no.'. تابع مراحله من هذه الصفحة.');
    }

    public function document(Request $r, string $owner, int $id): RedirectResponse
    {
        abort_unless(isset(RentalDocuments::OWNERS[$owner]), 404);
        $instanceId = isset(RentalRequests::TYPES[$owner]) ? DB::table(RentalRequests::TYPES[$owner]['table'])->where('id', $id)->value('workflow_instance_id') : null;
        $instance = $instanceId ? DB::table('workflow_instances')->where('id', $instanceId)->first() : null;
        abort_unless($this->canUpload($r->user(), $instance), 403, 'رفع المستندات لمسؤول ملف الإيجارات ولصاحب المرحلة الحالية في الطلب.');
        $d = $r->validate(['file' => 'required|file|mimes:'.RentalDocuments::MIMES.'|max:20480', 'document_type_value_id' => 'nullable|integer',
            'title' => 'required|string|max:255', 'supersedes_id' => 'nullable|integer']);
        $this->documents->store($r->user(), $owner, $id, $r->file('file'), $d['document_type_value_id'] ?? null, $d['title'], $d['supersedes_id'] ?? null);

        return back()->with('success', 'حُفظ المستند في الملف.');
    }

    public function download(Request $r, int $document): StreamedResponse
    {
        $row = $this->documents->owner($document);
        abort_unless($row, 404);
        $instanceId = isset(RentalRequests::TYPES[$row->owner_type]) ? DB::table(RentalRequests::TYPES[$row->owner_type]['table'])->where('id', $row->owner_id)->value('workflow_instance_id') : null;
        abort_unless($this->access->canSeeRecord($r->user(), $instanceId ? (int) $instanceId : null), 403);

        return $this->documents->download($r->user(), $row);
    }

    /** Handover record of a termination: the unit's state after eviction and any damage, before completing. */
    public function outcome(Request $r, int $id, RentalAudit $audit): RedirectResponse
    {
        abort_unless($this->access->canManage($r->user()), 403);
        $d = $r->validate(['outcome_unit_status' => ['required', Rule::in(['vacant', 'maintenance', 'reserved', 'suspended'])], 'damages' => 'nullable|string|max:10000']);
        $updated = DB::table('aims_rental_cases')->where('id', $id)->where('status', 'approved')->update($d + ['updated_at' => now()]);
        abort_unless($updated, 409, 'محضر الاستلام بعد اعتماد المدير التنفيذي للإنهاء.');
        $audit->log((int) $r->user()->id, 'rental.termination.handover', 'aims_rental_cases', $id, null, $d);

        return back()->with('success', 'حُفظ محضر الاستلام. أتمّ المعاملة من «الإجراء المطلوب منك».');
    }

    /** Accounts record the posting reference from the accounting software, then complete the settlement. */
    public function post(Request $r, int $id): RedirectResponse
    {
        $user = $r->user();
        abort_unless($this->access->canRecordFinance($user), 403, 'تسجيل الترحيل لموظف الحسابات المخوّل.');
        $d = $r->validate(['posting_reference' => 'required|string|max:150', 'posting_date' => 'required|date|before_or_equal:today', 'comment' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($id, $user, $d): void {
            $settlement = DB::table('aims_rental_settlements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($settlement && $settlement->status === 'approved' && $settlement->workflow_instance_id, 409, 'التسوية ليست بانتظار الترحيل.');
            $instance = DB::table('workflow_instances')->where('id', $settlement->workflow_instance_id)->first();
            $transition = DB::table('workflow_transitions')->where('workflow_version_id', $instance->workflow_version_id)
                ->where('from_step_id', $instance->current_step_id)->where('action_code', 'post')->value('id');
            abort_unless($transition, 409, 'التسوية ليست في مرحلة الترحيل.');
            DB::table('aims_rental_settlements')->where('id', $id)->update(['posting_reference' => $d['posting_reference'], 'posting_date' => $d['posting_date'], 'updated_at' => now()]);
            $this->engine->act((int) $instance->id, $user, (int) $transition, $d['comment'] ?? ('قيد الترحيل '.$d['posting_reference']), [], null, (int) $instance->revision);
        });

        return back()->with('success', 'سُجل الترحيل وانعكس على رصيد الاستحقاق.');
    }

    /** @return array{0: object, 1: ?object} */
    private function load(Request $r, string $type, int $id): array
    {
        abort_unless(isset(RentalRequests::TYPES[$type]), 404);
        $row = DB::table(RentalRequests::TYPES[$type]['table'])->where('id', $id)->first();
        abort_unless($row, 404);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        abort_unless($r->user() && $this->access->canSeeRecord($r->user(), $instance ? (int) $instance->id : null), 403, 'هذا الطلب خارج نطاق اطلاعك.');

        return [$row, $instance];
    }

    private function canUpload(?User $user, ?object $instance): bool
    {
        return $user && ($this->access->canManage($user) || ($instance && $instance->status === 'in_progress' && (int) $instance->current_assignee_id === (int) $user->id));
    }

    /** @return array<string, mixed> */
    private function context(string $type, object $row, RentalMaintenance $maintenance): array
    {
        $tenant = fn (?int $id) => $id ? DB::table('aims_rental_tenants')->where('id', $id)->first(['id', 'type', 'name', 'representative', 'identity_number', 'phone', 'phone2', 'email', 'address', 'activity']) : null;
        $unit = fn (?int $id) => $id ? DB::table('aims_property_units as u')->join('aims_properties as p', 'p.id', '=', 'u.property_id')->where('u.id', $id)
            ->first(['u.id', 'u.code', 'u.name', 'u.availability', 'u.area_m2', 'u.directions', 'p.name as property']) : null;
        $contractUnits = fn (int $contractId) => DB::table('aims_rental_contract_units as cu')->join('aims_property_units as u', 'u.id', '=', 'cu.unit_id')
            ->join('aims_properties as p', 'p.id', '=', 'u.property_id')->where('cu.contract_id', $contractId)
            ->get(['cu.unit_id', 'cu.rent_share', 'cu.rent_per_m2', 'cu.starts_on', 'cu.ends_on', 'cu.occupies', 'u.code', 'u.name', 'u.availability', 'u.area_m2', 'p.name as property']);
        $value = fn (?int $id) => $id ? DB::table('master_data_values')->where('id', $id)->value('name_ar') : null;

        return match ($type) {
            'application' => ['tenant' => $tenant((int) $row->tenant_id), 'unit' => $unit($row->unit_id ? (int) $row->unit_id : null),
                'contracts' => DB::table('aims_rental_contracts')->where('application_id', $row->id)->get(['id', 'number', 'status'])],
            'contract' => $this->contractContext($row, $tenant, $contractUnits, $maintenance),
            'case' => ['contract' => DB::table('aims_rental_contracts')->where('id', $row->contract_id)->first(['id', 'number', 'status', 'starts_on', 'ends_on', 'tenant_id']),
                'tenant' => $tenant((int) DB::table('aims_rental_contracts')->where('id', $row->contract_id)->value('tenant_id')),
                'units' => $contractUnits((int) $row->contract_id), 'reason' => $value($row->termination_reason_value_id ? (int) $row->termination_reason_value_id : null),
                'readings' => DB::table('aims_rental_meter_readings')->where('case_id', $row->id)->orderByDesc('id')->get(),
                'dues' => $this->ledger->dues()->where('d.contract_id', $row->contract_id)->orderBy('d.due_on')->get()],
            'settlement' => ['due' => $this->ledger->dues()->where('d.id', $row->due_id)->first(), 'type' => $value((int) $row->type_value_id),
                'contract' => DB::table('aims_rental_contracts')->where('id', $row->contract_id)->first(['id', 'number', 'status', 'tenant_id']),
                'tenant' => $tenant((int) DB::table('aims_rental_contracts')->where('id', $row->contract_id)->value('tenant_id'))],
        };
    }

    /** @return array<string, mixed> */
    private function contractContext(object $row, callable $tenant, callable $contractUnits, RentalMaintenance $maintenance): array
    {
        $dues = $this->ledger->dues()->where('d.contract_id', $row->id)->orderBy('d.due_on')->orderBy('d.kind');

        return [
            'tenant' => $tenant((int) $row->tenant_id),
            'units' => $contractUnits((int) $row->id),
            'services' => DB::table('aims_rental_contract_services as s')->join('master_data_values as v', 'v.id', '=', 's.service_value_id')
                ->where('s.contract_id', $row->id)->get(['s.service_value_id', 's.billing', 's.amount', 's.notes', 'v.name_ar as name']),
            'dues' => (clone $dues)->get(),
            'totals' => $this->ledger->totals($dues),
            'receipts' => DB::table('aims_rental_receipt_allocations as a')->join('aims_rental_dues as d', 'd.id', '=', 'a.due_id')->leftJoin('users as u', 'u.id', '=', 'a.recorded_by')
                ->where('d.contract_id', $row->id)->orderByDesc('a.id')
                ->get(['a.id', 'a.due_id', 'd.due_on', 'a.accounting_reference', 'a.amount', 'a.currency_code', 'a.accounting_posted_at', 'a.external_system', 'a.reversed_at', 'a.reversal_reason', 'u.name as recorded_by']),
            'settlements' => DB::table('aims_rental_settlements')->where('contract_id', $row->id)->orderByDesc('id')->get(['id', 'due_id', 'effect', 'amount', 'currency_code', 'status', 'posting_reference']),
            'readings' => DB::table('aims_rental_meter_readings')->where('contract_id', $row->id)->orderByDesc('id')->get(),
            'parent' => $row->parent_contract_id ? DB::table('aims_rental_contracts')->where('id', $row->parent_contract_id)->first(['id', 'number', 'status', 'starts_on', 'ends_on']) : null,
            'children' => DB::table('aims_rental_contracts')->where('parent_contract_id', $row->id)->orderBy('id')->get(['id', 'number', 'kind', 'status', 'starts_on', 'ends_on']),
            'application' => $row->application_id ? DB::table('aims_rental_applications')->where('id', $row->application_id)->first(['id', 'status']) : null,
            'cases' => DB::table('aims_rental_cases')->where('contract_id', $row->id)->where('kind', 'eviction')->orderByDesc('id')->get(['id', 'status', 'departure_on']),
            'maintenance' => $maintenance->linked(null, (int) $row->id),
            'issuedNotices' => DB::table('aims_rental_notices')->where('contract_id', $row->id)->orderByDesc('id')->get(['id', 'kind', 'status', 'sent_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function editorData(string $type): array
    {
        return match ($type) {
            'application' => ['tenants' => DB::table('aims_rental_tenants')->orderBy('name')->get(['id', 'name']),
                'units' => DB::table('aims_property_units as u')->join('aims_properties as p', 'p.id', '=', 'u.property_id')->where('u.rentable', true)
                    ->whereNotIn('u.availability', ['draft', 'out_of_service'])->orderBy('p.name')->get(['u.id', 'u.code', 'u.name', 'u.availability', 'p.name as property'])],
            'contract' => ['tenants' => DB::table('aims_rental_tenants')->orderBy('name')->get(['id', 'name']),
                'units' => DB::table('aims_property_units as u')->join('aims_properties as p', 'p.id', '=', 'u.property_id')->where('u.rentable', true)
                    ->whereIn('u.availability', ['vacant', 'reserved', 'rented'])->orderBy('p.name')->get(['u.id', 'u.code', 'u.name', 'u.availability', 'u.area_m2', 'p.name as property']),
                'applications' => DB::table('aims_rental_applications')->where('status', 'approved')->get(['id', 'tenant_id', 'unit_id']),
                'parents' => DB::table('aims_rental_contracts')->whereIn('status', RentalContracts::OCCUPYING)->get(['id', 'number', 'tenant_id', 'starts_on', 'ends_on']),
                'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')],
            default => [],
        };
    }
}
