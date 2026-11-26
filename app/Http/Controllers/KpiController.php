<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\Kpi\KpiEngine;
use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiScope;
use App\Services\Kpi\KpiSnapshots;
use App\Services\Kpi\KpiSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The KPI dashboard. Every figure, list and split is computed with the viewer's live scope; the page
 * never receives records outside it.
 */
class KpiController extends Controller
{
    public function __construct(private readonly KpiEngine $engine, private readonly KpiSnapshots $snapshots) {}

    public function index(Request $request): Response
    {
        [$scope, $period, $filters, $department] = $this->context($request);
        $fresh = $request->boolean('refresh');
        $definitions = $this->engine->definitions($scope);
        $names = $this->engine->departmentNames();
        $registered = $this->engine->definitions($scope, false)
            ->filter(fn (object $definition): bool => ! $definition->is_active)
            ->map(function (object $definition): ?array {
                $config = $this->engine->configuration($this->engine->currentVersions()[(int) $definition->id]);
                if (($config['source'] ?? null) !== KpiSources::UNAVAILABLE) {
                    return null;
                }

                return ['code' => $definition->code, 'title' => $config['title'] ?? $definition->code, 'domain' => KpiEngine::DOMAINS[$definition->domain] ?? $definition->domain,
                    'reason' => $config['unavailable_reason'] ?? ''];
            })->filter()->values();

        return Inertia::render('Kpi/Index', [
            'filters' => [...$period->toArray(), 'department_id' => $department, 'currency' => $filters['currency'] ?? '', 'workflow_code' => $filters['workflow_code'] ?? ''],
            'scope' => ['institutional' => $scope->institutional, 'label' => $scope->institutional ? 'المؤسسة كلها'
                : implode('، ', array_map(fn (int $id): string => $names[$id] ?? '', $scope->departments))],
            'cards' => Inertia::defer(fn (): array => $definitions->map(fn (object $definition): array => [
                ...$this->engine->evaluate($definition, $scope, $period, $filters, $department, $fresh),
                'urls' => ['details' => route('kpi.details', $definition->id, false), 'breakdown' => route('kpi.breakdown', $definition->id, false),
                    'trend' => route('kpi.trend', $definition->id, false), 'history' => route('kpi.history', $definition->id, false)],
            ])->values()->all()),
            'comparison' => Inertia::defer(fn (): ?array => $department === null ? $this->engine->comparison($scope, $period) : null),
            'registered' => $registered,
            'options' => [
                'departments' => collect($scope->departments)->map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? '#'.$id])->sortBy('name')->values(),
                'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['code', 'name_ar']),
                'base_currency' => trim((string) DB::table('aims_currency_settings')->orderBy('id')->value('base_currency_code')),
                'workflows' => DB::table('workflow_definitions')->where('is_active', true)->orderBy('name')->get(['code', 'name']),
                'domains' => KpiEngine::DOMAINS,
            ],
            'canManage' => $scope->administrator,
            'urls' => ['index' => route('kpi.index', [], false), 'snapshot' => route('kpi.snapshot', [], false), 'manage' => route('admin.kpi.index', [], false)],
        ]);
    }

    public function details(Request $request, int $definition, AuditService $audit): JsonResponse
    {
        [$scope, $period, $filters, $department] = $this->context($request);
        $input = $request->validate(['component' => ['required', Rule::in(['numerator', 'denominator'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $row = $this->definitionOr404($definition);
        $details = $this->engine->details($row, $scope, $period, $filters, $department, $input['component'], (int) ($input['page'] ?? 1), $request->user());
        $audit->recordRow('kpi.details.viewed', 'kpi_definitions', $definition, null,
            ['component' => $input['component'], 'period' => $period->toArray(), 'department_id' => $department, 'records' => $details['total']], (int) $request->user()->id, $request->ip());

        return response()->json($details);
    }

    public function breakdown(Request $request, int $definition): JsonResponse
    {
        [$scope, $period, $filters, $department] = $this->context($request);

        return response()->json(['rows' => $this->engine->breakdown($this->definitionOr404($definition), $scope, $period, $filters, $department)]);
    }

    public function trend(Request $request, int $definition): JsonResponse
    {
        [$scope, $period, $filters, $department] = $this->context($request);

        return response()->json($this->engine->trend($this->definitionOr404($definition), $scope, $period, $filters, $department));
    }

    public function history(Request $request, int $definition): JsonResponse
    {
        [$scope, $period, $filters, $department] = $this->context($request);
        $card = $this->engine->evaluate($this->definitionOr404($definition), $scope, $period, $filters, $department);

        return response()->json(['data' => $card['scope_key'] ? $this->snapshots->history($definition, $card['scope_key'], $card['filters']) : []]);
    }

    public function snapshot(Request $request, AuditService $audit): JsonResponse
    {
        [$scope, $period, $filters, $department] = $this->context($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => 'سبب حفظ القراءة'])['reason'];
        $cards = $this->engine->definitions($scope)->map(fn (object $definition): array => $this->engine->evaluate($definition, $scope, $period, $filters, $department, true));
        $count = $this->snapshots->save($cards, $period, $reason, (int) $request->user()->id);
        $audit->recordRow('kpi.snapshot.saved', 'users', (int) $request->user()->id, null,
            ['count' => $count, 'period' => $period->toArray(), 'department_id' => $department, 'filters' => $filters, 'reason' => $reason], (int) $request->user()->id, $request->ip());

        return response()->json(['count' => $count]);
    }

    /**
     * @return array{0: KpiScope, 1: KpiPeriod, 2: array<string, string>, 3: ?int}
     */
    private function context(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'workflow_code' => ['nullable', 'string', 'max:100', Rule::exists('workflow_definitions', 'code')],
        ], [], ['from' => 'بداية الفترة', 'to' => 'نهاية الفترة', 'department_id' => 'القسم', 'currency' => 'العملة', 'workflow_code' => 'نوع المعاملة']);
        $scope = KpiScope::forUser($request->user());
        $period = KpiPeriod::fromInput($input['from'] ?? null, $input['to'] ?? null);
        $department = isset($input['department_id']) ? (int) $input['department_id'] : null;
        abort_if($department !== null && ! $scope->covers($department), 403, 'القسم المختار خارج نطاقك.');
        $filters = array_filter(['currency' => $input['currency'] ?? null, 'workflow_code' => $input['workflow_code'] ?? null]);

        return [$scope, $period, $filters, $department];
    }

    private function definitionOr404(int $id): object
    {
        return $this->engine->definition($id) ?? abort(404);
    }
}
