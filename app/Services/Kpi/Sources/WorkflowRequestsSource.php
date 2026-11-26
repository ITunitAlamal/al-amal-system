<?php

namespace App\Services\Kpi\Sources;

use App\Models\User;
use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Requests (workflow instances), one row per request, attributed to the requester's department. */
final class WorkflowRequestsSource extends KpiSource
{
    public function key(): string
    {
        return 'workflow_requests';
    }

    public function label(): string
    {
        return 'المعاملات';
    }

    public function basis(): string
    {
        return 'كل معاملة على محرك المسارات سجل واحد، منسوب إلى القسم الأصلي (قسم مقدم الطلب). المفتوحة = قيد المعالجة الآن. المنجزة = انتهت بالإنجاز وتاريخ إغلاقها داخل الفترة؛ '
            .'المرفوضة والملغاة لا تُعد منجزة. مدة الانتظار تُحسب من آخر انتقال إلى المرحلة الحالية، لا من آخر تعديل. فلتر «المسند إليه» يفحص أصحاب المهمة الحاليين، فالمعاملة المحولة إلى مفوَّض تُحسب له.';
    }

    public function lineage(): array
    {
        return [
            'workflow_instances: department_id, status, started_at, completed_at, due_at, current_step_id, reference_no, subject_type',
            'workflow_definitions: code, name',
            'workflow_events: occurred_at (آخر انتقال)',
            'workflow_participants + department_user_roles + roles (فلتر دور المسند إليه)',
        ];
    }

    public function measures(): array
    {
        return [
            'open' => ['label' => 'معاملات مفتوحة الآن', 'kind' => 'count', 'snapshot' => true],
            'open_overdue' => ['label' => 'معاملات مفتوحة تجاوزت مهلة مرحلتها', 'kind' => 'count', 'snapshot' => true],
            'waiting_hours' => ['label' => 'ساعات الانتظار في المرحلة الحالية', 'kind' => 'hours', 'snapshot' => true],
            'completed' => ['label' => 'معاملات أُنجزت في الفترة', 'kind' => 'count', 'snapshot' => false],
            'closed' => ['label' => 'معاملات أُغلقت في الفترة (إنجاز أو رفض)', 'kind' => 'count', 'snapshot' => false],
            'cycle_hours' => ['label' => 'ساعات من التقديم حتى الإنجاز', 'kind' => 'hours', 'snapshot' => false],
        ];
    }

    public function ratios(): array
    {
        return [['open_overdue', 'open'], ['completed', 'closed']];
    }

    public function filters(): array
    {
        return ['workflow_code' => 'نوع المعاملة', 'subject_type' => 'فئة المعاملة', 'assignee_role' => 'دور المسند إليه حاليًا'];
    }

    public function viewerFilters(): array
    {
        return ['workflow_code'];
    }

    public function attribution(): string
    {
        return 'القسم الأصلي للمعاملة (قسم مقدم الطلب)';
    }

    public function problem(array $filters, array $params, array $measures = []): ?string
    {
        return in_array('open_overdue', $measures, true) && ! WorkflowTasksSource::slaConfigured($filters) ? WorkflowTasksSource::NO_SLA : null;
    }

    public function columns(): array
    {
        return ['reference' => 'المعاملة', 'workflow_name' => 'النوع', 'current_step' => 'المرحلة الحالية', 'current_holder' => 'المسؤول الحالي', 'department_name' => 'القسم الأصلي',
            'started_at' => 'قُدمت', 'entered_at' => 'وصلت المرحلة', 'completed_at' => 'أُغلقت', 'status' => 'الحالة'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $base = DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->select(['i.id', 'i.public_id', 'i.reference_no as reference', 'd.name as workflow_name', 's.name as current_step', 'i.department_id',
                'i.status', 'i.started_at', 'i.completed_at', 'i.due_at'])
            ->selectSub(DB::table('workflow_events as e')->whereColumn('e.workflow_instance_id', 'i.id')->whereNotNull('e.workflow_transition_id')
                ->selectRaw('MAX(e.occurred_at)'), 'entered_at')
            // Who must act now (SRS CORE04, ED requirements doc §3): names only, no request content.
            ->selectSub(DB::table('workflow_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.participation_type', 'assignee')->where('p.is_active', true)
                ->selectRaw("string_agg(u.name, '، ' ORDER BY u.name)"), 'current_holder');
        // Confidential requests (complaints) stay out of shared indicators: only the people on them see them.
        $base->where('d.is_confidential', false);
        if (! empty($filters['workflow_code'])) {
            $base->where('d.code', $filters['workflow_code']);
        }
        if (! empty($filters['subject_type'])) {
            $base->where('i.subject_type', $filters['subject_type']);
        }
        if (! empty($filters['assignee_role'])) {
            $base->whereExists(fn (Builder $holder) => $holder->from('workflow_participants as p')
                ->join('department_user_roles as a', 'a.user_id', '=', 'p.user_id')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.participation_type', 'assignee')->where('p.is_active', true)
                ->where('r.code', $filters['assignee_role']));
        }
        $this->restrictTo($base, 'i.department_id', $scope);

        $now = $this->naive(now());
        $from = $this->naive($period->from);
        $until = $this->naive($period->until);
        $query = DB::query()->fromSub($base, 'q');
        match ($measure) {
            'open', 'waiting_hours' => $query->where('q.status', 'in_progress'),
            'open_overdue' => $query->where('q.status', 'in_progress')->whereNotNull('q.due_at')->where('q.due_at', '<', $now),
            'completed', 'cycle_hours' => $query->where('q.status', 'completed')->where('q.completed_at', '>=', $from)->where('q.completed_at', '<', $until),
            'closed' => $query->whereIn('q.status', ['completed', 'rejected'])->where('q.completed_at', '>=', $from)->where('q.completed_at', '<', $until),
        };
        $contribution = match ($measure) {
            'waiting_hours' => 'EXTRACT(EPOCH FROM (CAST(? AS timestamp) - COALESCE(q.entered_at, q.started_at))) / 3600.0',
            'cycle_hours' => 'EXTRACT(EPOCH FROM (q.completed_at - q.started_at)) / 3600.0',
            default => '1',
        };

        return $query->select('q.*')->selectRaw($contribution.' AS contribution', $measure === 'waiting_hours' ? [$now] : []);
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference, 'workflow_name' => $row->workflow_name, 'current_step' => $row->status === 'in_progress' ? $row->current_step : '—',
            'current_holder' => $row->status === 'in_progress' ? ($row->current_holder ?: 'بلا مسؤول — راجع جاهزية الإعدادات') : '—',
            'started_at' => $row->started_at, 'entered_at' => $row->status === 'in_progress' ? $row->entered_at : null, 'completed_at' => $row->completed_at,
            'status' => ['in_progress' => 'قيد المعالجة', 'completed' => 'منجزة', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'][$row->status] ?? $row->status];
    }

    public function link(object $row, User $viewer): ?string
    {
        return app(WorkflowVisibilityService::class)->accessLevel($viewer, (int) $row->id) !== null
            ? route('workflows.show', ['publicId' => $row->public_id], false) : null;
    }
}
