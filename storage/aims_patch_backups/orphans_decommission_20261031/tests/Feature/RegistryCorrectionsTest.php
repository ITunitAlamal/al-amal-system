<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Orphans\Import\RegistryCorrections;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistryCorrectionsTest extends TestCase
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
        $columns = ['batch_id', 'raw_payload', 'fingerprint', 'corrections_payload', 'correction_version', 'parse_flags', 'review_status', 'updated_at',
            'child_name', 'child_name_search', 'child_national_id', 'child_id_status', 'child_key', 'child_dob',
            'guardian_name', 'guardian_name_search', 'guardian_national_id', 'guardian_id_status', 'guardian_key', 'guardian_relation_key', 'guardian_claims_mother',
            'father_national_id', 'father_id_status', 'mother_national_id', 'mother_id_status', 'household_key', 'governorate_key'];
        foreach (['identity', 'household', 'guardianship'] as $kind) {
            foreach (['decision', 'note', 'decided_by', 'decided_at'] as $suffix) {
                $columns[] = $kind.'_'.$suffix;
            }
        }
        foreach ([
            'roles' => ['code'], 'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on'],
            'departments' => ['code', 'is_active', 'head_user_id', 'deleted_at'],
            'orphan_access_grants' => ['department_id', 'user_id', 'is_active', 'capability'],
            'orphan_import_rows' => $columns, 'orphan_import_batches' => ['status'],
            'audit_logs' => ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'created_at', 'updated_at'],
        ] as $name => $fields) {
            Schema::create($name, function (Blueprint $table) use ($fields): void {
                $table->id();
                foreach ($fields as $field) {
                    $table->text($field)->nullable();
                }
            });
        }
    }

    private function staff(array $capabilities = ['view', 'records'], bool $head = false): User
    {
        $user = User::factory()->make(['id' => 10, 'department_id' => 1, 'is_active' => true]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'ORPHANS', 'is_active' => true, 'head_user_id' => $head ? 10 : null]);
        foreach ($capabilities as $capability) {
            DB::table('orphan_access_grants')->insert(['department_id' => 1, 'user_id' => 10, 'is_active' => true, 'capability' => $capability]);
        }
        if ($head) {
            DB::table('roles')->insert(['id' => 1, 'code' => 'department_head']);
            DB::table('department_user_roles')->insert(['role_id' => 1, 'user_id' => 10, 'department_id' => 1]);
        }

        return $user;
    }

    private function row(): object
    {
        DB::table('orphan_import_batches')->insert(['id' => 1, 'status' => 'staged']);
        DB::table('orphan_import_rows')->insert(['id' => 1, 'batch_id' => 1, 'raw_payload' => Crypt::encryptString(json_encode([
            'child_name' => 'الاسم الأصلي', 'guardian_name' => 'الوصي الأصلي', 'guardian_national_id' => '123456789', 'child_dob' => '01/02/2015',
        ], JSON_UNESCAPED_UNICODE)), 'fingerprint' => 'original', 'correction_version' => 0,
            'child_name' => 'الاسم الأصلي', 'guardian_national_id' => '123456789', 'guardian_id_status' => 'format_ok',
            'guardian_key' => '123456789', 'child_dob' => '2015-02-01', 'identity_decision' => 'supported', 'review_status' => 'reviewed']);

        return DB::table('orphan_import_rows')->find(1);
    }

    private function input(array $changes): array
    {
        return ['version' => 0, 'reason' => 'تصحيح بموجب المستند المرفق', 'changes' => $changes];
    }

    public function test_delegated_staff_can_correct_names_without_rewriting_source_or_unchanged_dates(): void
    {
        $user = $this->staff();
        $before = $this->row();

        app(RegistryCorrections::class)->update($user, 1, $this->input(['child_name' => 'أحمد محمد علي سالم', 'guardian_name' => 'سالم محمد علي أحمد']));

        $after = DB::table('orphan_import_rows')->find(1);
        self::assertSame($before->raw_payload, $after->raw_payload);
        self::assertSame('original', $after->fingerprint);
        self::assertSame('2015-02-01', $after->child_dob);
        self::assertSame('احمد محمد علي سالم', $after->child_name_search);
        self::assertSame('سالم محمد علي أحمد', RegistryCorrections::values($after)['guardian_name']);
        self::assertSame('needs_followup', $after->review_status);
        self::assertNull($after->identity_decision);
        $audit = DB::table('audit_logs')->first();
        self::assertSame('10', $audit->actor_id);
        self::assertStringNotContainsString('أحمد محمد علي سالم', $audit->metadata);
    }

    public function test_head_can_correct_guardian_identity_and_regroup_without_grants(): void
    {
        $user = $this->staff([], true);
        $this->row();

        app(RegistryCorrections::class)->update($user, 1, $this->input(['guardian_national_id' => '987654321']));

        self::assertSame('987654321', DB::table('orphan_import_rows')->value('guardian_key'));
    }

    public function test_view_only_staff_cannot_correct_records(): void
    {
        $user = $this->staff(['view']);
        $this->row();
        $this->expectException(HttpException::class);

        app(RegistryCorrections::class)->update($user, 1, $this->input(['child_name' => 'اسم جديد']));
    }

    public function test_record_permission_does_not_allow_sensitive_identity_edits(): void
    {
        $user = $this->staff();
        $this->row();
        $this->expectException(ValidationException::class);

        app(RegistryCorrections::class)->update($user, 1, $this->input(['guardian_national_id' => '987654321']));
    }

    public function test_stale_edit_is_rejected_without_overwriting_a_newer_edit(): void
    {
        $user = $this->staff();
        $this->row();
        $service = app(RegistryCorrections::class);
        $service->update($user, 1, $this->input(['child_name' => 'اسم جديد']));

        try {
            $service->update($user, 1, $this->input(['child_name' => 'تعديل قديم']));
            self::fail('Expected a conflict');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertSame('اسم جديد', DB::table('orphan_import_rows')->value('child_name'));
        self::assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_invalid_date_is_rejected(): void
    {
        $user = $this->staff();
        $this->row();
        $this->expectException(ValidationException::class);

        app(RegistryCorrections::class)->update($user, 1, $this->input(['child_dob' => '2020-02-31']));
    }

    public function test_http_endpoint_rejects_view_only_staff(): void
    {
        $user = $this->staff(['view']);
        $this->row();

        $this->withoutMiddleware()->actingAs($user)->putJson('/orphans/staging/rows/1', $this->input(['child_name' => 'اسم جديد']))->assertForbidden();

        self::assertSame('الاسم الأصلي', DB::table('orphan_import_rows')->value('child_name'));
    }

    public function test_migration_can_roll_back_only_before_corrections_exist(): void
    {
        Schema::table('orphan_import_rows', fn (Blueprint $table) => $table->dropColumn(['corrections_payload', 'correction_version']));
        $migration = require __DIR__.'/../../database/migrations/2026_11_28_000003_add_corrections_to_orphan_import_rows_table.php';
        $migration->up();
        self::assertTrue(Schema::hasColumn('orphan_import_rows', 'corrections_payload'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('orphan_import_rows', 'corrections_payload'));
        $migration->up();
        $user = $this->staff();
        $this->row();
        app(RegistryCorrections::class)->update($user, 1, $this->input(['child_name' => 'اسم جديد']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrections exist');

        $migration->down();
    }
}
