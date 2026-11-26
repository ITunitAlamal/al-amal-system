<?php

namespace App\Services\Kpi\Sources;

use App\Models\User;
use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Leave requests, one row per request, attributed to the employee's department. */
final class LeaveRequestsSource extends KpiSource
{
    private const PENDING = ['submitted', 'manager_approved'];

    private const DECIDED = ['approved', 'rejected'];

    private const STATUS = ['draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'manager_approved' => 'بانتظار القرار النهائي', 'returned' => 'معادة للتعديل',
        'pending_registration' => 'معتمدة بانتظار التسجيل', 'approved' => 'معتمدة ومسجلة', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'];

    public function key(): string
    {
        return 'leave_requests';
    }

    public function label(): string
    {
        return 'طلبات الإجازة';
    }

    public function basis(): string
    {
        return 'طلب الإجازة سجل واحد منسوب إلى قسم الموظف. «بانتظار البت» = مقدم أو موصى به ولم يصدر قرار نهائي؛ المعاد للتعديل لدى الموظف لا يُحسب. '
            .'«مبتوت فيه» = معتمد أو مرفوض وتاريخ قراره داخل الفترة. زمن البت من وقت التقديم حتى القرار النهائي. المسودات والطلبات الملغاة خارج العينة.';
    }

    public function lineage(): array
    {
        return ['employee_leave_requests: status, submitted_at, decided_at, leave_type_value_id, workflow_instance_id', 'employees: department_id', 'master_data_values: leave_types'];
    }

    public function measures(): array
    {
        return [
            'pending' => ['label' => 'طلبات بانتظار البت', 'kind' => 'count', 'snapshot' => true],
            'decided' => ['label' => 'طلبات بُت فيها في الفترة', 'kind' => 'count', 'snapshot' => false],
            'approved' => ['label' => 'طلبات اعتُمدت في الفترة', 'kind' => 'count', 'snapshot' => false],
            'decision_hours' => ['label' => 'ساعات من التقديم حتى القرار', 'kind' => 'hours', 'snapshot' => false],
        ];
    }

    public function ratios(): array
    {
        return [['approved', 'decided']];
    }

    public function attribution(): string
    {
        return 'قسم الموظف صاحب الطلب';
    }

    public function columns(): array
    {
        return ['reference' => 'المعاملة', 'name' => 'الموظف', 'leave_type' => 'النوع', 'department_name' => 'القسم', 'submitted_at' => 'قُدم', 'decided_at' => 'القرار', 'status' => 'الحالة'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $query = DB::table('employee_leave_requests as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id');
        match ($measure) {
            'pending' => $query->whereIn('l.status', self::PENDING),
            'decided' => $query->whereIn('l.status', self::DECIDED),
            'approved' => $query->where('l.status', 'approved'),
            'decision_hours' => $query->whereIn('l.status', self::DECIDED)->whereNotNull('l.submitted_at'),
        };
        if ($measure !== 'pending') {
            $query->whereNotNull('l.decided_at')->where('l.decided_at', '>=', $period->from->toIso8601String())->where('l.decided_at', '<', $period->until->toIso8601String());
        }
        $this->restrictTo($query, 'e.department_id', $scope);

        return $query->select(['l.id', 'e.department_id', 'l.status', 'l.submitted_at', 'l.decided_at', 't.name_ar as leave_type', 'i.id as instance_id', 'i.public_id',
            'i.reference_no as reference'])
            ->selectRaw("concat_ws(' ', e.first_name_ar, e.family_name_ar) AS name")
            ->selectRaw(($measure === 'decision_hours' ? 'EXTRACT(EPOCH FROM (l.decided_at - l.submitted_at)) / 3600.0' : '1').' AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference ?? 'طلب #'.$row->id, 'name' => $row->name, 'leave_type' => $row->leave_type, 'submitted_at' => $row->submitted_at,
            'decided_at' => $row->decided_at, 'status' => self::STATUS[$row->status] ?? $row->status];
    }

    public function link(object $row, User $viewer): ?string
    {
        return $row->instance_id && app(WorkflowVisibilityService::class)->accessLevel($viewer, (int) $row->instance_id) !== null
            ? route('workflows.show', ['publicId' => $row->public_id], false) : null;
    }
}
