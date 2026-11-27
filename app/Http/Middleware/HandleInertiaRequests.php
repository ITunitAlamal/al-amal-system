<?php

namespace App\Http\Middleware;

use App\Services\Configuration\NavigationBuilder;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Notifications\NotificationCenter;
use App\Services\Portal\PortalNotifier;
use App\Services\Progress\TransactionTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'inertia';

    public function share(Request $request): array
    {
        if ($request->is('guardian', 'guardian/*')) {
            return [...parent::share($request),
                'auth' => null,
                'menu' => [],
                // Only what the portal shell needs: name, account state and the unread notice count.
                'guardian' => fn () => ($account = Auth::guard('guardian')->user()) ? ['name' => $account->name, 'status' => $account->account_status,
                    'unread' => app(PortalNotifier::class)->unread($account->id)] : null,
                'flash' => ['success' => fn () => $request->session()->get('success')],
            ];
        }

        return [
            ...parent::share($request),
            // Sidebar built from navigation_items; see Admin/NavigationController.
            'menu' => fn () => app(NavigationBuilder::class)->forUser($request->user('web')),
            'guardian' => null,
            'notifications' => fn () => $request->user('web')
                ? ['unread' => app(NotificationCenter::class)->unreadCount($request->user('web'))]
                : ['unread' => 0],
            // Counters of work waiting for the user. "tasks" is the one number of «مهامي»: the sidebar badge, the
            // top-bar counter and the page heading all show what TransactionTracker::tasksOf lists. The tracker is one
            // instance per request (scoped in AppServiceProvider): on a page that already read the list this is its
            // length, elsewhere count queries only. A failure is logged and shows no badge rather than breaking every page.
            'workCounters' => fn () => $request->user('web') ? [
                'tasks' => $request->user('web')->is_active
                    ? (int) rescue(fn (): int => app(TransactionTracker::class)->taskCount($request->user('web')), 0) : 0,
                'correspondence' => Schema::hasTable('correspondence_tasks')
                    ? app(CorrespondenceAccess::class)->mailbox($request->user('web'), 'needs_action')->count() : 0,
            ] : null,

            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'employee_number' => $request->user()->employee_number,
                        'department_id' => $request->user()->department_id,
                        'is_active' => (bool) $request->user()->is_active,
                    ]
                    : null,

                'permissions' => fn () => $request->user()
                    ? [
                        'is_system_admin' => $request->user()->isSystemAdmin(),

                        'can_manage_organization' => $request->user()->canManageOrganization(),

                        'can_manage_employees' => $request->user()->canManageEmployees(),

                        'can_manage_financial_settings' => $request->user()->isSystemAdmin(),

                        'can_view_audit_logs' => $request->user()->isSystemAdmin(),
                    ]
                    : [
                        'is_system_admin' => false,
                        'can_manage_organization' => false,
                        'can_manage_employees' => false,
                        'can_manage_financial_settings' => false,
                        'can_view_audit_logs' => false,
                    ],
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
