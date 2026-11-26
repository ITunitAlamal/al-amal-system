<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Kpi\KpiAccess;
use App\Services\Kpi\KpiDefinitions;
use App\Services\Kpi\KpiEngine;
use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiScope;
use App\Services\Kpi\KpiSources;
use App\Services\Kpi\Sources\RentalCasesSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KPI catalog administration (system administrator only): visibility and placement, versioned
 * formulas and targets, readiness, and a preview on live institution-wide data that stores nothing.
 */
class KpiDefinitionController extends Controller
{
    private const SUBJECT_TYPES = ['aims_request' => 'طلبات الخدمات («طلباتي»)', 'employee_leave_request' => 'طلبات الإجازة'];

    public function __construct(private readonly KpiDefinitions $definitions, private readonly KpiEngine $engine, private readonly KpiAccess $access) {}

    public function index(Request $request, KpiSources $sources): Response
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $users = DB::table('users')->pluck('name', 'id');
        $versions = DB::table('kpi_versions')->orderByDesc('version')->get()->groupBy('definition_id');
        $current = $this->engine->currentVersions();
        $rows = DB::table('kpi_definitions')->orderBy('sort_order')->orderBy('id')->get()->map(function (object $definition) use ($versions, $current, $users): array {
            $history = ($versions[$definition->id] ?? collect())->map(fn (object $version): array => [
                'id' => (int) $version->id, 'version' => (int) $version->version, 'effective_from' => $version->effective_from, 'effective_until' => $version->effective_until,
                'change_reason' => $version->change_reason, 'created_at' => $version->created_at, 'created_by' => $users[$version->created_by] ?? null,
                'configuration' => json_decode($version->configuration, true),
            ])->values();
            $now = $current[(int) $definition->id] ?? null;

            return [
                'id' => (int) $definition->id, 'code' => $definition->code, 'domain' => $definition->domain, 'is_active' => (bool) $definition->is_active,
                'sort_order' => (int) $definition->sort_order, 'owner_department_id' => $definition->owner_department_id ? (int) $definition->owner_department_id : null,
                'audience' => $definition->audience, 'viewer_department_ids' => $this->access->list($definition->viewer_department_ids),
                'visible_role_codes' => $this->access->list($definition->visible_role_codes),
                'on_executive_home' => (bool) $definition->on_executive_home, 'on_department_home' => (bool) $definition->on_department_home,
                'current' => $now ? json_decode($now->configuration, true) : ($history->first()['configuration'] ?? null),
                'current_version' => $now ? (int) $now->version : null, 'latest_version' => $history->first()['version'] ?? null,
                'versions' => $history,
                'urls' => ['settings' => route('admin.kpi.settings', $definition->id, false), 'version' => route('admin.kpi.version', $definition->id, false)],
            ];
        });
        $constants = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->whereIn('t.code', ['kpi_units', 'kpi_periods'])->where('v.is_active', true)->orderBy('v.sort_order')
            ->get(['v.id', 'v.code', 'v.name_ar', 't.code as type']);

        return Inertia::render('Admin/Kpi/Index', [
            'definitions' => $rows,
            'readiness' => Inertia::defer(fn (): array => $this->readiness($request)),
            'catalog' => $sources->catalog(),
            'formulas' => collect(KpiSources::FORMULAS)->map(fn (array $formula, string $code): array => ['code' => $code, 'label' => $formula[0], 'kinds' => $formula[1], 'units' => KpiSources::UNITS[$code]])->values(),
            'units' => $constants->where('type', 'kpi_units')->values(),
            'periods' => $constants->where('type', 'kpi_periods')->values(),
            'departments' => DB::table('departments')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'is_active']),
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name']),
            'workflows' => DB::table('workflow_definitions')->orderBy('name')->get(['code', 'name', 'subject_type']),
            'subjectTypes' => DB::table('workflow_definitions')->distinct()->pluck('subject_type')->filter()
                ->map(fn (string $type): array => ['code' => $type, 'name' => self::SUBJECT_TYPES[$type] ?? $type])->values(),
            'caseKinds' => collect(RentalCasesSource::KINDS)->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])->values(),
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['code', 'name_ar']),
            'domains' => KpiEngine::DOMAINS,
            'audiences' => KpiDefinitions::AUDIENCES,
            'urls' => ['store' => route('admin.kpi.store', [], false), 'preview' => route('admin.kpi.preview', [], false),
                'dashboard' => route('kpi.index', [], false), 'constants' => route('admin.master-data.index', [], false)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->definitions->create($request->user(), $request->all(), $request->ip());

        return back()->with('success', 'حُفظ المؤشر الجديد وإصداره الأول.');
    }

    public function settings(Request $request, int $definition): RedirectResponse
    {
        $this->definitions->updateSettings($request->user(), $definition, $request->all(), $request->ip());

        return back()->with('success', 'حُفظت إعدادات الظهور والترتيب، وسُجلت في سجل التدقيق.');
    }

    public function version(Request $request, int $definition): RedirectResponse
    {
        $this->definitions->addVersion($request->user(), $definition, $request->all(), $request->ip());

        return back()->with('success', 'حُفظ إصدار جديد؛ الإصدارات السابقة والقراءات المحفوظة لم تتغير.');
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'configuration' => ['required', 'array']],
            [], ['from' => 'بداية فترة المعاينة', 'to' => 'نهاية فترة المعاينة', 'configuration' => 'تعريف المؤشر']);
        $configuration = $this->definitions->validateConfiguration($dates['configuration']);
        $period = KpiPeriod::fromInput($dates['from'] ?? null, $dates['to'] ?? null);

        return response()->json($this->engine->preview($configuration, $period));
    }

    /**
     * Today's figure for every definition, as the administrator sees it, to show which ones compute.
     *
     * @return array<int, array{status: string, reason: ?string, value: ?float, unit: ?string, sample_size: int}>
     */
    private function readiness(Request $request): array
    {
        $scope = KpiScope::forUser($request->user());
        $period = KpiPeriod::fromInput(null, null);
        $result = [];
        foreach (DB::table('kpi_definitions')->orderBy('sort_order')->get() as $definition) {
            $card = $this->engine->evaluate($definition, $scope, $period);
            $result[(int) $definition->id] = ['status' => $card['status'], 'reason' => $card['reason'], 'value' => $card['value'], 'unit' => $card['unit'],
                'unit_code' => $card['unit_code'], 'currency' => $card['currency'], 'sample_size' => $card['sample_size']];
        }

        return $result;
    }
}
