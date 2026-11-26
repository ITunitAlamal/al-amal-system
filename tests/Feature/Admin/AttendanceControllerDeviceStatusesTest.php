<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttendanceControllerDeviceStatusesTest extends TestCase
{
    private User $administrator;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->isolateTestEnvironment();
        $app = require __DIR__.'/../../../bootstrap/app.php';
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
        $this->withoutMiddleware(HandleInertiaRequests::class);
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
        });
        Schema::create('department_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->unsignedBigInteger('department_id')->nullable();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('host', 253);
            $table->unsignedSmallInteger('port')->default(4370);
            $table->string('protocol', 8)->default('auto');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::table('attendance_devices', function (Blueprint $table): void {
            $table->string('sync_mode', 20)->default('disabled');
            $table->string('remote_user', 100)->nullable();
        });
        (require database_path('migrations/2026_10_27_163048_add_health_probe_status_to_attendance_devices.php'))->up();
        Schema::create('attendance_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
        });
        Schema::create('attendance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->foreignId('actor_id')->constrained('users');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $this->administrator = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $roleId = DB::table('roles')->insertGetId(['code' => 'system_admin', 'name' => 'System administrator']);
        DB::table('department_user_roles')->insert([
            'user_id' => $this->administrator->id,
            'role_id' => $roleId,
            'department_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->originalEnvironment as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv($key.'='.$value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function test_returns_only_cached_status_and_keeps_device_agent_and_sync_states_separate(): void
    {
        $now = now();
        $connectedDeviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'probe_result' => 'connected',
            'probe_latency_ms' => 28,
            'last_probe_at' => $now->toDateTimeString(),
            'last_success_at' => $now->toDateTimeString(),
            'agent_last_seen_at' => $now->copy()->subMinutes(2)->toDateTimeString(),
            'sync_mode' => 'pull',
            'last_sync_success_at' => $now->copy()->subMinutes(1)->toDateTimeString(),
            'last_error' => 'do-not-return-sensitive-raw-error',
        ]);
        DB::table('attendance_sync_runs')->insert([
            'device_id' => $connectedDeviceId,
            'status' => 'failed',
            'started_at' => $now->toDateTimeString(),
            'ended_at' => $now->toDateTimeString(),
        ]);
        $staleDeviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'probe_result' => 'connected',
            'last_probe_at' => $now->copy()->subMinutes(4)->toDateTimeString(),
            'last_success_at' => $now->copy()->subMinutes(4)->toDateTimeString(),
            'sync_mode' => 'disabled',
        ]);
        $stuckSyncDeviceId = $this->createDevice(['sync_mode' => 'pull']);
        DB::table('attendance_sync_runs')->insert([
            'device_id' => $stuckSyncDeviceId,
            'status' => 'running',
            'started_at' => $now->copy()->subMinutes(20)->toDateTimeString(),
        ]);
        $originalUpdatedAt = DB::table('attendance_devices')->where('id', $connectedDeviceId)->value('updated_at');

        $response = $this->actingAs($this->administrator)->getJson('/admin/attendance/devices/status');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('devices.0.device_state', 'connected')
            ->assertJsonPath('devices.0.agent_state', 'disconnected')
            ->assertJsonPath('devices.0.sync_state', 'failed')
            ->assertJsonPath('devices.0.probe_latency_ms', 28)
            ->assertJsonPath('devices.1.device_state', 'stale')
            ->assertJsonPath('devices.1.agent_state', 'unknown')
            ->assertJsonPath('devices.1.sync_state', 'disabled')
            ->assertJsonPath('devices.2.sync_state', 'stale')
            ->assertDontSee('do-not-return-sensitive-raw-error');

        self::assertSame($originalUpdatedAt, DB::table('attendance_devices')->where('id', $connectedDeviceId)->value('updated_at'));
        self::assertSame(30, $response->json('devices.0.probe_interval_seconds'));
        self::assertSame($staleDeviceId, $response->json('devices.1.id'));
        self::assertSame($stuckSyncDeviceId, $response->json('devices.2.id'));
        self::assertEqualsCanonicalizing([
            'firmware', 'platform', 'clock_drift_seconds', 'last_failure_at', 'last_failure_code', 'latest_run',
            'id',
            'device_state',
            'agent_state',
            'sync_state',
            'last_probe_at',
            'last_success_at',
            'probe_latency_ms',
            'probe_error_code',
            'agent_last_seen_at',
            'last_sync_success_at',
            'probe_interval_seconds',
            'probe_freshness_seconds',
        ], array_keys($response->json('devices.0')));
    }

    public function test_rejects_an_unauthenticated_status_request(): void
    {
        $this->get('/admin/attendance/devices/status')->assertRedirect(route('login'));
    }

    public function test_forbids_an_authenticated_user_without_attendance_access(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->getJson('/admin/attendance/devices/status')->assertForbidden();
        $this->actingAs($user)->post('/admin/attendance/devices/1/probe')->assertForbidden();
    }

    public function test_system_admin_can_enqueue_one_rate_limited_probe_for_its_assigned_device(): void
    {
        $deviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
        ]);

        $this->actingAs($this->administrator)
            ->post('/admin/attendance/devices/'.$deviceId.'/probe')
            ->assertRedirect();

        self::assertNotNull(DB::table('attendance_devices')->where('id', $deviceId)->value('probe_requested_at'));
        self::assertSame(1, DB::table('attendance_audit_events')->where('entity_id', $deviceId)->where('action', 'probe_requested')->count());

        $this->actingAs($this->administrator)
            ->post('/admin/attendance/devices/'.$deviceId.'/probe')
            ->assertTooManyRequests();

        self::assertSame(1, DB::table('attendance_audit_events')->where('entity_id', $deviceId)->where('action', 'probe_requested')->count());
    }

    public function test_probe_request_rejects_a_device_assigned_to_another_agent(): void
    {
        $deviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'assigned_agent' => 'different-agent',
        ]);

        $this->actingAs($this->administrator)
            ->post('/admin/attendance/devices/'.$deviceId.'/probe')
            ->assertConflict();

        self::assertNull(DB::table('attendance_devices')->where('id', $deviceId)->value('probe_requested_at'));
        self::assertSame(0, DB::table('attendance_audit_events')->count());
    }

    public function test_endpoint_change_revokes_approval_and_invalidates_old_health_and_sync_status(): void
    {
        config(['attendance.zk_allowed_hosts' => '192.0.2.11', 'attendance.zk_allowed_ports' => '4380', 'attendance.agent_destinations' => []]);
        $deviceId = $this->createDevice([
            'is_active' => true,
            'sync_mode' => 'pull',
            'probe_enabled' => true,
            'probe_result' => 'connected',
            'last_probe_at' => now()->toDateTimeString(),
            'last_success_at' => now()->toDateTimeString(),
            'last_sync_success_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($this->administrator)->putJson('/admin/attendance/devices/'.$deviceId, [
            'name' => 'Updated test device',
            'location' => 'Test site',
            'host' => '192.0.2.11',
            'port' => 4380,
            'protocol' => 'udp',
            'probe_enabled' => true,
            'probe_interval_seconds' => 60,
            'assigned_agent' => 'local',
        ])->assertRedirect();

        $device = DB::table('attendance_devices')->where('id', $deviceId)->first();
        self::assertFalse((bool) $device->is_active);
        self::assertSame('disabled', $device->sync_mode);
        self::assertNull($device->last_probe_at);
        self::assertNull($device->last_success_at);
        self::assertNull($device->last_sync_success_at);
        self::assertNull($device->probe_requested_at);
        self::assertSame(1, DB::table('attendance_audit_events')->where('entity_id', $deviceId)->count());
    }

    public function test_system_admin_cannot_approve_a_health_probe_to_a_host_outside_the_allowlist(): void
    {
        $deviceId = $this->createDevice(['probe_enabled' => true]);
        config(['attendance.zk_allowed_hosts' => '192.0.2.11']);

        $this->actingAs($this->administrator)
            ->postJson('/admin/attendance/devices/'.$deviceId.'/connection', [
                'enabled' => true,
                'sync_mode' => 'disabled',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        self::assertFalse((bool) DB::table('attendance_devices')->where('id', $deviceId)->value('is_active'));
        self::assertSame(0, DB::table('attendance_audit_events')->count());
    }

    public function test_system_admin_can_approve_a_health_probe_without_enabling_attendance_sync(): void
    {
        $deviceId = $this->createDevice(['probe_enabled' => true]);
        config(['attendance.zk_allowed_hosts' => '192.0.2.10']);

        $this->actingAs($this->administrator)
            ->postJson('/admin/attendance/devices/'.$deviceId.'/connection', [
                'enabled' => true,
                'sync_mode' => 'disabled',
            ])
            ->assertRedirect();

        $device = DB::table('attendance_devices')->where('id', $deviceId)->first();
        self::assertTrue((bool) $device->is_active);
        self::assertSame('disabled', $device->sync_mode);
        self::assertSame(1, DB::table('attendance_audit_events')->where('entity_id', $deviceId)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDevice(array $overrides = []): int
    {
        $now = now()->toDateTimeString();

        return DB::table('attendance_devices')->insertGetId(array_replace([
            'name' => 'Test attendance device',
            'location' => 'Test site',
            'host' => '192.0.2.10',
            'port' => 4370,
            'protocol' => 'tcp',
            'is_active' => false,
            'sync_mode' => 'disabled',
            'probe_enabled' => false,
            'probe_interval_seconds' => 30,
            'assigned_agent' => 'local',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function isolateTestEnvironment(): void
    {
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ] as $key => $value) {
            $this->originalEnvironment[$key] = getenv($key);
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnvironment['DB_URL'] = getenv('DB_URL');
        putenv('DB_URL=');
        $_ENV['DB_URL'] = '';
        $_SERVER['DB_URL'] = '';
    }
}
