<?php

namespace App\Services\Dashboard\Boards;

use App\Models\User;
use App\Services\Organization\Authority;
use App\Services\WorkflowVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * رئيس القسم — which requests of my department are stuck or late. Counts follow the organization (the departments the
 * user validly heads, Organization\Authority, and their units); confidential workflows (complaints) are never counted for the head.
 */
final class DepartmentHeadBoard
{
    /** Departments whose own board replaces this one when the head can see it (code => board kind). */
    // EXEC is the executive director's office: the executive board already covers it (no second, overlapping board).
    public const SPECIALIZED = ['ORPHANS' => 'orphans', 'HR' => 'hr', 'FIN' => 'finance', 'ASSETS' => 'rentals', 'EXEC' => 'executive'];

    /**
     * @param  list<string>  $covered  board kinds the user already has
     * @return list<object>
     */
    public static function departments(User $user, array $covered = []): array
    {
        $skip = array_keys(array_filter(self::SPECIALIZED, fn (string $kind): bool => in_array($kind, $covered, true)));
        $headed = app(Authority::class)->headedDepartmentIds($user);

        return $headed === [] ? [] : DB::table('departments')->whereIn('id', $headed)
            ->whereNotIn('code', $skip ?: [''])->orderBy('name')->get(['id', 'code', 'name'])->all();
    }

    public function build(User $user, object $department): array
    {
        $ids = [$department->id, ...DB::table('departments')->where('parent_id', $department->id)->whereNull('deleted_at')->pluck('id')->all()];
        $instances = DB::table('workflow_instances as i')->whereIn('i.department_id', $ids)->whereNotIn('i.workflow_version_id', WorkflowVisibilityService::confidentialVersions());
        $open = (clone $instances)->where('i.status', 'in_progress');
        $today = today()->toDateString();
        $staff = DB::table('employees')->whereIn('department_id', $ids)->whereNull('deleted_at');
        $leaves = DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->whereIn('e.department_id', $ids);

        $rows = (clone $open)->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')->leftJoin('users as r', 'r.id', '=', 'i.requested_by')->leftJoin('users as a', 'a.id', '=', 'i.current_assignee_id')
            ->orderByRaw('i.sla_breached_at IS NULL')->orderBy('i.started_at')->limit(50)
            ->get(['i.public_id', 'i.reference_no', 'i.started_at', 'i.due_at', 'i.sla_breached_at', 'd.name as kind', 's.name as step', 'r.name as requester', 'a.name as holder'])
            ->map(fn (object $i): array => ['cells' => ['reference' => $i->reference_no, 'type' => $i->kind, 'subject' => $i->requester, 'step' => $i->step, 'holder' => $i->holder ?: 'غير مسند',
                'age' => BoardKit::age($i->started_at)], 'status' => $i->sla_breached_at ? ['label' => 'متأخر', 'tone' => 'danger'] : ['label' => 'جارٍ', 'tone' => 'info'],
                'url' => '/workflows/'.$i->public_id, 'action' => 'فتح', 'overdue' => (bool) $i->sla_breached_at])->all();

        $pr = $department->code === 'PR';

        return [
            'key' => 'department-'.$department->id, 'title' => 'قسم '.$department->name, 'decision' => 'أي طلبات قسمي متوقفة أو متأخرة، ومن غائب اليوم؟', 'scope' => $department->name.' ووحداته',
            'hero' => BoardKit::metric('طلبات القسم الجارية', (clone $open)->count(), 'down', [...BoardKit::weekOverWeek(clone $instances, 'i.started_at'), 'label' => 'بدأت خلال آخر 7 أيام'],
                'معاملات مسارات العمل المفتوحة في القسم ووحداته', '/my-department'),
            'kpis' => [
                BoardKit::metric('طلبات متأخرة عن مهلتها', (clone $open)->whereNotNull('i.sla_breached_at')->count(), 'down', url: '/my-department'),
                BoardKit::metric('إجازات بانتظار المراجعة', (clone $leaves)->whereIn('l.status', ['submitted', 'manager_approved'])->count(), 'down', url: route('leaves.index', [], false)),
                BoardKit::metric('في إجازة اليوم', (clone $leaves)->where('l.status', 'approved')->where('l.start_date', '<=', $today)->where('l.end_date', '>=', $today)->count(), null,
                    detail: 'من '.(clone $staff)->where('employment_status', 'active')->count().' موظفًا فعّالًا'),
            ],
            'trend' => BoardKit::trend('الطلبات الواردة والمنجزة أسبوعيًا', 'الواردة = معاملات بدأت في القسم؛ المنجزة = معاملات اكتملت. نوافذ 7 أيام تنتهي اليوم.', [
                ['label' => 'واردة', 'values' => BoardKit::weekly(clone $instances, 'i.started_at')],
                ['label' => 'منجزة', 'values' => BoardKit::weekly((clone $instances)->where('i.status', 'completed'), 'i.completed_at')],
            ]),
            'bars' => BoardKit::bars('الطلبات الجارية حسب النوع', 'المعاملات المفتوحة الآن في القسم حسب نوع المسار.',
                (clone $open)->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                    ->groupBy('d.name')->selectRaw('d.name AS label, count(*) AS value')->get()->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('يحتاج إلى متابعة', [['key' => 'reference', 'label' => 'المرجع'], ['key' => 'type', 'label' => 'النوع'], ['key' => 'subject', 'label' => 'مقدم الطلب'],
                ['key' => 'step', 'label' => 'المرحلة'], ['key' => 'holder', 'label' => 'لدى'], ['key' => 'age', 'label' => 'العمر']], $rows, 'لا توجد طلبات جارية في القسم.', '/my-department'),
            'notes' => $pr ? ['مقاييس النشر والتفاعل غير متاحة: لا يوجد في النظام مصدر بيانات لها. يتولى القسم الحملات الإغاثية المسندة إليه فقط، دون اطلاع عام على ملفات الأيتام.'] : [],
            'actions' => [['label' => 'قسمي', 'url' => '/my-department', 'icon' => 'building']],
        ];
    }
}
