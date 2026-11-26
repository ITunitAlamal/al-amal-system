<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Daily attendance results, one row per employee-day, attributed to the employee's department. */
final class AttendanceDaysSource extends KpiSource
{
    /** Scheduled working days the attendance service evaluated against punches. */
    private const EVALUATED = ['present', 'late', 'early', 'missing_punches', 'incomplete_punches'];

    private const STATUS = ['present' => 'حضور منتظم', 'late' => 'تأخير بعد مهلة السماح', 'early' => 'خروج مبكر', 'missing_punches' => 'بلا بصمات', 'incomplete_punches' => 'بصمات ناقصة'];

    public function key(): string
    {
        return 'attendance_days';
    }

    public function label(): string
    {
        return 'أيام الدوام المحتسبة';
    }

    public function basis(): string
    {
        return 'نتائج الحضور اليومية كما تحسبها وحدة الحضور من البصمات والجدول المعتمد ومهلة السماح المعتمدة. العينة أيام العمل المجدولة التي قُيّمت (حضور، تأخير، خروج مبكر، بلا بصمات، بصمات ناقصة) داخل الفترة وحتى اليوم. '
            .'الإجازات والعطل وأيام الراحة والاستثناءات وأيام الجداول غير الصالحة أو مهلة السماح غير المعتمدة خارج العينة. إعادة احتساب يوم تغيّر نتيجته.';
    }

    public function lineage(): array
    {
        return ['attendance_daily_results: employee_id, work_date, status, late_minutes, early_minutes', 'employees: department_id'];
    }

    public function measures(): array
    {
        return [
            'evaluated' => ['label' => 'أيام عمل مجدولة قُيّمت', 'kind' => 'count', 'snapshot' => false],
            'on_time' => ['label' => 'أيام حضور منتظم', 'kind' => 'count', 'snapshot' => false],
            'late' => ['label' => 'أيام تأخير', 'kind' => 'count', 'snapshot' => false],
            'early' => ['label' => 'أيام خروج مبكر', 'kind' => 'count', 'snapshot' => false],
            'absent' => ['label' => 'أيام بلا بصمات', 'kind' => 'count', 'snapshot' => false],
        ];
    }

    public function ratios(): array
    {
        return [['on_time', 'evaluated'], ['late', 'evaluated'], ['early', 'evaluated'], ['absent', 'evaluated']];
    }

    public function attribution(): string
    {
        return 'قسم الموظف';
    }

    public function columns(): array
    {
        return ['work_date' => 'اليوم', 'reference' => 'الرقم الوظيفي', 'name' => 'الموظف', 'department_name' => 'القسم', 'status' => 'النتيجة', 'late_minutes' => 'دقائق التأخير'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $query = DB::table('attendance_daily_results as a')->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('a.work_date', '>=', $period->from->toDateString())->where('a.work_date', '<', $period->until->toDateString())
            ->where('a.work_date', '<=', now()->toDateString());
        match ($measure) {
            'evaluated' => $query->whereIn('a.status', self::EVALUATED),
            'on_time' => $query->where('a.status', 'present'),
            'late' => $query->where('a.status', 'late'),
            'early' => $query->where('a.status', 'early'),
            'absent' => $query->where('a.status', 'missing_punches'),
        };
        $this->restrictTo($query, 'e.department_id', $scope);

        return $query->select(['a.id', 'e.department_id', 'a.work_date', 'a.status', 'a.late_minutes', 'e.employee_number as reference'])
            ->selectRaw("concat_ws(' ', e.first_name_ar, e.family_name_ar) AS name")
            ->selectRaw('1 AS contribution');
    }

    public function present(object $row): array
    {
        return ['work_date' => $row->work_date, 'reference' => $row->reference, 'name' => $row->name, 'status' => self::STATUS[$row->status] ?? $row->status, 'late_minutes' => $row->late_minutes];
    }
}
