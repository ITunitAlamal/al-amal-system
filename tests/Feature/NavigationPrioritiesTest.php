<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Configuration\NavigationPriorities;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class NavigationPrioritiesTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('session.driver', 'array');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach ([
            'roles' => ['code', 'name'],
            'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on'],
            'departments' => ['code', 'parent_id', 'node_type', 'head_user_id', 'is_active', 'deleted_at'],
            'navigation_items' => ['code', 'parent_id', 'label_ar', 'icon', 'route_name', 'route_params', 'access_rule', 'audience_roles', 'is_active', 'sort_order'],
            'dashboard_layouts' => ['scope_type', 'scope_key', 'current_version', 'is_active'],
            'dashboard_layout_versions' => ['layout_id', 'version', 'configuration'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
    }

    public static function contexts(): array
    {
        return [
            'executive' => [null, 'executive_director', 'executive', 'dashboard'],
            'administrator' => [null, 'system_admin', 'administrator', 'dashboard'],
            'hr head' => ['HR', 'department_head', 'hr_head', 'dashboard'],
            'hr employee' => ['HR', 'department_employee', 'hr_staff', 'my_tasks'],
            'orphans' => ['ORPHANS', 'department_employee', 'orphans', 'dashboard'],
            'public relations' => ['PR', 'department_employee', 'public_relations', 'dashboard'],
            'finance' => ['FIN', 'finance_accountant', 'finance', 'dashboard'],
            'assets' => ['ASSETS', 'rentals_officer', 'assets', 'dashboard'],
            'regular employee' => [null, null, 'employee', 'dashboard'],
        ];
    }

    #[DataProvider('contexts')]
    public function test_work_context_sorts_without_adding_destinations(?string $department, ?string $role, string $context, string $first): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => $department ? 1 : null]);
        if ($department) {
            DB::table('departments')->insert(['id' => 1, 'code' => $department, 'node_type' => 'department', 'head_user_id' => $role === 'department_head' ? 10 : null, 'is_active' => true]);
        }
        if ($role) {
            $this->assign($user, $role, $department ? 1 : null);
        }
        $menu = [$this->link('my_profile'), $this->link('my_tasks'), $this->link('dashboard'), $this->link('custom')];
        $service = app(NavigationPriorities::class);
        self::assertSame($context, $service->contexts($user, $menu)[0]);
        $sorted = $service->forUser($user, $menu);
        self::assertSame($first, $sorted[0]['code']);
        self::assertEqualsCanonicalizing($menu, $sorted);
        self::assertSame($sorted, $service->forUser($user, $menu));
        $rules = app(NavigationAccessRules::class);
        self::assertTrue($rules->allows($user, 'authenticated'));
        self::assertSame($context === 'administrator', $rules->allows($user, 'system_admin'));
        self::assertSame(in_array($context, ['administrator', 'executive', 'hr_head'], true), $rules->allows($user, 'attendance_access'));
    }

    public function test_primary_unit_inherits_nearest_function_before_secondary_assignments(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => 2]);
        DB::table('departments')->insert([
            ['id' => 1, 'code' => 'FIN', 'parent_id' => null, 'is_active' => true],
            ['id' => 2, 'code' => 'FIN-U1', 'parent_id' => 1, 'is_active' => true],
            ['id' => 3, 'code' => 'HR', 'parent_id' => null, 'is_active' => true],
            ['id' => 4, 'code' => 'MAINT', 'parent_id' => 3, 'is_active' => true],
        ]);
        $this->assign($user, 'department_employee', 3);
        self::assertSame(['finance', 'hr_staff'], app(NavigationPriorities::class)->contexts($user, []));
        $user->department_id = 4;
        self::assertSame(['maintenance', 'hr_staff'], app(NavigationPriorities::class)->contexts($user, []));
    }

    public static function priorities(): array
    {
        return [
            ['executive', ['dashboard', 'finance_approvals', 'kpi_dashboard', 'my_department', 'my_tasks']],
            ['administrator', ['dashboard', 'employee_accounts', 'departments', 'master_data', 'audit_logs']],
            ['hr_head', ['dashboard', 'employee_profiles', 'attendance', 'leaves_requests', 'my_tasks']],
            ['hr_staff', ['my_tasks', 'employee_profiles', 'attendance', 'my_requests', 'dashboard']],
            ['orphans', ['dashboard', 'kpi_dashboard', 'my_tasks', 'official_correspondence', 'my_requests']],
            ['public_relations', ['dashboard', 'my_tasks', 'official_correspondence', 'my_requests', 'my_profile']],
            ['finance', ['dashboard', 'finance_transactions', 'finance_receivables', 'finance_reports', 'my_tasks']],
            ['assets', ['dashboard', 'rentals_file', 'rentals_buildings', 'maintenance_draft', 'my_tasks']],
            ['employee', ['dashboard', 'my_tasks', 'my_requests', 'leaves_requests', 'my_profile']],
        ];
    }

    #[DataProvider('priorities')]
    public function test_role_priority_order_is_independent_of_counts(string $context, array $expected): void
    {
        $menu = array_map(fn (string $code): array => $this->link($code) + ['count' => 500], array_reverse($expected));
        self::assertSame($expected, array_column(app(NavigationPriorities::class)->arrange($menu, $context), 'code'));
        foreach ($menu as $index => &$item) {
            $item['count'] = $index;
        }
        unset($item);
        self::assertSame($expected, array_column(app(NavigationPriorities::class)->arrange($menu, $context), 'code'));
    }

    public function test_expired_future_and_inactive_department_assignments_do_not_drive_priority(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => null]);
        $this->assign($user, 'executive_director', null, ['ends_on' => today()->subDay()->toDateString()]);
        $this->assign($user, 'system_admin', null, ['starts_on' => today()->addDay()->toDateString()]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'FIN', 'is_active' => false]);
        $this->assign($user, 'finance_accountant', 1);
        self::assertSame(['employee'], app(NavigationPriorities::class)->contexts($user, []));
    }

    public function test_grouping_preserves_custom_labels_deep_links_and_unlisted_order(): void
    {
        $attendance = $this->link('attendance');
        $attendance['label'] = 'تسمية الموارد البشرية المخصصة';
        $operations = $this->link('attendance_operations', '/admin/attendance/operations?view=devices');
        $leave = $this->link('leaves_requests');
        $menu = [['code' => 'leaves', 'label' => 'الدوام والإجازات', 'url' => null, 'icon' => 'calendar', 'children' => [$leave, $operations, $attendance]], $this->link('custom-first'), $this->link('custom-second')];
        $result = app(NavigationPriorities::class)->arrange($menu, 'hr_head');
        self::assertSame('attendance_workspace', $result[0]['code']);
        self::assertSame($attendance['label'], $result[0]['label']);
        self::assertSame([$attendance, $operations], $result[0]['children']);
        self::assertSame([$leave], $result[1]['children']);
        self::assertSame(['custom-first', 'custom-second'], array_column(array_slice($result, 2), 'code'));
    }

    public function test_builder_filters_permissions_before_priority_and_keeps_hr_boundary(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => 1]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'HR', 'head_user_id' => 10, 'is_active' => true]);
        $this->assign($user, 'department_head', 1);
        foreach ([['my_tasks', 'tasks.index', 'authenticated'], ['master_data', 'admin.master-data.index', 'system_admin'], ['attendance', 'admin.attendance.index', 'attendance_access'], ['dashboard', 'ui.preview', 'authenticated']] as $index => [$code, $route, $rule]) {
            DB::table('navigation_items')->insert(['code' => $code, 'route_name' => $route, 'access_rule' => $rule, 'label_ar' => $code, 'icon' => 'calendar', 'is_active' => true, 'sort_order' => $index]);
        }
        self::assertSame(['dashboard', 'attendance', 'my_tasks'], array_column(app(NavigationBuilder::class)->forUser($user), 'code'));
        $employee = User::factory()->make(['id' => 11, 'is_active' => true, 'department_id' => 1]);
        self::assertSame(['my_tasks', 'dashboard'], array_column(app(NavigationBuilder::class)->forUser($employee), 'code'));
        self::assertFalse(app(NavigationAccessRules::class)->allows($employee, 'attendance_access'));
    }

    public function test_session_context_is_stable_but_removed_when_no_longer_effective(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => 1]);
        DB::table('departments')->insert([['id' => 1, 'code' => 'HR', 'is_active' => true], ['id' => 2, 'code' => 'FIN', 'is_active' => true]]);
        $this->assign($user, 'finance_accountant', 2);
        request()->setLaravelSession(app('session')->driver());
        request()->session()->put('navigation.work_context.10', 'finance');
        $menu = [$this->link('my_tasks'), $this->link('dashboard')];
        self::assertSame('dashboard', app(NavigationPriorities::class)->forUser($user, $menu)[0]['code']);
        DB::table('department_user_roles')->update(['ends_on' => today()->subDay()->toDateString()]);
        self::assertSame('my_tasks', app(NavigationPriorities::class)->forUser($user, $menu)[0]['code']);
        self::assertSame('hr_staff', request()->session()->get('navigation.work_context.10'));
    }

    private function assign(User $user, string $code, ?int $departmentId, array $dates = []): void
    {
        $role = DB::table('roles')->insertGetId(['code' => $code]);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => $departmentId, ...$dates]);
    }

    private function link(string $code, ?string $url = null): array
    {
        return ['code' => $code, 'label' => $code, 'icon' => 'calendar', 'url' => $url ?? '/'.$code, 'children' => []];
    }

    public function test_hr_head_lands_at_my_workspace_and_legacy_link_keeps_filters(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => 1]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'HR', 'head_user_id' => 10, 'is_active' => true]);
        $this->assign($user, 'department_head', 1);
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('ui.preview'));
        $this->get('/hr-services?date=2026-09-01&attendance_page=2')->assertRedirect(route('ui.preview', ['date' => '2026-09-01', 'attendance_page' => 2]));
    }

    public function test_hr_sidebar_entry_is_removed_without_exposing_hr_data_to_staff(): void
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true, 'department_id' => null]);
        DB::table('navigation_items')->insert(['code' => 'hr_dashboard', 'route_name' => 'hr.dashboard', 'access_rule' => 'authenticated', 'label_ar' => 'HR', 'icon' => 'chart', 'is_active' => true]);
        self::assertSame([], app(NavigationBuilder::class)->forUser($user));
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->actingAs($user)->get('/hr-services')->assertForbidden();
    }
}
