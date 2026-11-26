<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Dashboard\DashboardLayoutResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExecutiveDashboardTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);

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
            'departments' => ['name', 'head_user_id', 'parent_id', 'is_active', 'deleted_at'],
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
        DB::table('roles')->insert([
            ['id' => 1, 'code' => 'executive_director', 'name' => 'Executive'],
            ['id' => 2, 'code' => 'system_admin', 'name' => 'Administrator'],
        ]);
        foreach (['dashboard', 'my_tasks', 'my_requests', 'official_correspondence', 'kpi_dashboard', 'hr_group', 'employee_profiles'] as $index => $code) {
            DB::table('navigation_items')->insert(['id' => $index + 1, 'code' => $code, 'label_ar' => $code,
                'parent_id' => $code === 'employee_profiles' ? 6 : null, 'route_name' => 'tasks.index', 'access_rule' => 'authenticated', 'is_active' => true]);
        }
    }

    public function test_executive_sees_decisions_and_personal_work_without_operational_links_even_in_custom_shortcuts(): void
    {
        $user = User::factory()->make(['id' => 20, 'is_active' => true]);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => 1]);
        DB::table('dashboard_layouts')->insert(['id' => 1, 'scope_type' => 'role', 'scope_key' => 'executive_director', 'current_version' => 1, 'is_active' => true]);
        DB::table('dashboard_layout_versions')->insert(['layout_id' => 1, 'version' => 1, 'configuration' => json_encode([
            'components' => ['hr_services' => ['visible' => true], 'kpis' => ['visible' => true]],
            'shortcuts' => ['employee_profiles', 'my_tasks'], 'landing' => 'employee_profiles',
        ])]);
        $resolver = app(DashboardLayoutResolver::class);
        $layout = $resolver->resolve($user);
        self::assertTrue($layout['executive_workspace']);
        self::assertSame('executive', $layout['components']['tasks']['section']);
        self::assertTrue($layout['components']['tasks']['visible']);
        self::assertFalse($layout['components']['kpis']['visible']);
        foreach (['hr_services', 'team', 'rentals'] as $key) {
            self::assertFalse($layout['components'][$key]['visible']);
        }
        self::assertSame(['my_tasks'], array_column($resolver->shortcuts($user)['allowed'], 'code'));
        self::assertNull($resolver->landingUrl($user));
        $codes = array_column(app(NavigationBuilder::class)->forUser($user), 'code');
        self::assertNotContains('hr_group', $codes);
        foreach (['dashboard', 'my_tasks', 'my_requests', 'official_correspondence', 'kpi_dashboard'] as $code) {
            self::assertContains($code, $codes);
        }
    }

    public function test_administrators_staff_and_expired_or_future_executive_assignments_keep_their_normal_layout(): void
    {
        foreach ([[], [['role_id' => 2]], [['role_id' => 1], ['role_id' => 2]],
            [['role_id' => 1, 'ends_on' => today()->subDay()->toDateString()]],
            [['role_id' => 1, 'starts_on' => today()->addDay()->toDateString()]],
        ] as $index => $assignments) {
            $user = User::factory()->make(['id' => $index + 30, 'is_active' => true]);
            foreach ($assignments as $assignment) {
                DB::table('department_user_roles')->insert(['user_id' => $user->id, ...$assignment]);
            }
            $layout = app(DashboardLayoutResolver::class)->resolve($user);
            self::assertFalse($layout['executive_workspace']);
            self::assertFalse($layout['components']['kpis']['visible']);
            self::assertSame('base', $layout['components']['tasks']['section']);
            self::assertSame([], $layout['hidden_navigation']);
        }
    }
}
