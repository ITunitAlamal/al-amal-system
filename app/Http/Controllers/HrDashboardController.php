<?php

namespace App\Http\Controllers;

use App\Services\Hr\DepartmentFollowUp;
use App\Services\Hr\HrServicesDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «متابعة القسم»: the requests of the unified department (HR, general services and maintenance) in one list, with
 * the tasks of its people and the latest leave requests below. The head works from it; the executive director and
 * the system administrator read it. Every action opens the request, where the workflow itself decides who may act.
 */
class HrDashboardController extends Controller
{
    private const DENIED = '«متابعة القسم» لرئيس قسم الموارد البشرية والخدمات العامة، وللمدير التنفيذي ومدير النظام للاطلاع.';

    public function index(Request $request, DepartmentFollowUp $followUp, HrServicesDashboard $dashboard): Response
    {
        $user = $request->user();
        abort_unless($user && HrServicesDashboard::canView($user), 403, self::DENIED);
        $types = $followUp->types();
        $data = $request->validate([
            // One type, or several separated by commas (the department figures open both of its own types together).
            'type' => ['nullable', 'string', 'max:200'],
            // «جاهز للإسناد» (ready) is part of "head", opened from the head's home card of that name.
            'holder' => ['nullable', Rule::in([...array_keys(DepartmentFollowUp::HOLDER_FILTERS), DepartmentFollowUp::READY_FILTER])],
            'status' => ['nullable', Rule::in(array_keys(DepartmentFollowUp::STATUS_FILTERS))],
            // Where a service or maintenance request stands (HR requirements §5).
            'stage' => ['nullable', Rule::in(array_keys(DepartmentFollowUp::STAGE_FILTERS))],
            // The filter's name before the page became «متابعة القسم» (its values were open/all); old links keep working.
            'services' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1'],
            'leaves_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $legacy = (string) ($data['services'] ?? '');
        $status = $data['status'] ?? (isset(DepartmentFollowUp::STATUS_FILTERS[$legacy]) ? $legacy : 'open');
        $filters = [
            'types' => array_values(array_intersect(array_keys($types), array_map('trim', explode(',', (string) ($data['type'] ?? ''))))),
            // A closed request has no holder.
            'holder' => $status === 'closed' ? null : ($data['holder'] ?? null),
            'status' => $status,
            // Stages are those of open requests.
            'stage' => $status === 'closed' ? null : ($data['stage'] ?? null),
        ];

        return Inertia::render('Hr/Dashboard', [
            'department' => (string) DB::table('departments')->where('id', HrServicesDashboard::departmentId())->value('name'),
            'readOnly' => ! $user->isHrDepartmentHead(),
            'filters' => $filters,
            'typeOptions' => $types,
            'holderOptions' => DepartmentFollowUp::HOLDER_FILTERS,
            'readyLabel' => DepartmentFollowUp::READY_LABEL,
            'statusOptions' => DepartmentFollowUp::STATUS_FILTERS,
            'stageOptions' => DepartmentFollowUp::STAGE_FILTERS,
            // How the open requests of the chosen types are held and where they stand: the numbers on the filters.
            'summary' => fn (): array => $followUp->summary($user, $filters['types'], $filters['holder'], $filters['stage']),
            'requests' => fn () => $followUp->page($user, $filters, (int) ($data['page'] ?? 1)),
            'links' => fn (): array => $dashboard->links($user),
            'tasks' => Inertia::defer(fn (): array => $dashboard->departmentTasks($user, 15), 'tables'),
            // Open tasks per member of the department: how many, how many late, and the longest wait.
            'team' => Inertia::defer(fn (): array => $dashboard->teamLoad($user), 'tables'),
            // Staff files: documents expired or near expiry, and files missing a mandatory document.
            'documents' => Inertia::defer(fn (): ?array => $dashboard->documentsCard($user), 'tables'),
            'recentLeaves' => Inertia::defer(fn (): array => $dashboard->recentLeaves($user, (int) ($data['leaves_page'] ?? 1)), 'tables'),
        ]);
    }
}
