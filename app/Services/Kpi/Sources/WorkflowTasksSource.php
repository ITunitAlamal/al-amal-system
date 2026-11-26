<?php

namespace App\Services\Kpi\Sources;

use App\Models\User;
use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Step visits ("tasks"): each arrival of a request at a step, from the transition into the step until the
 * next action on the request. Deadlines are rebuilt as arrival + the step's SLA hours of the published
 * version, which is exactly how the engine sets due_at; published versions are never edited.
 */
final class WorkflowTasksSource extends KpiSource
{
    private const WINDOW = 'OVER (PARTITION BY e.workflow_instance_id ORDER BY e.occurred_at, e.id)';

    public const DEADLINE_MEASURES = ['handled_with_deadline', 'handled_on_time', 'due', 'due_on_time', 'open_overdue'];

    public const NO_SLA = 'لم تُحدَّد مهلة (SLA) لأي مرحلة في مسارات العمل المعنية، فلا يُقاس الالتزام بالمواعيد ولا يُعرض صفر مضلل. تُحدَّد مهلة كل مرحلة من «مسارات العمل» ثم تُنشر نسخة جديدة.';

    public function key(): string
    {
        return 'workflow_tasks';
    }

    public function label(): string
    {
        return 'مهام مراحل المعاملات';
    }

    public function basis(): string
    {
        return 'كل وصول لمعاملة إلى مرحلة مهمة واحدة، من حدث الانتقال إليها حتى الإجراء التالي عليها. المهلة = وقت الوصول + مهلة المرحلة في نسخة المسار المنشورة، وهي الطريقة نفسها التي يحدد بها المحرك موعد الاستحقاق. '
            .'المهمة المنجزة تُنسب إلى قسم منفذ الإجراء، والمفتوحة إلى قسم المسند إليه حاليًا؛ لا يُنسب تأخر مرحلة إلى قسم مقدم الطلب. '
            .'خارج العينة: مراحل مقدم الطلب، والمراحل النهائية، والمهام التي انتهت بإلغاء الطلب. المراحل بلا مهلة معتمدة لا تدخل في نسب الالتزام.';
    }

    public function lineage(): array
    {
        return [
            'workflow_events: workflow_instance_id, workflow_transition_id, to_step_id, action, actor_id, occurred_at',
            'workflow_steps: step_type, assignment_type, assigned_role_code, sla_hours',
            'workflow_instances: status, current_step_id, reference_no, subject_type',
            'workflow_definitions: code, name',
            'workflow_participants: assignee, is_active',
            'users: department_id',
        ];
    }

    public function measures(): array
    {
        return [
            'handled' => ['label' => 'مهام أُنجزت في الفترة', 'kind' => 'count', 'snapshot' => false],
            'handled_with_deadline' => ['label' => 'مهام أُنجزت في الفترة ولها مهلة', 'kind' => 'count', 'snapshot' => false],
            'handled_on_time' => ['label' => 'مهام أُنجزت ضمن مهلتها', 'kind' => 'count', 'snapshot' => false],
            'due' => ['label' => 'مهام حلّت مهلتها في الفترة', 'kind' => 'count', 'snapshot' => false],
            'due_on_time' => ['label' => 'مهام حلّت مهلتها وأُنجزت قبلها', 'kind' => 'count', 'snapshot' => false],
            'handling_hours' => ['label' => 'ساعات معالجة المهام المنجزة', 'kind' => 'hours', 'snapshot' => false],
            'open' => ['label' => 'مهام مفتوحة الآن', 'kind' => 'count', 'snapshot' => true],
            'open_overdue' => ['label' => 'مهام مفتوحة تجاوزت مهلتها', 'kind' => 'count', 'snapshot' => true],
        ];
    }

    public function ratios(): array
    {
        return [['handled_on_time', 'handled_with_deadline'], ['due_on_time', 'due'], ['handled_with_deadline', 'handled'], ['open_overdue', 'open']];
    }

    public function filters(): array
    {
        return ['workflow_code' => 'نوع المعاملة', 'subject_type' => 'فئة المعاملة', 'step_role' => 'دور مسؤول المرحلة'];
    }

    public function viewerFilters(): array
    {
        return ['workflow_code'];
    }

    public function attribution(): string
    {
        return 'قسم منفذ الإجراء، أو قسم المسند إليه للمهام المفتوحة';
    }

    public function problem(array $filters, array $params, array $measures = []): ?string
    {
        return array_intersect($measures, self::DEADLINE_MEASURES) !== [] && ! self::slaConfigured($filters) ? self::NO_SLA : null;
    }

    /** Whether any published or retired version of the matching workflows gives a step an SLA. */
    public static function slaConfigured(array $filters): bool
    {
        return DB::table('workflow_steps as s')->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->whereIn('v.status', ['published', 'retired'])->whereNotNull('s.sla_hours')
            ->when(! empty($filters['workflow_code']), fn (Builder $q) => $q->where('d.code', $filters['workflow_code']))
            ->when(! empty($filters['subject_type']), fn (Builder $q) => $q->where('d.subject_type', $filters['subject_type']))
            ->exists();
    }

    public function columns(): array
    {
        return ['reference' => 'المعاملة', 'workflow_name' => 'النوع', 'step_name' => 'المرحلة', 'department_name' => 'القسم المسؤول',
            'entered_at' => 'وصلت', 'deadline' => 'المهلة', 'left_at' => 'نُفذت', 'state' => 'الحالة'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $from = $this->naive($period->from);
        $until = $this->naive($period->until);
        $now = $this->naive(now());
        $query = DB::query()->fromSub($this->visits($filters), 't');

        $handled = fn (Builder $q): Builder => $q->where('t.outcome', 'handled')->where('t.left_at', '>=', $from)->where('t.left_at', '<', $until);
        $due = fn (Builder $q): Builder => $q->whereIn('t.outcome', ['handled', 'open'])->whereNotNull('t.deadline')
            ->where('t.deadline', '>=', $from)->where('t.deadline', '<', $until)->where('t.deadline', '<=', $now);

        match ($measure) {
            'handled', 'handling_hours' => $handled($query),
            'handled_with_deadline' => $handled($query)->whereNotNull('t.deadline'),
            'handled_on_time' => $handled($query)->whereNotNull('t.deadline')->whereColumn('t.left_at', '<=', 't.deadline'),
            'due' => $due($query),
            'due_on_time' => $due($query)->where('t.outcome', 'handled')->whereColumn('t.left_at', '<=', 't.deadline'),
            'open' => $query->where('t.outcome', 'open'),
            'open_overdue' => $query->where('t.outcome', 'open')->whereNotNull('t.deadline')->where('t.deadline', '<', $now),
        };
        if ($scope->departments !== null) {
            $query->whereRaw('t.responsible_departments && ?::bigint[]', [$scope->arrayLiteral()]);
        }

        return $query->select('t.*')
            ->selectRaw('CASE WHEN cardinality(t.responsible_departments) = 1 THEN t.responsible_departments[1] END AS department_id')
            ->selectRaw(($measure === 'handling_hours' ? 'EXTRACT(EPOCH FROM (t.left_at - t.entered_at)) / 3600.0' : '1').' AS contribution');
    }

    public function present(object $row): array
    {
        $state = match (true) {
            $row->outcome === 'open' && $row->deadline !== null && $row->deadline < now()->format('Y-m-d H:i:s') => 'مفتوحة ومتأخرة',
            $row->outcome === 'open' => 'مفتوحة',
            $row->deadline === null => 'منجزة بلا مهلة',
            $row->left_at <= $row->deadline => 'منجزة ضمن المهلة',
            default => 'منجزة بعد المهلة',
        };

        return ['reference' => $row->reference, 'workflow_name' => $row->workflow_name, 'step_name' => $row->step_name,
            'entered_at' => $row->entered_at, 'deadline' => $row->deadline, 'left_at' => $row->left_at, 'state' => $state];
    }

    public function link(object $row, User $viewer): ?string
    {
        return app(WorkflowVisibilityService::class)->accessLevel($viewer, (int) $row->instance_id) !== null
            ? route('workflows.show', ['publicId' => $row->public_id], false) : null;
    }

    private function visits(array $filters): Builder
    {
        $events = DB::table('workflow_events as e')
            ->where(fn (Builder $q) => $q->whereNotNull('e.workflow_transition_id')->orWhere('e.action', 'cancel'))
            ->select(['e.id', 'e.workflow_instance_id', 'e.workflow_transition_id', 'e.to_step_id', 'e.occurred_at'])
            ->selectRaw('LEAD(e.occurred_at) '.self::WINDOW.' AS left_at')
            ->selectRaw('LEAD(e.workflow_transition_id) '.self::WINDOW.' AS next_transition_id')
            ->selectRaw('LEAD(e.action) '.self::WINDOW.' AS next_action')
            ->selectRaw('LEAD(e.actor_id) '.self::WINDOW.' AS handled_by');

        $query = DB::query()->fromSub($events, 'x')
            ->join('workflow_steps as s', 's.id', '=', 'x.to_step_id')
            ->join('workflow_instances as i', 'i.id', '=', 'x.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('users as h', 'h.id', '=', 'x.handled_by')
            // Confidential requests (complaints) stay out of shared indicators: only the people on them see them.
            ->where('d.is_confidential', false)
            ->whereNotNull('x.workflow_transition_id')
            ->where('s.step_type', '<>', 'end')
            ->where(fn (Builder $q) => $q->whereNull('s.assignment_type')->orWhere('s.assignment_type', '<>', 'requester'));
        if (! empty($filters['workflow_code'])) {
            $query->where('d.code', $filters['workflow_code']);
        }
        if (! empty($filters['subject_type'])) {
            $query->where('i.subject_type', $filters['subject_type']);
        }
        if (! empty($filters['step_role'])) {
            $query->where('s.assigned_role_code', $filters['step_role']);
        }

        return $query->select(['x.id', 'i.id as instance_id', 'i.public_id', 'i.reference_no as reference', 'd.code as workflow_code',
            'd.name as workflow_name', 's.name as step_name', 'x.occurred_at as entered_at', 'x.left_at'])
            ->selectRaw("CASE WHEN x.next_transition_id IS NOT NULL THEN 'handled' WHEN x.next_action = 'cancel' THEN 'cancelled'
                WHEN x.left_at IS NULL AND i.status = 'in_progress' AND i.current_step_id = x.to_step_id THEN 'open' ELSE 'other' END AS outcome")
            ->selectRaw('CASE WHEN s.sla_hours IS NOT NULL THEN x.occurred_at + make_interval(hours => s.sla_hours) END AS deadline')
            ->selectRaw("CASE WHEN x.next_transition_id IS NOT NULL THEN ARRAY[h.department_id]::bigint[]
                ELSE ARRAY(SELECT DISTINCT u.department_id FROM workflow_participants p JOIN users u ON u.id = p.user_id
                    WHERE p.workflow_instance_id = i.id AND p.participation_type = 'assignee' AND p.is_active AND u.department_id IS NOT NULL)::bigint[] END AS responsible_departments");
    }
}
