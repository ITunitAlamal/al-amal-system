<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Services\Kpi\Sources\KpiSource;
use App\Services\Kpi\Sources\WorkflowTasksSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes KPI figures from registered sources with the viewer's data scope applied inside every query.
 * Zero denominators and empty duration samples give no value (never a misleading 0%).
 */
final class KpiEngine
{
    public const CACHE_SECONDS = 300;

    public const PAGE_SIZE = 25;

    public const DOMAINS = [
        'institution' => 'المؤسسة والمعاملات', 'hr' => 'الموارد البشرية', 'maintenance' => 'الصيانة والخدمات العامة', 'finance' => 'الحسابات',
        'rentals' => 'الأصول والإيجارات', 'orphans' => 'الأيتام والكفالات', 'shelter' => 'الرعاية الإيوائية', 'media' => 'العلاقات العامة والإعلام',
        'secretariat' => 'السكرتاريا',
    ];

    /** @var array<int, object>|null */
    private ?array $units = null;

    /** @var array<int, string>|null */
    private ?array $departmentNames = null;

    /** @var array<int, object>|null */
    private ?array $current = null;

    public function __construct(private readonly KpiSources $sources, private readonly KpiAccess $access) {}

    /**
     * Definitions the viewer may see, ordered for display.
     *
     * @return Collection<int, object>
     */
    public function definitions(KpiScope $scope, bool $activeOnly = true): Collection
    {
        $current = $this->currentVersions();

        return DB::table('kpi_definitions')->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(function (object $definition) use ($scope, $current): bool {
                $version = $current[(int) $definition->id] ?? null;
                $source = $version ? $this->sources->get($this->configuration($version)['source'] ?? '') : null;

                return $version !== null && $this->access->visible($definition, $scope, $source);
            })->values();
    }

    /**
     * Today's version of every definition, in one query.
     *
     * @return array<int, object>
     */
    public function currentVersions(): array
    {
        $today = KpiPeriod::now()->toDateString();

        return $this->current ??= DB::table('kpi_versions')->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $today))
            ->orderBy('definition_id')->orderByDesc('version')->get()
            ->unique('definition_id')->keyBy(fn (object $version): int => (int) $version->definition_id)->all();
    }

    public function definition(int $id): ?object
    {
        return DB::table('kpi_definitions')->where('id', $id)->first();
    }

    /** The version in force on a day: the highest version number that started on or before it and had not ended. */
    public function versionAt(int $definitionId, string $date): ?object
    {
        return DB::table('kpi_versions')->where('definition_id', $definitionId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $date))
            ->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    public function configuration(object $version): array
    {
        return is_array($version->configuration) ? $version->configuration : (json_decode($version->configuration, true) ?: []);
    }

    /**
     * One KPI figure for the viewer.
     *
     * @param  array<string, mixed>  $viewerFilters
     * @return array<string, mixed>
     */
    public function evaluate(object $definition, KpiScope $scope, KpiPeriod $period, array $viewerFilters = [], ?int $departmentId = null, bool $fresh = false): array
    {
        $context = $this->context($definition, $scope, $period, $viewerFilters, $departmentId);
        if (is_string($context['problem'])) {
            return $this->card($context, null, $period);
        }
        $key = 'kpi:v2:'.hash('sha256', json_encode([$context['version']->id, $context['config'], $context['scope']->key(), $period->key(), $context['filters'], $context['params']]));
        if ($fresh) {
            Cache::forget($key);
        }
        $figure = Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->compute($context, $period));

        return $this->card($context, $figure, $period);
    }

    /**
     * Evaluate an unsaved configuration for the administration preview (institution-wide, nothing stored).
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function preview(array $configuration, KpiPeriod $period): array
    {
        $definition = (object) ['id' => 0, 'code' => 'PREVIEW', 'domain' => 'institution', 'is_active' => true, 'owner_department_id' => null,
            'audience' => 'executive', 'viewer_department_ids' => '[]', 'visible_role_codes' => '[]'];
        $version = (object) ['id' => 0, 'version' => 0, 'effective_from' => $period->definitionDate(), 'configuration' => $configuration];
        $context = $this->contextFor($definition, $version, $configuration, new KpiDataScope(null, 'المؤسسة كلها'), $period, []);

        return $this->card($context, is_string($context['problem']) ? null : $this->compute($context, $period), $period);
    }

    /**
     * The records behind a figure, with the same scope and filters, page by page.
     *
     * @return array<string, mixed>
     */
    public function details(object $definition, KpiScope $scope, KpiPeriod $period, array $viewerFilters, ?int $departmentId, string $component, int $page, User $viewer): array
    {
        $context = $this->context($definition, $scope, $period, $viewerFilters, $departmentId);
        abort_if(is_string($context['problem']), 422, $context['problem'] ?? '');
        $measure = $component === 'denominator' ? ($context['config']['denominator'] ?? null) : ($context['config']['numerator'] ?? null);
        abort_unless($measure && isset($context['source']->measures()[$measure]), 422, 'لا يوجد مكوّن بهذا الاسم في المؤشر.');
        /** @var KpiSource $source */
        $source = $context['source'];
        $query = DB::query()->fromSub($source->rows($measure, $context['scope'], $period, $context['filters'], $context['params']), 'r');
        $total = (clone $query)->count();
        $names = $this->departmentNames();
        $rows = $query->orderByDesc('r.id')->forPage($page, self::PAGE_SIZE)->get()->map(function (object $row) use ($source, $viewer, $names): array {
            $display = $source->present($row);
            if (array_key_exists('department_name', $source->columns())) {
                $display['department_name'] = $row->department_id === null ? 'غير منسوب لقسم' : ($names[(int) $row->department_id] ?? '—');
            }

            return ['cells' => $display, 'link' => $source->link($row, $viewer)];
        });

        return ['measure' => $source->measures()[$measure]['label'], 'columns' => $source->columns(), 'rows' => $rows, 'total' => $total,
            'page' => $page, 'last_page' => max(1, (int) ceil($total / self::PAGE_SIZE)), 'scope' => $context['scope']->label];
    }

    /**
     * The figure split by department, for sources whose records belong to a department.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(object $definition, KpiScope $scope, KpiPeriod $period, array $viewerFilters, ?int $departmentId): array
    {
        $context = $this->context($definition, $scope, $period, $viewerFilters, $departmentId);
        abort_if(is_string($context['problem']), 422, $context['problem'] ?? '');
        abort_unless($context['source']->sliceable(), 422, 'هذا المؤشر لا يُنسب إلى الأقسام.');
        $config = $context['config'];
        $numerator = $this->grouped($context, $period, $config['numerator'], $this->operation($config['formula']));
        $denominator = $this->isRatio($config['formula']) ? $this->grouped($context, $period, $config['denominator'], 'sum') : [];
        $names = $this->departmentNames();
        $rows = [];
        foreach (array_unique([...array_keys($numerator), ...array_keys($denominator)]) as $department) {
            $num = $numerator[$department] ?? ['value' => $this->isRatio($config['formula']) || $config['formula'] === 'count' ? 0.0 : null, 'count' => 0];
            $den = $denominator[$department] ?? null;
            $value = $this->combine($config, $context['unit'], $num, $den);
            $rows[] = ['department' => $department === 'none' ? 'غير منسوب لقسم' : ($names[(int) $department] ?? '—'),
                'value' => $value, 'numerator' => $this->round($num['value']), 'denominator' => $den ? $this->round($den['value']) : null,
                'sample_size' => $den['count'] ?? $num['count']];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['department'], $b['department']));

        return $rows;
    }

    /**
     * Six calendar months up to the period's month, recomputed from history. Present-state figures cannot be
     * rebuilt for the past; for them only saved readings exist.
     *
     * @return array<string, mixed>
     */
    public function trend(object $definition, KpiScope $scope, KpiPeriod $period, array $viewerFilters, ?int $departmentId): array
    {
        $context = $this->context($definition, $scope, $period, $viewerFilters, $departmentId);
        if (! is_string($context['problem']) && $context['snapshot']) {
            return ['kind' => 'snapshot', 'points' => [], 'note' => 'قراءة لحظية لا يُعاد بناؤها لأشهر سابقة؛ تظهر القيم السابقة من القراءات المحفوظة فقط.'];
        }
        $points = [];
        $month = $period->until->subDay()->startOfMonth();
        for ($i = 5; $i >= 0; $i--) {
            $window = KpiPeriod::month($month->subMonths($i));
            $card = $this->evaluate($definition, $scope, $window, $viewerFilters, $departmentId);
            $points[] = ['label' => $this->monthLabel($window->from), 'from' => $window->from->toDateString(), 'to' => $window->lastDay(),
                'value' => $card['value'], 'numerator' => $card['numerator'], 'denominator' => $card['denominator'],
                'status' => $card['status'], 'reason' => $card['reason'], 'version' => $card['version']];
        }

        return ['kind' => 'computed', 'points' => $points, 'note' => 'كل شهر محسوب من السجلات بالإصدار الساري في نهايته. الشهر الجاري جزئي حتى اليوم.'];
    }

    /**
     * Figures for every department the viewer answers for, on the request and task sources.
     *
     * @return array<string, mixed>|null
     */
    public function comparison(KpiScope $scope, KpiPeriod $period): ?array
    {
        if (! $scope->institutional && count($scope->departments) < 2) {
            return null;
        }
        $scopeAll = $scope->institutional ? new KpiDataScope(null, 'المؤسسة كلها') : new KpiDataScope($scope->departments, 'أقسامك');
        $tasks = $this->sources->get('workflow_tasks');
        $requests = $this->sources->get('workflow_requests');
        $count = fn (KpiSource $source, string $measure, string $operation = 'sum'): array => $this->groupedRows($source->rows($measure, $scopeAll, $period, [], []), $operation);
        $columns = [
            'requests_open' => $count($requests, 'open'),
            'tasks_open' => $count($tasks, 'open'),
            'tasks_overdue' => $count($tasks, 'open_overdue'),
            'handled' => $count($tasks, 'handled'),
            'with_deadline' => $count($tasks, 'handled_with_deadline'),
            'on_time' => $count($tasks, 'handled_on_time'),
            'median_hours' => $count($tasks, 'handling_hours', 'median'),
        ];
        $names = $this->departmentNames();
        $departments = $scope->institutional ? array_keys($names) : $scope->departments;
        foreach ($columns as $values) {
            foreach (array_keys($values) as $key) {
                if ($key !== 'none' && ! in_array((int) $key, $departments, true) && $scope->institutional) {
                    $departments[] = (int) $key;
                }
            }
        }
        $rows = [];
        foreach ($departments as $id) {
            $cell = fn (string $column): ?float => $columns[$column][(string) $id]['value'] ?? null;
            $withDeadline = $cell('with_deadline');
            $row = ['department' => $names[$id] ?? 'قسم #'.$id, 'requests_open' => (int) ($cell('requests_open') ?? 0), 'tasks_open' => (int) ($cell('tasks_open') ?? 0),
                'tasks_overdue' => (int) ($cell('tasks_overdue') ?? 0), 'handled' => (int) ($cell('handled') ?? 0),
                'on_time_rate' => $withDeadline ? round(($cell('on_time') ?? 0) / $withDeadline * 100, 1) : null,
                'with_deadline' => (int) ($withDeadline ?? 0), 'median_hours' => $this->round($cell('median_hours'))];
            if (array_sum([$row['requests_open'], $row['tasks_open'], $row['handled']]) > 0 || in_array($id, $scope->departments, true)) {
                $rows[] = $row;
            }
        }
        usort($rows, fn (array $a, array $b): int => [$b['tasks_overdue'], $b['tasks_open']] <=> [$a['tasks_overdue'], $a['tasks_open']] ?: strcmp($a['department'], $b['department']));
        $unassigned = $columns['tasks_overdue']['none']['value'] ?? 0;

        return ['rows' => $rows, 'period' => $period->toArray(), 'unattributed_overdue' => (int) $unassigned,
            'sla_configured' => WorkflowTasksSource::slaConfigured([]),
            'note' => 'الطلبات المفتوحة منسوبة إلى قسم مقدم الطلب. المهام والتأخر وزمن المعالجة منسوبة إلى القسم المسؤول عن المرحلة؛ تأخر مرحلة لدى قسم آخر لا يُحسب على القسم صاحب الطلب. نسبة الالتزام تشمل المهام ذات المهلة فقط.'];
    }

    /** @return array<int, string> */
    public function departmentNames(): array
    {
        return $this->departmentNames ??= DB::table('departments')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => $name])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(object $definition, KpiScope $scope, KpiPeriod $period, array $viewerFilters, ?int $departmentId): array
    {
        abort_unless((bool) $definition->is_active || $scope->administrator, 404);
        $current = $this->currentVersions()[(int) $definition->id] ?? null;
        $currentSource = $current ? $this->sources->get($this->configuration($current)['source'] ?? '') : null;
        abort_unless($current && $this->access->visible($definition, $scope, $currentSource), 403, 'هذا المؤشر خارج نطاقك.');
        $version = $this->versionAt((int) $definition->id, $period->definitionDate());
        if (! $version) {
            return ['definition' => $definition, 'version' => null, 'config' => $this->configuration($current), 'source' => null, 'scope' => null,
                'filters' => [], 'params' => [], 'unit' => null, 'snapshot' => false, 'notes' => [], 'problem' => 'لم يكن المؤشر معرّفًا في هذه الفترة.'];
        }
        $config = $this->configuration($version);
        $source = $this->sources->get($config['source'] ?? '');
        $dataScope = $source ? $this->access->dataScope($definition, $scope, $source, $departmentId) : new KpiDataScope(null, '—');

        return $this->contextFor($definition, $version, $config, $dataScope, $period, $viewerFilters);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(object $definition, object $version, array $config, KpiDataScope|string $dataScope, KpiPeriod $period, array $viewerFilters): array
    {
        $source = $this->sources->get($config['source'] ?? '');
        $context = ['definition' => $definition, 'version' => $version, 'config' => $config, 'source' => $source, 'scope' => $dataScope instanceof KpiDataScope ? $dataScope : null,
            'filters' => [], 'params' => (array) ($config['params'] ?? []), 'unit' => $this->unit((int) ($config['unit_value_id'] ?? 0)), 'snapshot' => false,
            'notes' => $this->versionNotes((int) $definition->id, $period, $version), 'problem' => null];
        if (($config['source'] ?? '') === KpiSources::UNAVAILABLE) {
            return [...$context, 'problem' => $config['unavailable_reason'] ?: 'لا يوجد مصدر رسمي لبيانات هذا المؤشر بعد.'];
        }
        if (! $source) {
            return [...$context, 'problem' => 'مصدر المؤشر غير مسجل في النظام.'];
        }
        if (is_string($dataScope)) {
            return [...$context, 'problem' => $dataScope];
        }
        $fixed = array_filter((array) ($config['filters'] ?? []), fn ($value): bool => $value !== null && $value !== '');
        $filters = $fixed;
        foreach ($source->viewerFilters() as $key) {
            $chosen = $viewerFilters[$key] ?? null;
            if ($chosen === null || $chosen === '') {
                continue;
            }
            if (isset($fixed[$key]) && $fixed[$key] !== $chosen) {
                $context['notes'][] = 'المؤشر مثبت على قيمة محددة لهذا الفلتر؛ اختيارك لا ينطبق عليه.';

                continue;
            }
            $filters[$key] = $chosen;
        }
        $filters += array_filter($source->defaults(), fn ($value): bool => $value !== null && $value !== '');
        ksort($filters);
        $context['filters'] = $filters;
        $measures = array_filter([$config['numerator'] ?? null, $this->isRatio($config['formula'] ?? '') ? ($config['denominator'] ?? null) : null]);
        foreach ($measures as $measure) {
            if (! isset($source->measures()[$measure])) {
                return [...$context, 'problem' => 'مقياس غير معروف في تعريف المؤشر؛ راجع الإصدار من الإدارة.'];
            }
        }
        $context['snapshot'] = collect($measures)->contains(fn (string $measure): bool => $source->isSnapshot($measure));
        if ($context['snapshot'] && ! $period->includesNow()) {
            return [...$context, 'problem' => 'قراءة لحظية لحالة اليوم؛ لا يُعاد بناؤها لفترة منتهية. راجع القراءات المحفوظة.'];
        }
        if (($problem = $source->problem($filters, $context['params'], array_values($measures))) !== null) {
            return [...$context, 'problem' => $problem];
        }

        return $context;
    }

    /**
     * @return array{value: ?float, numerator: ?float, denominator: ?float, sample_size: int, computed_at: string}
     */
    private function compute(array $context, KpiPeriod $period): array
    {
        $config = $context['config'];
        /** @var KpiSource $source */
        $source = $context['source'];
        $numerator = $this->aggregate($source->rows($config['numerator'], $context['scope'], $period, $context['filters'], $context['params']), $this->operation($config['formula']));
        $denominator = $this->isRatio($config['formula'])
            ? $this->aggregate($source->rows($config['denominator'], $context['scope'], $period, $context['filters'], $context['params']), 'sum')
            : null;

        return ['value' => $this->combine($config, $context['unit'], $numerator, $denominator), 'numerator' => $this->round($numerator['value']),
            'denominator' => $denominator ? $this->round($denominator['value']) : null, 'sample_size' => $denominator['count'] ?? $numerator['count'],
            'computed_at' => KpiPeriod::now()->toIso8601String()];
    }

    /**
     * @param  array{value: ?float, count: int}  $numerator
     * @param  array{value: ?float, count: int}|null  $denominator
     */
    private function combine(array $config, ?object $unit, array $numerator, ?array $denominator): ?float
    {
        if ($this->isRatio($config['formula'])) {
            if (! $denominator || ! $denominator['value']) {
                return null;
            }
            $value = ($numerator['value'] ?? 0) / $denominator['value'] * ($config['formula'] === 'percentage' ? 100 : 1);

            return round($value, 2);
        }
        if ($numerator['value'] === null) {
            return null;
        }
        $value = $numerator['value'];
        if (in_array($config['formula'], ['average', 'median'], true) && ($unit->code ?? null) === 'days') {
            $value /= 24;
        }

        return round($value, 2);
    }

    /**
     * @return array{value: ?float, count: int}
     */
    private function aggregate(Builder $rows, string $operation): array
    {
        $result = DB::query()->fromSub($rows, 'r')->selectRaw('COUNT(*) AS n')->selectRaw($this->expression($operation).' AS v')->first();

        return ['value' => $result->v === null ? null : (float) $result->v, 'count' => (int) $result->n];
    }

    /**
     * @return array<string, array{value: ?float, count: int}>
     */
    private function grouped(array $context, KpiPeriod $period, string $measure, string $operation): array
    {
        return $this->groupedRows($context['source']->rows($measure, $context['scope'], $period, $context['filters'], $context['params']), $operation);
    }

    /**
     * @return array<string, array{value: ?float, count: int}>
     */
    private function groupedRows(Builder $rows, string $operation): array
    {
        $result = [];
        foreach (DB::query()->fromSub($rows, 'r')->groupBy('r.department_id')->select('r.department_id')
            ->selectRaw('COUNT(*) AS n')->selectRaw($this->expression($operation).' AS v')->get() as $row) {
            $result[$row->department_id === null ? 'none' : (string) $row->department_id] = ['value' => $row->v === null ? null : (float) $row->v, 'count' => (int) $row->n];
        }

        return $result;
    }

    private function expression(string $operation): string
    {
        return match ($operation) {
            'count' => 'COUNT(*)',
            'average' => 'AVG(r.contribution)',
            'median' => 'percentile_cont(0.5) WITHIN GROUP (ORDER BY r.contribution)',
            default => 'COALESCE(SUM(r.contribution), 0)',
        };
    }

    private function operation(string $formula): string
    {
        return in_array($formula, ['count', 'average', 'median'], true) ? $formula : 'sum';
    }

    private function isRatio(string $formula): bool
    {
        return in_array($formula, ['ratio', 'percentage'], true);
    }

    /**
     * @param  array{value: ?float, numerator: ?float, denominator: ?float, sample_size: int, computed_at: string}|null  $figure
     * @return array<string, mixed>
     */
    private function card(array $context, ?array $figure, KpiPeriod $period): array
    {
        $definition = $context['definition'];
        $config = $context['config'];
        $source = $context['source'];
        $value = $figure['value'] ?? null;
        $empty = $figure !== null && $figure['sample_size'] === 0;
        $status = match (true) {
            $figure === null => 'unavailable',
            $empty => 'empty',
            $value === null => 'unavailable',
            default => 'measured',
        };
        $counted = in_array($config['formula'] ?? '', ['count', 'sum'], true);
        $reason = $context['problem'] ?? match (true) {
            $empty && $counted && $context['snapshot'] => 'لا توجد حالات قائمة الآن ضمن نطاقك.',
            $empty && $counted => 'لا توجد سجلات ضمن الفترة والنطاق المختارين.',
            $empty => 'لا توجد سجلات كافية للحساب ضمن الفترة والنطاق؛ لا تُعرض قيمة بدل صفر مضلل.',
            $value === null => 'المقام صفر؛ لا تُعرض نسبة.',
            default => null,
        };
        $target = isset($config['target']) && $config['target'] !== '' ? (float) $config['target'] : null;
        $polarity = $config['polarity'] ?? 'higher';
        $measures = $source?->measures() ?? [];

        return [
            'id' => (int) $definition->id, 'code' => $definition->code, 'domain' => $definition->domain ?? 'institution',
            'domain_label' => self::DOMAINS[$definition->domain ?? 'institution'] ?? ($definition->domain ?? ''),
            'title' => $config['title'] ?? $definition->code, 'description' => $config['description'] ?? '',
            'value' => $value, 'numerator' => $figure['numerator'] ?? null, 'denominator' => $figure['denominator'] ?? null,
            'sample_size' => $figure['sample_size'] ?? 0, 'status' => $status, 'reason' => $reason, 'notes' => array_values(array_unique($context['notes'])),
            'formula' => $config['formula'] ?? null, 'formula_label' => KpiSources::FORMULAS[$config['formula'] ?? ''][0] ?? '',
            'numerator_label' => $measures[$config['numerator'] ?? '']['label'] ?? null,
            'denominator_label' => $this->isRatio($config['formula'] ?? '') ? ($measures[$config['denominator'] ?? '']['label'] ?? null) : null,
            'numerator_kind' => $measures[$config['numerator'] ?? '']['kind'] ?? null,
            'unit' => $context['unit']?->name_ar, 'unit_code' => $context['unit']?->code,
            'currency' => $context['filters']['currency'] ?? null,
            'source' => $config['source'] ?? null, 'source_label' => $source?->label() ?? 'غير متاح', 'basis' => $source?->basis() ?? ($config['unavailable_reason'] ?? ''),
            'lineage' => $source?->lineage() ?? [], 'attribution' => $source?->attribution() ?? '',
            'target' => $target, 'warning_threshold' => $this->number($config['warning_threshold'] ?? null), 'critical_threshold' => $this->number($config['critical_threshold'] ?? null),
            'polarity' => $polarity, 'target_met' => $target === null || $value === null ? null : ($polarity === 'higher' ? $value >= $target : $value <= $target),
            'threshold_status' => $this->thresholdStatus($config, $value),
            'scope_label' => $context['scope']?->label, 'snapshot' => $context['snapshot'], 'sliceable' => (bool) $source?->sliceable(),
            'can_drill' => $status !== 'unavailable' && $source !== null,
            'period' => $period->toArray(), 'as_of' => $period->asOf()->toIso8601String(), 'filters' => $context['filters'],
            'version' => $context['version']->version ?? null, 'version_from' => $context['version']->effective_from ?? null,
            'version_id' => $context['version']->id ?? null, 'scope_key' => $context['scope']?->key(),
            'computed_at' => $figure['computed_at'] ?? null, 'cache_seconds' => self::CACHE_SECONDS,
        ];
    }

    private function thresholdStatus(array $config, ?float $value): ?string
    {
        $warning = $this->number($config['warning_threshold'] ?? null);
        $critical = $this->number($config['critical_threshold'] ?? null);
        if ($value === null || ($warning === null && $critical === null)) {
            return null;
        }
        $worse = fn (float $limit): bool => ($config['polarity'] ?? 'higher') === 'higher' ? $value < $limit : $value > $limit;
        if ($critical !== null && $worse($critical)) {
            return 'critical';
        }
        if ($warning !== null && $worse($warning)) {
            return 'warning';
        }

        return 'ok';
    }

    /** @return list<string> */
    private function versionNotes(int $definitionId, KpiPeriod $period, object $version): array
    {
        if ($definitionId === 0) {
            return [];
        }
        $changes = DB::table('kpi_versions')->where('definition_id', $definitionId)->where('id', '<>', $version->id)
            ->where('effective_from', '>', $period->from->toDateString())->where('effective_from', '<=', $period->definitionDate())->count();

        return $changes > 0 ? ['تغيّر تعريف المؤشر أو هدفه خلال الفترة؛ القيمة محسوبة بالإصدار '.$version->version.' الساري في نهايتها، والإصدارات السابقة محفوظة.'] : [];
    }

    private function unit(int $valueId): ?object
    {
        if ($this->units === null) {
            $this->units = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'kpi_units')
                ->get(['v.id', 'v.code', 'v.name_ar'])->keyBy('id')->all();
        }

        return $this->units[$valueId] ?? null;
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        $names = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        return $names[$month->month - 1].' '.$month->year;
    }
}
