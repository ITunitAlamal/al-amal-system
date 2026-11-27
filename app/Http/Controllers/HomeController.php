<?php

namespace App\Http\Controllers;

use App\Services\Configuration\SystemReadiness;
use App\Services\Dashboard\DashboardLayoutResolver;
use App\Services\Dashboard\PersonalTasks;
use App\Services\Dashboard\PersonalWorkspace;
use App\Services\Dashboard\RoleBoards;
use App\Services\Finance\DepartmentBudgets;
use App\Services\HomeDashboard;
use App\Services\Hr\DepartmentHome;
use App\Services\Hr\HrHomeDashboard;
use App\Services\Hr\HrServicesDashboard;
use App\Services\Notifications\NotificationCenter;
use App\Services\Rentals\RentalsDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The personal home page ("مساحتي"). Its blocks, order and sizes come from the dashboard templates
     * (DashboardLayoutResolver); a block appears only when the user's own access provides it: a template can
     * hide or arrange blocks, never reveal one, and locked blocks (the user's work) cannot be hidden.
     * The heavier personal and role blocks load after the page.
     *
     * The unified department (HR, general services and maintenance): its head has one dashboard, HrHomeDashboard
     * («بانتظار إجرائي» first), with ?workspace=personal for their personal services; its other people open this page
     * on «بانتظار إجرائي» and «طلباتي». Everyone else keeps the page as it is.
     */
    public function index(Request $request, HomeDashboard $dashboard, SystemReadiness $readiness, DashboardLayoutResolver $layouts,
        NotificationCenter $notifications, PersonalWorkspace $workspace, PersonalTasks $todo, HrHomeDashboard $hr, DepartmentBudgets $budgets): Response
    {
        $user = $request->user();
        $hrHead = $user->isHrDepartmentHead();
        if ($hrHead && $request->query('workspace') !== 'personal') {
            return Inertia::render('Hr/Home', $hr->props($request));
        }
        $member = ! $hrHead && HrServicesDashboard::isMember($user);
        $home = $dashboard->for($user);
        $fromHome = ['team' => $home['team'] !== null, 'executive' => $home['executive'] !== null, 'finance' => $home['finance'] !== null,
            'start_options' => $home['startOptions'] !== [] && $layouts->unavailableReason($user, 'start_options') === null, 'evaluation' => $home['evaluation'] !== null];
        $blocks = [];
        foreach ($layouts->resolve($user)['components'] as $key => $component) {
            if (! $component['visible']) {
                continue;
            }
            $available = array_key_exists($key, $fromHome) ? $fromHome[$key] : $layouts->unavailableReason($user, $key) === null;
            if ($available) {
                $blocks[$key] = ['key' => $key, 'size' => $component['size'], 'section' => $component['section'] ?? 'base', 'options' => $component['options']];
            }
        }
        $limit = fn (string $key, int $default): int => (int) ($blocks[$key]['options']['limit'] ?? $default);
        if ($hrHead) {
            unset($blocks['hr_services']);
        }
        // «طلباتي» at the top of a member's page follows the template like the block it replaces (it is not locked).
        $memberRequests = $member && isset($blocks['my_requests']);
        if ($member) {
            // Shown at the top of the page instead: the tasks as «بانتظار إجرائي», the requests as «طلباتي».
            unset($blocks['tasks'], $blocks['my_requests']);
        }

        return Inertia::render('Home', [
            'hrHomeUrl' => $hrHead ? route('ui.preview', [], false) : null,
            'departmentHome' => $member ? 'member' : null,
            'memberRequests' => $memberRequests,
            'waiting' => fn () => $member ? app(DepartmentHome::class)->waiting($user, $dashboard->tasks($user)) : null,
            'departmentBudgets' => Inertia::defer(fn (): array => $budgets->summary($user, now()->format('Y-m')), 'department-budgets'),
            'executiveWorkspace' => $layouts->resolve($user)['executive_workspace'],
            'home' => $home,
            'layout' => array_values(array_map(fn (array $block): array => ['key' => $block['key'], 'size' => $block['size'], 'section' => $block['section']], $blocks)),
            'actionCenter' => fn () => isset($blocks['action_center']) ? $workspace->actionCenter($user, $home['stats']) : [],
            'shortcuts' => fn () => isset($blocks['shortcuts']) ? array_values(array_filter($layouts->shortcuts($user)['allowed'], fn (array $shortcut): bool => ! in_array($shortcut['code'], ['kpi_dashboard', 'hr_dashboard'], true))) : [],
            'notifications' => fn () => isset($blocks['notifications']) ? $notifications->recent($user, $limit('notifications', 5))->values() : [],
            'todo' => isset($blocks['todo']) ? Inertia::defer(fn (): array => $todo->summary($user, $limit('todo', 5)), 'personal') : null,
            'correspondence' => isset($blocks['correspondence']) ? Inertia::defer(fn (): array => $workspace->correspondence($user), 'personal') : null,
            'calendar' => isset($blocks['calendar']) ? Inertia::defer(fn (): array => $workspace->calendar($user), 'personal') : null,
            'activity' => isset($blocks['activity']) ? Inertia::defer(fn (): array => $workspace->activity($user), 'personal') : null,
            'hrServices' => isset($blocks['hr_services']) ? Inertia::defer(fn (): array => app(HrServicesDashboard::class)->kpis($user, now()->toDateString())['main'], 'role') : null,
            'rentals' => isset($blocks['rentals']) ? Inertia::defer(fn (): array => app(RentalsDashboard::class)->summary($user), 'role') : null,
            'readiness' => fn () => isset($blocks['readiness']) ? $readiness->checks() : null,
            // Role dashboards (hero, trend, breakdown, action table) for each real role of the user; loaded after the page.
            'boards' => Inertia::defer(fn (): array => app(RoleBoards::class)->for($user, $hrHead ? ['hr'] : []), 'boards'),
        ]);
    }
}
