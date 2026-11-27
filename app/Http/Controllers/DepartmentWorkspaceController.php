<?php

namespace App\Http\Controllers;

use App\Services\Departments\DepartmentWorkspace;
use App\Services\Finance\DepartmentBudgets;
use App\Services\Organization\ScopedDelegations;
use App\Services\Organization\UnitAssignments;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «قسمي» for department heads: team, leave, requests from the department, work waiting with its staff, the
 * distribution of staff over the department's functional units and time-limited delegations.
 */
class DepartmentWorkspaceController extends Controller
{
    public function index(Request $request, DepartmentWorkspace $workspace, UnitAssignments $units, ScopedDelegations $delegations, DepartmentBudgets $budgets): Response
    {
        $input = $request->validate(['department_id' => ['nullable', 'integer', 'min:1']], [], ['department_id' => 'القسم']);
        $user = $request->user();

        return Inertia::render('Departments/Workspace', [
            ...$workspace->overview($user, isset($input['department_id']) ? (int) $input['department_id'] : null),
            'units' => $units->overview($user),
            'departmentBudgets' => $budgets->summary($user, now()->format('Y-m'),
                isset($input['department_id']) && $budgets->headedDepartments($user)->contains('id', (int) $input['department_id']) ? (int) $input['department_id'] : null),
            'delegations' => $delegations->listFor($user),
            'delegationOptions' => $delegations->options($user),
        ]);
    }
}
