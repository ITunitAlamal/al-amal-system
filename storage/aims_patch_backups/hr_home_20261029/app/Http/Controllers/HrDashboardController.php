<?php

namespace App\Http\Controllers;

use App\Services\Hr\HrServicesDashboard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الموارد البشرية والخدمات العامة»: the HR head's dashboard (also the system administrator and the executive
 * director). Read-only: every action links to the existing screen and workflow that performs it.
 */
class HrDashboardController extends Controller
{
    public function __construct(private readonly HrServicesDashboard $dashboard) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && HrServicesDashboard::canView($user), 403, 'لوحة الموارد البشرية والخدمات العامة لرئيس الموارد البشرية ومدير النظام والمدير التنفيذي.');
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'q' => ['nullable', 'string', 'max:80'],
            'attendance_status' => ['nullable', Rule::in(array_keys(HrServicesDashboard::ATTENDANCE_STATUS))],
            'services' => ['nullable', Rule::in(array_keys(HrServicesDashboard::SERVICE_FILTERS))],
            'attendance_page' => ['nullable', 'integer', 'min:1'],
            'leaves_page' => ['nullable', 'integer', 'min:1'],
            'services_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $filters = [
            'date' => $date, 'q' => $data['q'] ?? '', 'attendance_status' => $data['attendance_status'] ?? '',
            'services' => $data['services'] ?? 'open',
        ];

        return Inertia::render('Hr/Dashboard', [
            'today' => now()->toDateString(),
            'filters' => $filters,
            'attendanceStatuses' => HrServicesDashboard::ATTENDANCE_STATUS,
            'serviceFilters' => HrServicesDashboard::SERVICE_FILTERS,
            'links' => $this->dashboard->links($user),
            'kpis' => Inertia::defer(fn (): array => $this->dashboard->kpis($user, $date), 'summary'),
            'actions' => Inertia::defer(fn (): array => [
                'leaves' => $this->dashboard->pendingLeaves($user),
                'attendance' => $this->dashboard->attendanceExceptions($date),
                'tasks' => $this->dashboard->departmentTasks($user),
            ], 'actions'),
            'attendance' => Inertia::defer(fn (): array => $this->dashboard->attendanceDay($date, $filters['q'] ?: null, $filters['attendance_status'] ?: null, (int) ($data['attendance_page'] ?? 1)), 'tables'),
            'recentLeaves' => Inertia::defer(fn (): array => $this->dashboard->recentLeaves((int) ($data['leaves_page'] ?? 1)), 'tables'),
            'services' => Inertia::defer(fn (): array => $this->dashboard->services($user, $filters['services'], (int) ($data['services_page'] ?? 1)), 'tables'),
        ]);
    }
}
