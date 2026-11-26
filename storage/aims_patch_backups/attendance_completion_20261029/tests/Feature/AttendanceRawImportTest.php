<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Attendance\AttendanceDestinationPolicy;
use App\Services\Attendance\AttendanceDeviceReader;
use App\Services\Attendance\AttendanceRawImporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AttendanceRawImportTest extends TestCase
{
    private object $device;

    private int $run;

    private int $employee;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55440, 'database' => 'aims_correspondence_test_20261022', 'username' => 'kpitest', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $server = DB::selectOne('select current_database() as name, inet_server_port() as port');
        self::assertSame('aims_correspondence_test_20261022', $server->name);
        self::assertSame(55440, (int) $server->port);
        DB::beginTransaction();
        if (! Schema::hasColumn('attendance_devices', 'probe_enabled')) {
            (require database_path('migrations/2026_10_27_163048_add_health_probe_status_to_attendance_devices.php'))->up();
        }
        (require database_path('migrations/2026_10_28_091603_add_safe_raw_attendance_import.php'))->up();
        config(['attendance.agent_id' => 'local', 'attendance.zk_allowed_hosts' => '192.0.2.10', 'attendance.zk_allowed_ports' => '4370', 'attendance.agent_destinations' => [], 'attendance.zk_sync_enabled' => true]);
        Storage::fake('local');
        $this->withoutMiddleware(HandleInertiaRequests::class);
        if (! Schema::hasColumn('employees', 'attendance_number')) {
            Schema::table('employees', fn ($table) => $table->string('attendance_number')->nullable());
        }
        $this->employee = DB::table('employees')->insertGetId(['employee_number' => '000123', 'first_name_ar' => 'Test', 'family_name_ar' => 'Fixture', 'employment_status' => 'active']);
        $id = DB::table('attendance_devices')->insertGetId(['name' => 'Fixture', 'host' => '192.0.2.10', 'port' => 4370, 'protocol' => 'tcp', 'is_active' => true, 'sync_mode' => 'pull', 'timezone' => 'Asia/Gaza', 'assigned_agent' => 'local', 'import_approved_at' => now()]);
        $this->device = DB::table('attendance_devices')->find($id);
        $this->run = DB::table('attendance_sync_runs')->insertGetId(['device_id' => $id, 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function event(string $uid = '000123', string $time = '2026-09-01 08:00:00'): array
    {
        return ['uid' => $uid, 'timestamp' => $time, 'status' => 1, 'punch' => 5, 'type' => null, 'device_event_id' => 7];
    }

    public function test_full_reimport_preserves_identical_occurrences_and_late_events_without_hr_calculations(): void
    {
        $importer = app(AttendanceRawImporter::class);
        $events = [$this->event(), $this->event(), $this->event('missing')];
        $first = $importer->ingest($this->device, $events, $this->run, false);
        self::assertSame(['received' => 3, 'inserted' => 3, 'duplicates' => 0, 'unmatched' => 1, 'failed' => 0], $first);
        $events[] = $this->event('000123', '2026-08-01 08:00:00');
        $second = $importer->ingest($this->device, array_reverse($events), $this->run, false);
        self::assertSame(1, $second['inserted']);
        self::assertSame(3, $second['duplicates']);
        self::assertSame(0, $importer->ingest($this->device, $events, $this->run, false)['inserted']);
        $rows = DB::table('attendance_punches')->where('device_id', $this->device->id)->get();
        self::assertCount(4, $rows);
        self::assertSame(['unknown'], $rows->pluck('direction')->unique()->values()->all());
        self::assertSame(5, json_decode($rows->first()->raw_payload, true)['punch']);
        self::assertSame(1, json_decode($rows->first()->raw_payload, true)['status']);
        self::assertSame('2026-09-01 05:00:00', CarbonImmutable::parse($rows->first()->occurred_at)->utc()->toDateTimeString());
        self::assertSame(0, DB::table('attendance_daily_results')->where('employee_id', $this->employee)->count());
    }

    public function test_preview_writes_no_punches_and_unapproved_import_fails(): void
    {
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['import_approved_at' => null]);
        self::assertSame(1, app(AttendanceRawImporter::class)->ingest($this->device, [$this->event()], $this->run, true)['inserted']);
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
        $this->expectExceptionMessage('import_not_authorized');
        app(AttendanceRawImporter::class)->ingest($this->device, [$this->event()], $this->run, false);
    }

    public function test_employee_number_keeps_leading_zeros_and_conflicting_mapping_is_unmatched(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['attendance_number' => 'alternate']);
        $other = DB::table('employees')->insertGetId(['employee_number' => '123', 'first_name_ar' => 'Other', 'family_name_ar' => 'Fixture']);
        $events = [$this->event(), $this->event('123'), $this->event('alternate'), $this->event((string) $this->employee)];
        app(AttendanceRawImporter::class)->ingest($this->device, $events, $this->run, false);
        $rows = DB::table('attendance_punches')->where('device_id', $this->device->id)->get()->keyBy('raw_user_id');
        self::assertSame($this->employee, (int) $rows['000123']->employee_id);
        self::assertSame($other, (int) $rows['123']->employee_id);
        self::assertNull($rows['alternate']->employee_id);
        self::assertNull($rows[(string) $this->employee]->employee_id);
        DB::table('attendance_employee_device_ids')->insert(['device_id' => $this->device->id, 'employee_id' => $other, 'device_user_id' => '000123']);
        self::assertSame(1, app(AttendanceRawImporter::class)->ingest($this->device, [$this->event('000123', '2026-09-02 08:00:00')], $this->run, false)['unmatched']);
    }

    public function test_invalid_event_rolls_back_whole_batch(): void
    {
        try {
            app(AttendanceRawImporter::class)->ingest($this->device, [$this->event(), $this->event('000123', 'not a timestamp')], $this->run, false);
            self::fail('Invalid timestamp accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('invalid_snapshot_event', $exception->getMessage());
        }
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
    }

    public function test_postgresql_rejects_mutation_and_deletion_of_raw_evidence(): void
    {
        app(AttendanceRawImporter::class)->ingest($this->device, [$this->event()], $this->run, false);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation): void {
                    $q = DB::table('attendance_punches')->where('device_id', $this->device->id);
                    $operation === 'delete' ? $q->delete() : $q->update(['direction' => 'in']);
                });
                self::fail('Raw evidence mutation accepted');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Raw attendance evidence is immutable', $exception->getMessage());
            }
        }
    }

    public function test_scoped_destinations_reject_wrong_port_agent_and_loopback(): void
    {
        $policy = app(AttendanceDestinationPolicy::class);
        self::assertTrue($policy->allows($this->device));
        foreach ([['port' => 80], ['assigned_agent' => 'other'], ['host' => '127.0.0.1'], ['host' => '169.254.169.254'], ['host' => 'device.example']] as $change) {
            self::assertFalse($policy->allows((object) array_replace((array) $this->device, $change)));
        }
    }

    public function test_command_previews_then_retries_encrypted_snapshot_without_rereading_device(): void
    {
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['import_approved_at' => null]);
        $reader = \Mockery::mock(AttendanceDeviceReader::class);
        $reader->shouldReceive('read')->once()->andReturn(['events' => [$this->event()]]);
        $this->app->instance(AttendanceDeviceReader::class, $reader);
        $this->artisan('attendance:sync', ['--device' => $this->device->id])->assertSuccessful();
        $files = Storage::disk('local')->files('attendance-retry');
        self::assertCount(1, $files);
        self::assertStringNotContainsString('000123', Storage::disk('local')->get($files[0]));
        self::assertSame('000123', json_decode(Crypt::decryptString(Storage::disk('local')->get($files[0])), true)['events'][0]['uid']);
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['import_approved_at' => now()]);
        $this->artisan('attendance:sync', ['--device' => $this->device->id])->assertSuccessful();
        self::assertSame(1, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
        Storage::disk('local')->assertMissing($files[0]);
    }

    public function test_single_flight_does_not_connect_when_device_is_busy(): void
    {
        $reader = \Mockery::mock(AttendanceDeviceReader::class);
        $reader->shouldNotReceive('read');
        $this->app->instance(AttendanceDeviceReader::class, $reader);
        $lock = Cache::lock('attendance-device-io:'.$this->device->id, 180);
        self::assertTrue($lock->get());
        try {
            $this->artisan('attendance:sync', ['--device' => $this->device->id])->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $role = DB::table('roles')->where('code', 'system_admin')->value('id');
        if (! $role) {
            $role = DB::table('roles')->insertGetId(['code' => 'system_admin', 'name' => 'Fixture']);
        }
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);

        return $user;
    }

    public function test_approval_requires_authorized_operator_and_current_preview_and_records_audit(): void
    {
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['import_approved_at' => null]);
        $url = '/admin/attendance/devices/'.$this->device->id.'/approve-import';
        $ordinary = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actingAs($ordinary)->postJson($url, ['run_id' => $this->run, 'confirmed' => true])->assertForbidden();
        $admin = $this->administrator();
        $this->actingAs($admin)->postJson($url, ['run_id' => $this->run, 'confirmed' => true])->assertConflict();
        DB::table('attendance_sync_runs')->where('id', $this->run)->update(['dry_run' => true, 'status' => 'success', 'ended_at' => now(), 'configuration_digest' => app(AttendanceRawImporter::class)->configurationDigest($this->device)]);
        $this->postJson($url, ['run_id' => $this->run, 'confirmed' => false])->assertUnprocessable();
        $this->postJson($url, ['run_id' => $this->run, 'confirmed' => true])->assertRedirect();
        $this->assertDatabaseHas('attendance_devices', ['id' => $this->device->id, 'import_approved_by' => $admin->id]);
        $this->assertDatabaseHas('attendance_audit_events', ['entity_id' => $this->device->id, 'action' => 'raw_import_approved', 'actor_id' => $admin->id]);
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
    }

    public function test_configuration_encrypts_credentials_redacts_audit_and_revokes_approval(): void
    {
        $admin = $this->administrator();
        $payload = ['name' => 'Fixture', 'host' => '192.0.2.10', 'port' => 4370, 'protocol' => 'tcp', 'assigned_agent' => 'local', 'driver' => 'pyzk', 'timezone' => 'Asia/Gaza', 'connection_timeout' => 6, 'sync_interval_seconds' => 300, 'communication_password' => '987654'];
        $this->actingAs($admin)->putJson('/admin/attendance/devices/'.$this->device->id, $payload)->assertRedirect();
        $row = DB::table('attendance_devices')->find($this->device->id);
        self::assertSame('987654', Crypt::decryptString($row->communication_password));
        self::assertNull($row->import_approved_at);
        self::assertFalse($row->is_active);
        self::assertStringNotContainsString('987654', DB::table('attendance_audit_events')->where('entity_id', $this->device->id)->value('details'));
        $response = $this->getJson('/admin/attendance/devices/status')->assertOk();
        self::assertStringNotContainsString('communication_password', $response->getContent());
        self::assertStringNotContainsString('987654', $response->getContent());
        $this->putJson('/admin/attendance/devices/'.$this->device->id, array_replace($payload, ['host' => '127.0.0.1']))->assertUnprocessable();
    }

    public function test_failed_snapshot_remains_encrypted_for_retry_and_is_not_partially_imported(): void
    {
        $reader = \Mockery::mock(AttendanceDeviceReader::class);
        $reader->shouldReceive('read')->once()->andReturn(['events' => [$this->event(), $this->event('000123', 'invalid')]]);
        $this->app->instance(AttendanceDeviceReader::class, $reader);
        $this->artisan('attendance:sync', ['--device' => $this->device->id])->assertFailed();
        self::assertCount(1, Storage::disk('local')->files('attendance-retry'));
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
        $this->assertDatabaseHas('attendance_sync_runs', ['device_id' => $this->device->id, 'status' => 'failed', 'error' => 'invalid_snapshot_event']);
    }

    public function test_ambiguous_employee_numbers_remain_unmatched(): void
    {
        Schema::table('employees', fn ($table) => $table->dropUnique('employees_employee_number_unique'));
        DB::table('employees')->insert(['employee_number' => '000123', 'first_name_ar' => 'Duplicate', 'family_name_ar' => 'Fixture']);
        self::assertSame(1, app(AttendanceRawImporter::class)->ingest($this->device, [$this->event()], $this->run, false)['unmatched']);
        self::assertNull(DB::table('attendance_punches')->where('device_id', $this->device->id)->value('employee_id'));
    }

    public function test_review_requires_exact_canonical_match_and_audit_reason(): void
    {
        app(AttendanceRawImporter::class)->ingest($this->device, [$this->event('review-number')], $this->run, false);
        $punch = DB::table('attendance_punches')->where('device_id', $this->device->id)->first();
        $url = '/admin/attendance/raw-punches/'.$punch->id.'/resolve';
        $this->actingAs($this->administrator())->postJson($url, ['reason' => 'Verified HR correction'])->assertConflict();
        DB::table('employees')->where('id', $this->employee)->update(['employee_number' => 'review-number']);
        $this->postJson($url, ['reason' => 'Verified HR correction'])->assertRedirect();
        $row = DB::table('attendance_punches')->find($punch->id);
        self::assertSame($punch->raw_payload, $row->raw_payload);
        self::assertSame('reviewed', $row->match_status);
        self::assertSame($this->employee, (int) $row->employee_id);
        $this->assertDatabaseHas('attendance_audit_events', ['entity_id' => $punch->id, 'action' => 'canonical_match_reviewed']);
    }

    public function test_scheduler_obeys_agent_assignment_interval_and_unapproved_state(): void
    {
        $reader = \Mockery::mock(AttendanceDeviceReader::class);
        $reader->shouldNotReceive('read');
        $this->app->instance(AttendanceDeviceReader::class, $reader);
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['last_synced_at' => now()]);
        $this->artisan('attendance:sync')->assertSuccessful();
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['last_synced_at' => null, 'import_approved_at' => null]);
        $this->artisan('attendance:sync')->assertSuccessful();
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['assigned_agent' => 'elsewhere', 'sync_requested_at' => now()]);
        $this->artisan('attendance:sync')->assertSuccessful();
    }

    public function test_clock_transition_ambiguity_and_nonexistent_local_time_are_rejected_without_loss(): void
    {
        DB::table('attendance_devices')->where('id', $this->device->id)->update(['timezone' => 'America/New_York']);
        $device = DB::table('attendance_devices')->find($this->device->id);
        foreach (['2026-11-01 01:30:00', '2026-03-08 02:30:00'] as $time) {
            try {
                app(AttendanceRawImporter::class)->ingest($device, [$this->event('000123', $time)], $this->run, false);
                self::fail('Ambiguous or invalid local time accepted');
            } catch (\RuntimeException $exception) {
                self::assertContains($exception->getMessage(), ['invalid_local_timestamp', 'ambiguous_local_timestamp']);
            }
        }
        self::assertSame(0, DB::table('attendance_punches')->where('device_id', $this->device->id)->count());
    }
}
