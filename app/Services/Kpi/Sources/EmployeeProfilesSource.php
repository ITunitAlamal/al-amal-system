<?php

namespace App\Services\Kpi\Sources;

use App\Models\User;
use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Active employee profiles, one row per employee, attributed to the employee's department. */
final class EmployeeProfilesSource extends KpiSource
{
    public function key(): string
    {
        return 'employee_profiles';
    }

    public function label(): string
    {
        return 'ملفات الموظفين';
    }

    public function basis(): string
    {
        return 'ملفات الموظفين النشطين غير المؤرشفة في وقت القراءة. «مكتمل» يعني أن الملف اجتاز التحقق النهائي في معالج ملف الموظف (onboarding_status = completed)، وهو لا يعني تدقيق صحة الوثائق. يُنسب الموظف إلى قسمه الحالي.';
    }

    public function lineage(): array
    {
        return ['employees: department_id, employment_status, onboarding_status, deleted_at, employee_number'];
    }

    public function measures(): array
    {
        return [
            'active' => ['label' => 'موظفون نشطون', 'kind' => 'count', 'snapshot' => true],
            'complete' => ['label' => 'ملفات مكتملة', 'kind' => 'count', 'snapshot' => true],
        ];
    }

    public function ratios(): array
    {
        return [['complete', 'active']];
    }

    public function attribution(): string
    {
        return 'قسم الموظف الحالي';
    }

    public function columns(): array
    {
        return ['reference' => 'الرقم الوظيفي', 'name' => 'الموظف', 'department_name' => 'القسم', 'status' => 'حالة الملف'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $query = DB::table('employees as e')->whereNull('e.deleted_at')->where('e.employment_status', 'active');
        if ($measure === 'complete') {
            $query->where('e.onboarding_status', 'completed');
        }
        $this->restrictTo($query, 'e.department_id', $scope);

        return $query->select(['e.id', 'e.department_id', 'e.employee_number as reference', 'e.onboarding_status'])
            ->selectRaw("concat_ws(' ', e.first_name_ar, e.father_name_ar, e.family_name_ar) AS name")
            ->selectRaw('1 AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference, 'name' => $row->name, 'status' => $row->onboarding_status === 'completed' ? 'مكتمل' : 'غير مكتمل'];
    }

    public function link(object $row, User $viewer): ?string
    {
        return $viewer->canManageEmployees() ? route('admin.employee-profiles.show', ['employee' => $row->id], false) : null;
    }
}
