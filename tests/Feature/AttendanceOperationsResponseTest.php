<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AttendanceOperationsResponseTest extends TestCase
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
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
        });
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('host');
            $table->unsignedInteger('port')->default(4370);
            $table->string('protocol')->default('tcp');
            $table->boolean('is_active')->default(true);
            $table->string('sync_mode')->default('disabled');
            $table->string('assigned_agent')->default('local');
            $table->timestamp('import_approved_at')->nullable();
            $table->timestamp('sync_requested_at')->nullable();
            $table->string('sync_request_mode')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->unsignedBigInteger('actor_id');
            $table->text('details');
            $table->timestamps();
        });
        foreach ([
            'roles' => ['code'], 'department_user_roles' => ['user_id', 'role_id', 'department_id'],
            'departments' => ['code', 'head_user_id', 'is_active', 'deleted_at'],
            'attendance_sync_runs' => ['device_id', 'status', 'received', 'inserted', 'error', 'started_at', 'ended_at'],
            'attendance_employee_device_ids' => ['device_id', 'employee_id', 'device_user_id'],
            'attendance_daily_results' => ['work_date', 'employee_id', 'status'],
            'master_data_types' => ['code', 'is_active'], 'master_data_values' => ['type_id', 'code', 'is_active'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
        $this->withoutMiddleware(HandleInertiaRequests::class);
        Inertia::setRootView('inertia');
    }

    public function test_inertia_visit_receives_a_page_even_with_json_accept_header(): void
    {
        $this->actingAs($this->administrator())
            ->getJson('/admin/attendance/operations', ['X-Inertia' => 'true'])
            ->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Admin/Attendance/Operations')
            ->assertJsonPath('props.sync_runs', [])->assertJsonPath('props.grace_minutes', null);
    }

    public function test_explicit_json_client_keeps_the_existing_contract(): void
    {
        $this->actingAs($this->administrator())->getJson('/admin/attendance/operations')
            ->assertOk()->assertHeaderMissing('X-Inertia')
            ->assertExactJson(['sync_runs' => [], 'mappings' => [], 'grace_minutes' => null, 'daily' => [], 'unmatched' => [], 'canApproveImport' => true]);
    }

    public function test_direct_browser_visit_renders_html(): void
    {
        $this->actingAs($this->administrator())->get('/admin/attendance/operations')
            ->assertOk()->assertViewIs('inertia');
    }

    public function test_system_admin_can_trigger_manual_device_sync(): void
    {
        $admin = $this->administrator();
        $this->enablePullSync();
        Artisan::shouldReceive('call')->never();

        $this->actingAs($admin)
            ->from('/admin/attendance')
            ->post('/admin/attendance/devices/1/sync')
            ->assertRedirect('/admin/attendance')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_audit_events', [
            'entity_type' => 'device',
            'entity_id' => 1,
            'action' => 'sync_requested',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_ordinary_user_cannot_start_manual_sync(): void
    {
        $this->enablePullSync();
        Artisan::shouldReceive('call')->never();

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->post('/admin/attendance/devices/1/sync')
            ->assertForbidden();
    }

    public function test_manual_sync_requires_enabled_pull_configuration(): void
    {
        $this->enablePullSync();
        DB::table('attendance_devices')->where('id', 1)->update(['sync_mode' => 'disabled']);
        Artisan::shouldReceive('call')->never();

        $this->actingAs($this->administrator())
            ->from('/admin/attendance')
            ->post('/admin/attendance/devices/1/sync')
            ->assertStatus(409);
    }

    public function test_manual_sync_refuses_import_without_preview_approval(): void
    {
        $this->enablePullSync();
        Artisan::shouldReceive('call')->never();

        $this->actingAs($this->administrator())
            ->from('/admin/attendance')
            ->post('/admin/attendance/devices/1/sync', ['mode' => 'import'])
            ->assertStatus(409);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/attendance/operations')->assertRedirect('/login');
    }

    public function test_ordinary_user_cannot_read_operations(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->getJson('/admin/attendance/operations')->assertForbidden();
    }

    private function enablePullSync(): void
    {
        $this->app['config']->set('attendance.zk_sync_enabled', true);
        config(['attendance.agent_id' => 'local', 'attendance.agent_destinations' => [], 'attendance.zk_allowed_ports' => '4370']);
        $this->app['config']->set('attendance.zk_allowed_hosts', '172.16.10.56');
        $this->app['config']->set('cache.default', 'array');
        DB::table('attendance_devices')->insert([
            'id' => 1,
            'host' => '172.16.10.56',
            'port' => 4370,
            'protocol' => 'tcp',
            'is_active' => true,
            'sync_mode' => 'pull',
        ]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'system_admin']);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => null]);

        return $user;
    }
}
