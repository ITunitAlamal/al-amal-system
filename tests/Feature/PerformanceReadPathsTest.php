<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Configuration\SystemReadiness;
use App\Services\Workflow\AssigneeResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PerformanceReadPathsTest extends TestCase
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
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        $tables = [
            'roles' => ['code', 'name'],
            'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on'],
            'departments' => ['name', 'code', 'head_user_id', 'parent_id', 'is_active', 'deleted_at'],
            'navigation_items' => ['code', 'parent_id', 'label_ar', 'icon', 'route_name', 'route_params', 'access_rule', 'audience_roles', 'is_active', 'sort_order'],
            'dashboard_layouts' => ['scope_type', 'scope_key', 'current_version', 'is_active'],
            'dashboard_layout_versions' => ['layout_id', 'version', 'configuration'],
            'workflow_definitions' => ['name', 'code', 'is_active', 'is_confidential'],
            'workflow_versions' => ['workflow_definition_id', 'status'],
            'workflow_steps' => ['workflow_version_id', 'step_type', 'assignment_type', 'assigned_role_code', 'assignment_config', 'code', 'name', 'sort_order'],
            'workflow_instances' => ['current_step_id', 'workflow_version_id', 'status', 'reference_no'],
            'workflow_participants' => ['workflow_instance_id', 'user_id', 'participation_type', 'is_active'],
            'employees' => ['user_id', 'deleted_at', 'onboarding_status', 'employment_status', 'first_name_ar', 'family_name_ar'],
            'master_data_types' => ['code', 'is_active'],
            'master_data_values' => ['type_id', 'code', 'is_active'],
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    if (str_ends_with($column, '_id') || str_starts_with($column, 'is_') || in_array($column, ['version', 'current_version', 'sort_order'], true)) {
                        $table->integer($column)->nullable();
                    } else {
                        $table->text($column)->nullable();
                    }
                }
            });
        }
    }

    public function test_repeated_menu_rules_do_not_repeat_queries_and_permission_changes_are_immediate(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'system_admin', 'name' => 'Administrator']);
        DB::table('department_user_roles')->insert(['user_id' => $administrator->id, 'role_id' => $role]);
        $item = ['code' => 'link-1', 'label_ar' => 'Accounts', 'route_name' => 'admin.employees.index', 'access_rule' => 'system_admin', 'is_active' => true];
        DB::table('navigation_items')->insert($item);
        $builder = app(NavigationBuilder::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertCount(1, $builder->forUser($administrator));
        $singleCount = count(DB::getQueryLog());
        foreach (range(2, 30) as $number) {
            DB::table('navigation_items')->insert(array_replace($item, ['code' => 'link-'.$number]));
        }
        DB::flushQueryLog();
        self::assertCount(30, $builder->forUser($administrator));
        self::assertLessThanOrEqual($singleCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
        self::assertSame([], $builder->forUser($other));
        DB::table('department_user_roles')->where('user_id', $administrator->id)->delete();
        self::assertSame([], $builder->forUser($administrator));
    }

    public function test_menu_reuses_admin_check_across_distinct_rules_and_does_not_retain_it(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'system_admin', 'name' => 'Administrator']);
        DB::table('department_user_roles')->insert(['user_id' => $administrator->id, 'role_id' => $role]);
        foreach (range(1, 20) as $number) {
            DB::table('navigation_items')->insert(['code' => 'permission-'.$number, 'label_ar' => 'Link', 'route_name' => 'admin.employees.index', 'access_rule' => 'permission:test.'.$number, 'is_active' => true]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        self::assertCount(20, app(NavigationBuilder::class)->forUser($administrator));

        $adminQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select exists(') && in_array('system_admin', $query['bindings'], true));
        self::assertCount(1, $adminQueries);
        DB::disableQueryLog();
        DB::table('department_user_roles')->where('user_id', $administrator->id)->delete();
        self::assertFalse($administrator->isSystemAdmin());
    }

    public function test_admin_snapshot_is_cleared_when_rendering_throws(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'system_admin', 'name' => 'Administrator']);
        DB::table('department_user_roles')->insert(['user_id' => $administrator->id, 'role_id' => $role]);

        try {
            $administrator->withSystemAdminSnapshot(fn () => throw new \RuntimeException('Render failed'));
            self::fail('The rendering exception must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Render failed', $exception->getMessage());
        }

        DB::table('department_user_roles')->where('user_id', $administrator->id)->delete();
        self::assertFalse($administrator->isSystemAdmin());
    }

    public function test_role_batch_preserves_missing_roles_fallbacks_and_invalid_informed_roles(): void
    {
        DB::table('roles')->insert(['code' => 'reviewer', 'name' => 'Reviewer']);
        $resolver = app(AssigneeResolver::class);
        $step = (object) ['step_type' => 'review', 'assignment_type' => 'role', 'assigned_role_code' => 'reviewer', 'name' => 'Review', 'assignment_config' => []];
        self::assertSame([], $resolver->problems($step, ['reviewer']));
        foreach ([['fallback_role_code' => 'missing'], ['inform_role_codes' => ['missing']], ['inform_role_codes' => 'invalid'], ['inform_role_codes' => ['reviewer', 'reviewer']]] as $configuration) {
            $step->assignment_config = $configuration;
            self::assertSame($resolver->problems($step), $resolver->problems($step, ['reviewer']));
        }
        $step->assignment_config = [];
        DB::table('roles')->delete();
        self::assertSame(['المرحلة «Review» تحتاج إلى دور موجود.'], $resolver->problems($step, []));
        self::assertSame($resolver->problems($step), $resolver->problems($step, []));
    }

    public function test_readiness_reuses_holder_checks_without_reusing_them_after_staff_changes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'reviewer', 'name' => 'Reviewer']);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $definition = DB::table('workflow_definitions')->insertGetId(['name' => 'Workflow', 'is_active' => true]);
        $version = DB::table('workflow_versions')->insertGetId(['workflow_definition_id' => $definition, 'status' => 'published']);
        $step = ['workflow_version_id' => $version, 'step_type' => 'review', 'assignment_type' => 'role', 'assigned_role_code' => 'reviewer', 'name' => 'Review', 'assignment_config' => '{}'];
        DB::table('workflow_steps')->insert($step);
        $readiness = app(SystemReadiness::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertTrue($readiness->checks()[0]['ok']);
        $singleCount = count(DB::getQueryLog());
        foreach (range(2, 20) as $number) {
            DB::table('workflow_steps')->insert(array_replace($step, ['name' => 'Review '.$number]));
        }
        DB::flushQueryLog();
        self::assertTrue($readiness->checks()[0]['ok']);
        self::assertLessThanOrEqual($singleCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $user->update(['is_active' => false]);
        $check = $readiness->checks()[0];
        self::assertFalse($check['ok']);
        self::assertStringContainsString('Review', $check['items'][0]);
        self::assertContains('و12 غيرها', $check['items']);
    }
}
