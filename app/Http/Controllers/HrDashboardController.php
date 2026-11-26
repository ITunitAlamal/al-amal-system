<?php

namespace App\Http\Controllers;

use App\Services\Hr\HrDashboardContent;
use App\Services\Hr\HrServicesDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HrDashboardController extends Controller
{
    public function index(Request $request, HrDashboardContent $content): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && HrServicesDashboard::canView($user), 403, 'لوحة الموارد البشرية والخدمات العامة لرئيس الموارد البشرية ومدير النظام والمدير التنفيذي.');
        if ($user->isHrDepartmentHead()) {
            return redirect()->route('ui.preview', $request->only(['date', 'q', 'attendance_status', 'services', 'attendance_page', 'leaves_page', 'services_page']));
        }

        return Inertia::render('Hr/Dashboard', $content->props($request));
    }
}
