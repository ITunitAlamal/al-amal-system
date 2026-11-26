<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Configuration\GazaAddress;
use App\Services\Rentals\RentalAlerts;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The assets, buildings and rentals file. Reading needs rentals.view and every change rentals.manage (the role
 * «مسؤول ملف الإيجارات», or heading the assets department: User::DEPARTMENT_HEAD_PERMISSIONS). Decisions are not
 * taken here: applications, contracts, terminations and settlements are sent to the central workflow engine,
 * where the executive director decides and Accounts record collections and postings. The system administrator
 * keeps full technical access, audited, but decides nothing.
 */
class PropertyRentalsController extends Controller
{
    public function __construct(
        private readonly RentalsAccess $access,
        private readonly RentalLedger $ledger,
        private readonly RentalConstants $constants,
        private readonly RentalAudit $audit,
    ) {}

    private function authorizeAccess(Request $request): void
    {
        $manage = ! $request->isMethodSafe();
        abort_unless($request->user() && ($manage ? $this->access->canManage($request->user()) : $this->access->canView($request->user())), 403,
            'لا تملك صلاحية '.($manage ? 'التعديل في' : 'الاطلاع على').' وحدة الأصول والإيجارات.');
    }

    public function buildings(Request $request): Response
    {
        return $this->page($request, true);
    }

    public function index(Request $request): Response
    {
        return $this->page($request, false);
    }

    private function page(Request $request, bool $manageRegistry): Response
    {
        $this->authorizeAccess($request);
        $user = $request->user();
        $instances = fn (string $table, string $alias) => DB::table("{$table} as {$alias}")->leftJoin('workflow_instances as wi', 'wi.id', '=', "{$alias}.workflow_instance_id")
            ->leftJoin('workflow_steps as ws', 'ws.id', '=', 'wi.current_step_id');
        $dues = $this->ledger->dues()->orderBy('d.due_on')->limit(2000);
        $masterBuildings = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->join('master_data_groups as g', 'g.id', '=', 't.group_id')
            ->where('g.code', 'buildings')->where('g.is_active', true)->where('t.is_active', true)->where('v.is_active', true)
            ->select('v.id', 'v.name_ar', 'v.code', 't.name_ar as type_name')->orderBy('v.name_ar')->get();
        $priorityType = DB::table('custom_field_definitions')->where('scope_key', 'MAINTENANCE')->where('code', 'priority')->value('master_data_type_id');

        return Inertia::render('Admin/Rentals/Index', [
            'properties' => DB::table('aims_properties')->orderBy('name')->get(),
            'floors' => DB::table('aims_property_floors')->orderBy('property_id')->orderBy('sort_order')->get(),
            'units' => DB::table('aims_property_units')->orderBy('property_id')->orderBy('code')->get(),
            'unitEvents' => DB::table('aims_property_unit_status_events as e')->leftJoin('users as u', 'u.id', '=', 'e.changed_by')
                ->orderByDesc('e.id')->limit(100)->get(['e.unit_id', 'e.from_status', 'e.to_status', 'e.reason', 'e.source', 'e.created_at', 'u.name as actor']),
            'tenants' => DB::table('aims_rental_tenants')->orderBy('name')->limit(1000)->get(),
            'applications' => $instances('aims_rental_applications', 'a')->orderByDesc('a.id')->limit(300)
                ->get(['a.*', 'wi.reference_no', 'ws.name as step']),
            'contracts' => $instances('aims_rental_contracts', 'c')->orderByDesc('c.id')->limit(500)
                ->get(['c.*', 'wi.reference_no', 'ws.name as step']),
            'contractUnits' => DB::table('aims_rental_contract_units')->get(),
            'dues' => $dues->get(),
            'totals' => $this->ledger->totals($this->ledger->dues()),
            'cases' => $instances('aims_rental_cases', 'k')->where('k.kind', 'eviction')->orderByDesc('k.id')->limit(300)
                ->get(['k.*', 'wi.reference_no', 'ws.name as step']),
            'settlements' => $instances('aims_rental_settlements', 's')->orderByDesc('s.id')->limit(300)
                ->get(['s.*', 'wi.reference_no', 'ws.name as step']),
            'readings' => DB::table('aims_rental_meter_readings as m')->leftJoin('users as u', 'u.id', '=', 'm.recorded_by')->orderByDesc('m.id')->limit(200)
                ->get(['m.*', 'u.name as recorded_by_name']),
            'notices' => DB::table('aims_rental_notices')->orderByDesc('id')->limit(200)->get(),
            'registryDocuments' => DB::table('aims_rental_documents as d')->leftJoin('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
                ->whereIn('d.owner_type', ['property', 'unit', 'tenant'])->orderByDesc('d.id')->limit(1000)
                ->get(['d.id', 'd.owner_type', 'd.owner_id', 'd.title', 'd.version', 'd.supersedes_id', 'v.name_ar as type']),
            'options' => $this->constants->options(),
            'maintenancePriorities' => $priorityType ? DB::table('master_data_values')->where('type_id', $priorityType)->where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']) : [],
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code'),
            'labels' => ['units' => RentalUnits::LABELS, 'manual' => RentalUnits::MANUAL, 'contracts' => RentalContracts::LABELS, 'kinds' => RentalContracts::KINDS,
                'applications' => RentalApplicationWorkflow::LABELS, 'cases' => RentalTerminationWorkflow::LABELS, 'settlements' => RentalSettlementWorkflow::LABELS,
                'effects' => RentalSettlementWorkflow::EFFECTS],
            'settings' => app(RentalAlerts::class)->settings(),
            'manageRegistry' => $manageRegistry,
            'canDecide' => $this->access->canDecide($user),
            'canManage' => $this->access->canManage($user),
            'isAdmin' => $user->isSystemAdmin(),
            'masterBuildings' => $masterBuildings,
        ]);
    }

    public function property(Request $r, GazaAddress $address): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['master_building_value_id' => 'required|integer|exists:master_data_values,id'] + $this->propertyRules());
        $this->constants->assert('asset_types', $d['kind_value_id'] ?? null, 'kind_value_id');
        $this->constants->assert('property_ownership_types', $d['ownership_value_id'] ?? null, 'ownership_value_id');
        $d = $address->rentalFields($d, 'location');
        $master = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->join('master_data_groups as g', 'g.id', '=', 't.group_id')
            ->where('v.id', $d['master_building_value_id'])->where('g.code', 'buildings')->where('g.is_active', true)->where('t.is_active', true)->where('v.is_active', true)
            ->select('v.code', 'v.name_ar')->first();
        if (! $master) {
            throw ValidationException::withMessages(['master_building_value_id' => 'المبنى المختار ليس من ثوابت المباني الفعالة.']);
        }
        if (DB::table('aims_properties')->where('master_building_value_id', $d['master_building_value_id'])->orWhere('code', $master->code)->exists()) {
            throw ValidationException::withMessages(['master_building_value_id' => 'هذا المبنى أو رمزه مرتبط مسبقًا بسجل الأصول؛ عدّل السجل القائم بدل إنشاء مكرر.']);
        }
        $d['code'] = $master->code;
        $d['name'] = $master->name_ar;
        $id = DB::table('aims_properties')->insertGetId($d + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.property.created', 'aims_properties', $id, null, $d);

        return back()->with('success', 'تم تسجيل الأصل.');
    }

    public function propertyUpdate(Request $r, int $id, GazaAddress $address): RedirectResponse
    {
        $this->authorizeAccess($r);
        $before = DB::table('aims_properties')->where('id', $id)->first();
        abort_unless($before, 404);
        $d = $r->validate($this->propertyRules());
        $this->constants->assert('asset_types', $d['kind_value_id'] ?? null, 'kind_value_id');
        $this->constants->assert('property_ownership_types', $d['ownership_value_id'] ?? null, 'ownership_value_id');
        $d = $address->rentalFields($d, 'location');
        DB::table('aims_properties')->where('id', $id)->update($d + ['updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.property.updated', 'aims_properties', $id, (array) $before, $d);

        return back()->with('success', 'تم تحديث بيانات الأصل.');
    }

    /** @return array<string, mixed> */
    private function propertyRules(): array
    {
        return ['kind_value_id' => 'nullable|integer', 'ownership_value_id' => 'nullable|integer', 'plot_number' => 'nullable|string|max:50',
            'parcel_number' => 'nullable|string|max:50', 'location' => 'nullable|string|max:255', 'description' => 'nullable|string|max:2000', ...GazaAddress::rules()];
    }

    public function floor(Request $r): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['property_id' => 'required|integer|exists:aims_properties,id', 'name' => 'required|string|max:255', 'sort_order' => 'nullable|integer|min:-100|max:10000']);
        $d['sort_order'] = $d['sort_order'] ?? 0;
        if (DB::table('aims_property_floors')->where('property_id', $d['property_id'])->where('name', $d['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'يوجد طابق بهذا الاسم في المبنى.']);
        }
        $id = DB::table('aims_property_floors')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.floor.created', 'aims_property_floors', $id, null, $d);

        return back()->with('success', 'تم تسجيل الطابق.');
    }

    public function unit(Request $r): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['property_id' => 'required|integer|exists:aims_properties,id', 'code' => 'required|string|max:80'] + $this->unitRules());
        $this->checkUnit($d, null);
        $id = DB::table('aims_property_units')->insertGetId($d + ['availability' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('aims_property_unit_status_events')->insert(['unit_id' => $id, 'from_status' => null, 'to_status' => 'draft', 'reason' => 'تسجيل العين',
            'source' => 'manual', 'changed_by' => $r->user()->id, 'created_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.unit.created', 'aims_property_units', $id, null, $d);

        return back()->with('success', 'تم تسجيل العين «قيد الإعداد». بعد فحص جاهزيتها انقلها إلى «شاغرة».');
    }

    public function unitUpdate(Request $r, int $id): RedirectResponse
    {
        $this->authorizeAccess($r);
        $before = DB::table('aims_property_units')->where('id', $id)->first();
        abort_unless($before, 404);
        $d = $r->validate(['code' => 'required|string|max:80'] + $this->unitRules());
        $d['property_id'] = (int) $before->property_id;
        $this->checkUnit($d, $before);
        unset($d['property_id']);
        DB::table('aims_property_units')->where('id', $id)->update($d + ['updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.unit.updated', 'aims_property_units', $id, (array) $before, $d);

        return back()->with('success', 'تم تحديث بيانات العين.');
    }

    /** @return array<string, mixed> */
    private function unitRules(): array
    {
        return ['floor_id' => 'nullable|integer|exists:aims_property_floors,id', 'name' => 'required|string|max:255', 'type_value_id' => 'nullable|integer',
            'usage_value_id' => 'nullable|integer', 'area_m2' => 'nullable|numeric|min:0|max:99999999', 'has_mezzanine' => 'required|boolean',
            'mezzanine_area_m2' => 'nullable|numeric|min:0|max:99999999', 'directions' => 'nullable|string|max:255', 'rentable' => 'required|boolean', 'description' => 'nullable|string|max:2000'];
    }

    /** @param array<string, mixed> $d */
    private function checkUnit(array &$d, ?object $before): void
    {
        $this->constants->assert('rental_unit_types', $d['type_value_id'] ?? null, 'type_value_id');
        $this->constants->assert('rental_unit_usages', $d['usage_value_id'] ?? null, 'usage_value_id');
        $duplicate = DB::table('aims_property_units')->where('property_id', $d['property_id'])->where('code', $d['code'])->when($before, fn ($q) => $q->where('id', '<>', $before->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['code' => 'رقم العين مستخدم في هذا الأصل.']);
        }
        if (! empty($d['floor_id']) && ! DB::table('aims_property_floors')->where('id', $d['floor_id'])->where('property_id', $d['property_id'])->exists()) {
            throw ValidationException::withMessages(['floor_id' => 'الطابق لا يتبع الأصل المختار.']);
        }
        if ($before && $before->code !== $d['code'] && DB::table('aims_rental_contract_units')->where('unit_id', $before->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'لا يتغير رقم عين وردت في عقد؛ يبقى مرجعًا للعقود السابقة.']);
        }
        if ($before && ! $d['rentable'] && in_array($before->availability, ['rented', 'reserved'], true)) {
            throw ValidationException::withMessages(['rentable' => 'العين مؤجرة أو محجوزة؛ لا تُعلّم غير قابلة للتأجير الآن.']);
        }
        if (! $d['has_mezzanine']) {
            $d['mezzanine_area_m2'] = null;
        }
    }

    public function unitStatus(Request $r, int $id, RentalUnits $units): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['status' => ['required', Rule::in(array_keys(RentalUnits::LABELS))], 'reason' => 'required|string|min:3|max:2000']);
        $units->manual($id, $d['status'], $d['reason'], (int) $r->user()->id);
        $this->audit->log((int) $r->user()->id, 'rental.unit.status', 'aims_property_units', $id, null, $d);

        return back()->with('success', 'تم تغيير حالة العين وحُفظ السبب في سجلها.');
    }

    public function tenant(Request $r, GazaAddress $address): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $address->rentalFields($r->validate($this->tenantRules()), 'address');
        try {
            $id = DB::transaction(fn (): int => DB::table('aims_rental_tenants')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identity_number' => 'يوجد مستأجر مسجل بنفس رقم الهوية أو التسجيل؛ افتح ملفه بدل إنشاء ملف مكرر.']);
        }
        $this->audit->log((int) $r->user()->id, 'rental.tenant.created', 'aims_rental_tenants', $id, null, ['type' => $d['type'], 'name' => $d['name']]);

        return back()->with('success', 'تم إضافة المستأجر.');
    }

    public function tenantUpdate(Request $r, int $id, GazaAddress $address): RedirectResponse
    {
        $this->authorizeAccess($r);
        $before = DB::table('aims_rental_tenants')->where('id', $id)->first();
        abort_unless($before, 404);
        $d = $address->rentalFields($r->validate($this->tenantRules()), 'address');
        try {
            DB::transaction(fn () => DB::table('aims_rental_tenants')->where('id', $id)->update($d + ['updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identity_number' => 'يوجد مستأجر آخر بنفس رقم الهوية أو التسجيل.']);
        }
        $this->audit->log((int) $r->user()->id, 'rental.tenant.updated', 'aims_rental_tenants', $id,
            array_intersect_key((array) $before, $d), $d);

        return back()->with('success', 'تم تحديث ملف المستأجر.');
    }

    /** @return array<string, mixed> */
    private function tenantRules(): array
    {
        return ['type' => ['required', Rule::in(['person', 'company', 'association', 'institution'])], 'name' => 'required|string|max:255', 'identity_number' => 'nullable|string|max:80',
            'representative' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:60', 'phone2' => 'nullable|string|max:60', 'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:2000', 'activity' => 'nullable|string|max:2000', ...GazaAddress::rules()];
    }

    public function application(Request $r, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['tenant_id' => 'required|integer|exists:aims_rental_tenants,id', 'unit_id' => 'nullable|integer|exists:aims_property_units,id',
            'purpose' => 'nullable|string|max:4000', 'study' => 'nullable|string|max:8000']);
        $id = $requests->createApplication($r->user(), $d);

        return redirect('/admin/rentals/records/application/'.$id)->with('success', 'حُفظ طلب الاستئجار مسودة. أرفق المستندات ثم أرسله إلى المدير التنفيذي.');
    }

    public function contract(Request $r, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $id = $requests->createContract($r->user(), $this->contractInput($r));

        return redirect('/admin/rentals/records/contract/'.$id)->with('success', 'حُفظ العقد مسودة. راجعه ثم أرسله للاعتماد.');
    }

    /** @return array<string, mixed> */
    public static function contractRules(): array
    {
        return ['number' => 'required|string|max:100', 'tenant_id' => 'required|integer|exists:aims_rental_tenants,id', 'kind' => ['required', Rule::in(array_keys(RentalContracts::KINDS))],
            'application_id' => 'nullable|integer|exists:aims_rental_applications,id', 'parent_contract_id' => 'nullable|integer|exists:aims_rental_contracts,id',
            'issued_on' => 'nullable|date', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'currency_code' => 'required|string|max:10',
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual', 'custom'])], 'total_rent' => 'required|numeric|min:0.01|max:999999999999',
            'auto_renew' => 'required|boolean', 'renewal_clause' => 'nullable|string|max:10000', 'terms' => 'nullable|string|max:20000', 'change_reason' => 'nullable|string|max:4000',
            'items' => 'required|array|min:1', 'items.*.unit_id' => 'required|integer|distinct|exists:aims_property_units,id', 'items.*.rent_share' => 'required|numeric|min:0.01',
            'items.*.rent_per_m2' => 'nullable|numeric|min:0', 'services' => 'array', 'services.*.service_value_id' => 'required|integer|distinct',
            'services.*.billing' => ['required', Rule::in(['included', 'separate'])], 'services.*.amount' => 'nullable|numeric|min:0', 'services.*.notes' => 'nullable|string|max:1000'];
    }

    /** @return array<string, mixed> */
    private function contractInput(Request $r): array
    {
        return $r->validate(self::contractRules());
    }

    public function customDue(Request $r, int $id, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['due_on' => 'required|date', 'amount' => 'required|numeric|min:0.01|max:999999999999']);
        $requests->addCustomDue($r->user(), $id, $d['due_on'], (string) $d['amount']);

        return back()->with('success', 'تم حفظ الاستحقاق المخصص.');
    }

    public function removeCustomDue(Request $r, int $id, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $requests->removeCustomDue($r->user(), $id);

        return back()->with('success', 'حُذف الاستحقاق من مسودة العقد.');
    }

    public function termination(Request $r, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['contract_id' => 'required|integer|exists:aims_rental_contracts,id', 'termination_reason_value_id' => 'required|integer',
            'departure_on' => 'required|date', 'reason' => 'required|string|max:4000', 'details' => 'nullable|string|max:10000']);
        $id = $requests->createTermination($r->user(), $d);

        return redirect('/admin/rentals/records/case/'.$id)->with('success', 'حُفظت معاملة الإنهاء مسودة. أرسلها للفحص عند الجاهزية.');
    }

    public function settlement(Request $r, RentalRequests $requests): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['due_id' => 'required|integer|exists:aims_rental_dues,id', 'type_value_id' => 'required|integer',
            'effect' => ['required', Rule::in(array_keys(RentalSettlementWorkflow::EFFECTS))], 'amount' => 'required|numeric|min:0.01|max:999999999999', 'reason' => 'required|string|max:4000']);
        $d['amount'] = number_format((float) $d['amount'], 2, '.', '');
        $id = $requests->createSettlement($r->user(), $d);

        return redirect('/admin/rentals/records/settlement/'.$id)->with('success', 'حُفظ طلب التسوية مسودة. أرفق المستندات ثم أرسله إلى الحسابات.');
    }

    public function maintenance(Request $r, RentalMaintenance $maintenance): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['unit_id' => 'nullable|integer|exists:aims_property_units,id', 'contract_id' => 'nullable|integer|exists:aims_rental_contracts,id',
            'description' => 'required|string|min:5|max:5000', 'priority_value_id' => 'required|integer|exists:master_data_values,id']);
        $instance = $maintenance->open($r->user(), $d['unit_id'] ?? null, $d['contract_id'] ?? null, $d['description'], (int) $d['priority_value_id']);

        return back()->with('success', 'فُتح طلب الصيانة '.$instance->reference_no.' في المسار المركزي، ويظهر في سجل العين.');
    }

    public function reading(Request $r, RentalDocuments $documents): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['unit_id' => 'required|integer|exists:aims_property_units,id', 'contract_id' => 'nullable|integer|exists:aims_rental_contracts,id',
            'case_id' => 'nullable|integer|exists:aims_rental_cases,id', 'purpose' => ['required', Rule::in(['handover', 'periodic', 'eviction'])],
            'meter_kind' => 'required|string|max:50', 'reading' => 'required|numeric|min:0|max:999999999999', 'read_on' => 'required|date|before_or_equal:today',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        if ($d['purpose'] === 'handover' && empty($d['contract_id'])) {
            throw ValidationException::withMessages(['contract_id' => 'قراءة التسليم ترتبط بالعقد.']);
        }
        if ($d['purpose'] === 'eviction' && empty($d['case_id'])) {
            throw ValidationException::withMessages(['case_id' => 'قراءة الإخلاء ترتبط بمعاملة الإنهاء.']);
        }
        if (! empty($d['contract_id']) && ! DB::table('aims_rental_contract_units')->where('contract_id', $d['contract_id'])->where('unit_id', $d['unit_id'])->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'العين ليست من أعيان العقد المختار.']);
        }
        if (! empty($d['case_id'])) {
            $case = DB::table('aims_rental_cases')->where('id', $d['case_id'])->first();
            if (! $case || ! DB::table('aims_rental_contract_units')->where('contract_id', $case->contract_id)->where('unit_id', $d['unit_id'])->exists()) {
                throw ValidationException::withMessages(['case_id' => 'العين ليست من أعيان العقد في معاملة الإنهاء.']);
            }
            $d['contract_id'] = (int) $case->contract_id;
        }
        $photo = $r->file('photo');
        unset($d['photo']);
        $id = DB::transaction(function () use ($r, $d, $photo, $documents): int {
            $id = DB::table('aims_rental_meter_readings')->insertGetId($d + ['recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($photo) {
                $documents->store($r->user(), 'reading', $id, $photo, $this->constants->idFor('rental_document_types', 'meter_photo'), 'صورة العداد '.$d['read_on'], null);
            }
            $this->audit->log((int) $r->user()->id, 'rental.meter.reading', 'aims_rental_meter_readings', $id, null, $d);

            return $id;
        });

        return back()->with('success', 'تم حفظ القراءة'.($photo ? ' وصورتها.' : '.'));
    }

    public function notice(Request $r): RedirectResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['contract_id' => 'required|integer|exists:aims_rental_contracts,id', 'kind' => ['required', Rule::in(['renewal', 'payment_due', 'overdue', 'document', 'maintenance', 'other'])], 'body' => 'required|string|max:10000']);
        $id = DB::table('aims_rental_notices')->insertGetId($d + ['status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.notice.created', 'aims_rental_notices', $id, null, ['contract_id' => $d['contract_id'], 'kind' => $d['kind']]);

        return back()->with('success', 'تم حفظ مسودة الإنذار.');
    }

    /** Mark a notice as issued (handed or sent to the tenant outside the system) with the date. */
    public function noticeIssue(Request $r, int $id): RedirectResponse
    {
        $this->authorizeAccess($r);
        $updated = DB::table('aims_rental_notices')->where('id', $id)->where('status', 'draft')
            ->update(['status' => 'issued', 'sent_at' => now(), 'issued_by' => $r->user()->id, 'updated_at' => now()]);
        abort_unless($updated, 409, 'الإنذار صادر مسبقًا.');
        $this->audit->log((int) $r->user()->id, 'rental.notice.issued', 'aims_rental_notices', $id, ['status' => 'draft'], ['status' => 'issued']);

        return back()->with('success', 'سُجل إصدار الإنذار وتاريخه.');
    }

    /** Alert windows: the system administrator's configuration (renewal alert three months by default). */
    public function settings(Request $r): RedirectResponse
    {
        abort_unless($r->user()?->isSystemAdmin(), 403, 'إعدادات التنبيه لمدير النظام.');
        $d = $r->validate(['renewal_alert_days' => 'required|integer|min:1|max:365', 'overdue_alert_days' => 'required|integer|min:0|max:90']);
        $before = DB::table('rentals_module_settings')->where('id', 1)->first();
        DB::table('rentals_module_settings')->where('id', 1)->update($d + ['updated_by' => $r->user()->id, 'updated_at' => now()]);
        $this->audit->log((int) $r->user()->id, 'rental.settings.updated', 'rentals_module_settings', 1, (array) $before, $d);

        return back()->with('success', 'حُفظت مدد التنبيه.');
    }

    /** CSV export with a stated reason (approved document §11: exports are restricted, with a reason and an audit line). */
    public function report(Request $r): StreamedResponse
    {
        $this->authorizeAccess($r);
        $d = $r->validate(['type' => ['required', Rule::in(['units', 'contracts', 'dues'])], 'reason' => 'required|string|min:5|max:500']);
        [$query, $columns] = match ($d['type']) {
            'units' => [DB::table('aims_property_units as u')->join('aims_properties as p', 'p.id', '=', 'u.property_id')->orderBy('p.name')->orderBy('u.code')
                ->select(['p.name as property', 'u.code', 'u.name', 'u.availability', 'u.area_m2', 'u.directions']), ['property', 'code', 'name', 'availability', 'area_m2', 'directions']],
            'contracts' => [DB::table('aims_rental_contracts as c')->join('aims_rental_tenants as t', 't.id', '=', 'c.tenant_id')->orderBy('c.number')
                ->select(['c.number', 'c.kind', 't.name as tenant', 'c.status', 'c.starts_on', 'c.ends_on', 'c.currency_code', 'c.frequency', 'c.total_rent']),
                ['number', 'kind', 'tenant', 'status', 'starts_on', 'ends_on', 'currency_code', 'frequency', 'total_rent']],
            'dues' => [DB::query()->fromSub($this->ledger->dues(), 'b')->orderBy('b.due_on'),
                ['contract_number', 'due_on', 'kind', 'currency_code', 'amount', 'paid', 'reduced', 'increased', 'remaining', 'payment_state', 'overdue']],
        };
        $this->audit->log((int) $r->user()->id, 'rental.report.export', 'aims_rental_'.$d['type'], 0, null, $d);

        return response()->streamDownload(function () use ($query, $columns): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($query->cursor() as $row) {
                fputcsv($out, array_map(fn (string $column) => is_bool($row->{$column}) ? ($row->{$column} ? 'نعم' : 'لا') : $row->{$column}, $columns));
            }
            fclose($out);
        }, 'aims-rental-'.$d['type'].'-'.Str::lower(now()->format('Ymd')).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
