<?php

namespace App\Http\Controllers;

use App\Services\Hr\EmployeeProfileView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «ملفي الوظيفي»: the signed-in employee's own file, with the sections the employee may see (pay only when the
 * administration enables it, the bank account masked). Never another employee's data.
 */
class MyProfileController extends Controller
{
    public function show(Request $request, EmployeeProfileView $view): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $employee = $user->employeeProfile;

        return Inertia::render('My/Profile', $employee ? $view->build($user, $employee) : ['employee' => null]);
    }
}
