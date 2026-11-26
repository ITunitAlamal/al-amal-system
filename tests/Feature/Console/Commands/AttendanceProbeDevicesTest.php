<?php

namespace Tests\Feature\Console\Commands;

use App\Services\Attendance\AttendanceDeviceProber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

final class AttendanceProbeDevicesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->isolateTestEnvironment();
        $app = require __DIR__.'/../../../../bootstrap/app.php';
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
        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->string('direction');
        });
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

    public function test_probes_only_the_assigned_active_device_and_never_changes_attendance_records(): void
    {
        config([
            'attendance.health_probe_enabled' => true,
            'attendance.agent_id' => 'lan-agent',
            'attendance.health_probe_timeout_seconds' => 10,
        ]);
        $deviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'assigned_agent' => 'lan-agent',
            'last_probe_at' => now()->toDateTimeString(),
            'probe_requested_at' => now()->toDateTimeString(),
        ]);
        $disabledDeviceId = $this->createDevice([
            'is_active' => false,
            'probe_enabled' => true,
            'assigned_agent' => 'lan-agent',
        ]);
        $periodicDeviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'assigned_agent' => 'lan-agent',
        ]);
        $otherAgentDeviceId = $this->createDevice([
            'is_active' => true,
            'probe_enabled' => true,
            'assigned_agent' => 'another-agent',
        ]);
        DB::table('attendance_punches')->insert(['direction' => 'in']);

        $this->mock(AttendanceDeviceProber::class, function (MockInterface $mock) use ($deviceId, $periodicDeviceId): void {
            $mock->shouldReceive('probe')
                ->twice()
                ->withArgs(fn (object $device): bool => in_array((int) $device->id, [$deviceId, $periodicDeviceId], true))
                ->andReturn(['result' => 'connected', 'latency_ms' => 17, 'error_code' => null]);
        });

        self::assertSame(0, Artisan::call('attendance:probe-devices'));

        $device = DB::table('attendance_devices')->where('id', $deviceId)->first();
        self::assertSame('connected', $device->probe_result);
        self::assertSame(17, (int) $device->probe_latency_ms);
        self::assertNotNull($device->last_probe_at);
        self::assertNotNull($device->last_success_at);
        self::assertNotNull($device->agent_last_seen_at);
        self::assertNull($device->probe_started_at);
        self::assertNull($device->probe_lock_token);
        self::assertNull($device->probe_requested_at);
        self::assertSame('connected', DB::table('attendance_devices')->where('id', $periodicDeviceId)->value('probe_result'));
        self::assertNotNull(DB::table('attendance_devices')->where('id', $disabledDeviceId)->value('agent_last_seen_at'));
        self::assertNull(DB::table('attendance_devices')->where('id', $disabledDeviceId)->value('last_probe_at'));
        self::assertNull(DB::table('attendance_devices')->where('id', $otherAgentDeviceId)->value('agent_last_seen_at'));
        self::assertSame(1, DB::table('attendance_punches')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDevice(array $overrides = []): int
    {
        $now = now()->toDateTimeString();

        return DB::table('attendance_devices')->insertGetId(array_replace([
            'name' => 'Test attendance device',
            'host' => '192.0.2.10',
            'port' => 4370,
            'protocol' => 'tcp',
            'is_active' => false,
            'sync_mode' => 'disabled',
            'probe_enabled' => false,
            'probe_interval_seconds' => 30,
            'assigned_agent' => 'lan-agent',
            'last_probe_at' => null,
            'probe_requested_at' => null,
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
