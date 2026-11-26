<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class OrganizationDepartmentCreationTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $port = (int) (getenv('AIMS_CORRESPONDENCE_TEST_PORT') ?: 55439);
        self::assertContains($port, [55439, 55440]);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'aims_correspondence_test_20261022', 'username' => 'kpitest', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $server = DB::selectOne('select current_database() as name, inet_server_port() as port');
        self::assertSame('aims_correspondence_test_20261022', $server->name);
        self::assertSame(config('database.connections.pgsql.port'), (int) $server->port);
        DB::beginTransaction();
        foreach (['executive_director', 'system_admin', 'department_head'] as $role) {
            DB::table('roles')->insertOrIgnore(['code' => $role, 'name' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! Schema::hasColumn('workflow_definitions', 'is_confidential')) {
            Schema::table('workflow_definitions', fn (Blueprint $table) => $table->boolean('is_confidential')->default(false));
        }
        foreach (['route_params', 'audience_roles'] as $column) {
            if (! Schema::hasColumn('navigation_items', $column)) {
                Schema::table('navigation_items', fn (Blueprint $table) => $table->jsonb($column)->nullable());
            }
        }
        if (! Schema::hasTable('department_structure_changes')) {
            (require database_path('migrations/2026_11_19_000002_organization_units_and_delegations.php'))->up();
        }
        (require database_path('migrations/2026_11_21_000002_allow_independent_department_structure_changes.php'))->up();
        $this->withoutMiddleware(HandleInertiaRequests::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_independent_department_is_created_only_after_executive_approval_without_a_head(): void
    {
        $admin = $this->person('system_admin');
        $executive = $this->person('executive_director');
        $this->actingAs($admin)->post('/admin/organization/changes', $this->proposal())->assertRedirect();
        $change = DB::table('department_structure_changes')->where('proposed_code', 'SECRETARIAT')->first();
        self::assertNotNull($change);
        $this->assertDatabaseMissing('departments', ['code' => 'SECRETARIAT']);
        $instance = DB::table('workflow_instances')->find($change->workflow_instance_id);
        $transition = DB::table('workflow_transitions')->where('workflow_version_id', $instance->workflow_version_id)->where('action_code', 'approve')->first();

        app(WorkflowEngine::class)->act((int) $instance->id, $executive, (int) $transition->id, 'اعتماد القسم المستقل', [], null, (int) $instance->revision);

        $this->assertDatabaseHas('departments', ['code' => 'SECRETARIAT', 'name' => 'السكرتاريا', 'node_type' => 'department', 'parent_id' => null, 'head_user_id' => null, 'is_active' => true]);
        $this->assertDatabaseHas('department_structure_changes', ['id' => $change->id, 'status' => 'applied', 'proposed_by' => $admin->id, 'decided_by' => $executive->id]);
        $departmentId = DB::table('departments')->where('code', 'SECRETARIAT')->value('id');
        $this->assertDatabaseMissing('department_user_roles', ['department_id' => $departmentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.structure.applied', 'subject_id' => (string) $departmentId]);
    }

    public function test_department_head_cannot_create_a_separate_department(): void
    {
        $head = $this->person('department_head');
        DB::table('departments')->where('id', $head->department_id)->update(['head_user_id' => $head->id]);

        $this->actingAs($head)->postJson('/admin/organization/changes', $this->proposal())->assertForbidden();

        $this->assertDatabaseMissing('department_structure_changes', ['proposed_code' => 'SECRETARIAT']);
    }

    public function test_independent_department_rejects_parent_and_requires_a_code(): void
    {
        $admin = $this->person('system_admin');

        $this->actingAs($admin)->postJson('/admin/organization/changes', [...$this->proposal(), 'parent_id' => $admin->department_id, 'proposed_code' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id', 'proposed_code']);

        $this->assertDatabaseMissing('department_structure_changes', ['proposed_name' => 'السكرتاريا']);
    }

    public function test_duplicate_pending_department_proposals_are_refused(): void
    {
        $admin = $this->person('system_admin');
        $this->person('executive_director');
        $this->actingAs($admin)->post('/admin/organization/changes', $this->proposal())->assertRedirect();

        $this->postJson('/admin/organization/changes', [...$this->proposal(), 'proposed_name' => ' السكرتاريا '])->assertUnprocessable()->assertJsonValidationErrors('change');

        self::assertSame(1, DB::table('department_structure_changes')->where('proposed_code', 'SECRETARIAT')->count());
    }

    public function test_existing_department_name_cannot_be_recreated(): void
    {
        $admin = $this->person('system_admin');
        DB::table('departments')->insert(['code' => 'EXISTING_SEC', 'name' => 'السكرتاريا', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->postJson('/admin/organization/changes', $this->proposal())->assertUnprocessable()->assertJsonValidationErrors('change');

        $this->assertDatabaseMissing('department_structure_changes', ['proposed_code' => 'SECRETARIAT']);
    }

    public function test_duplicate_unit_is_archived_once_without_changing_its_approval_history(): void
    {
        DB::table('departments')->insert(['code' => 'SECRETARIAT', 'name' => 'السكرتاريا', 'node_type' => 'department', 'is_active' => true]);
        $parent = $this->person('executive_director')->department_id;
        $unit = DB::table('departments')->insertGetId(['code' => 'EXEC-U1', 'name' => 'السكرتاريا', 'node_type' => 'unit', 'parent_id' => $parent, 'is_active' => true]);
        $history = DB::table('department_structure_changes')->insertGetId(['change_type' => 'create_unit', 'department_id' => $unit, 'status' => 'applied', 'authority' => 'executive_director', 'rationale' => 'قرار سابق محفوظ']);
        $migration = require database_path('migrations/2026_11_22_000002_retire_duplicate_secretariat_unit.php');

        $migration->up();
        $migration->up();

        self::assertSame(1, DB::table('departments')->where('name', 'السكرتاريا')->whereNull('deleted_at')->count());
        $this->assertDatabaseHas('departments', ['code' => 'SECRETARIAT', 'node_type' => 'department', 'parent_id' => null, 'is_active' => true]);
        $this->assertDatabaseHas('departments', ['id' => $unit, 'is_active' => false]);
        self::assertNotNull(DB::table('departments')->where('id', $unit)->value('deleted_at'));
        $this->assertDatabaseHas('department_structure_changes', ['id' => $history, 'status' => 'applied', 'authority' => 'executive_director']);
        self::assertSame(1, DB::table('department_structure_changes')->where('department_id', $unit)->where('change_type', 'retire')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.structure.applied', 'subject_id' => (string) $unit]);
    }

    public function test_duplicate_unit_with_users_is_not_archived_silently(): void
    {
        DB::table('departments')->insert(['code' => 'SECRETARIAT', 'name' => 'السكرتاريا', 'node_type' => 'department', 'is_active' => true]);
        $parent = $this->person('executive_director')->department_id;
        $unit = DB::table('departments')->insertGetId(['code' => 'EXEC-U1', 'name' => 'السكرتاريا', 'node_type' => 'unit', 'parent_id' => $parent, 'is_active' => true]);
        User::factory()->create(['department_id' => $unit]);

        try {
            (require database_path('migrations/2026_11_22_000002_retire_duplicate_secretariat_unit.php'))->up();
            self::fail('Expected linked accounts to block archiving.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('حسابات مستخدمين مرتبطة بالوحدة', $exception->getMessage());
        }

        $this->assertDatabaseHas('departments', ['id' => $unit, 'is_active' => true, 'deleted_at' => null]);
        $this->assertDatabaseMissing('department_structure_changes', ['department_id' => $unit, 'change_type' => 'retire']);
    }

    /** @return array<string, mixed> */
    private function proposal(): array
    {
        return ['change_type' => 'create_department', 'proposed_name' => 'السكرتاريا', 'proposed_code' => 'SECRETARIAT', 'rationale' => 'إنشاء قسم مستقل بلا تبعية لقسم آخر.'];
    }

    private function person(string $role): User
    {
        $department = DB::table('departments')->insertGetId(['code' => 'TEST_'.$role, 'name' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['department_id' => $department, 'is_active' => true, 'must_change_password' => false]);
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId, 'department_id' => $role === 'system_admin' ? null : $department, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
