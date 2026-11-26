<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Orphans\OrphanDashboard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrphanDashboardTest extends TestCase
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
        foreach ([
            'roles' => ['code'],
            'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on'],
            'departments' => ['code', 'is_active', 'head_user_id', 'deleted_at'],
            'orphan_access_grants' => ['department_id', 'user_id', 'is_active', 'capability'],
            'orphan_children' => ['household_id', 'status'],
            'orphan_addresses' => ['household_id', 'kind', 'valid_to', 'governorate_value_id', 'governorate'],
            'master_data_values' => ['name_ar'],
            'orphan_import_batches' => ['public_id', 'status', 'file_name', 'completed_at'],
            'orphan_import_rows' => ['batch_id', 'governorate_key', 'review_status'],
            'sponsorships' => ['status'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
    }

    private function administrator(): User
    {
        $user = User::factory()->make(['id' => 10, 'is_active' => true]);
        DB::table('roles')->insert(['id' => 1, 'code' => 'system_admin']);
        DB::table('department_user_roles')->insert(['role_id' => 1, 'user_id' => 10]);

        return $user;
    }

    public function test_geography_counts_only_active_children_at_current_addresses_and_keeps_unknowns(): void
    {
        $user = $this->administrator();
        DB::table('orphan_children')->insert([
            ['household_id' => 1, 'status' => 'active'], ['household_id' => 1, 'status' => 'active'],
            ['household_id' => 2, 'status' => 'active'], ['household_id' => 3, 'status' => 'active'],
            ['household_id' => 1, 'status' => 'pending_activation'],
        ]);
        DB::table('master_data_values')->insert(['id' => 1, 'name_ar' => 'غزة']);
        DB::table('orphan_addresses')->insert([
            ['household_id' => 1, 'kind' => 'current', 'valid_to' => null, 'governorate_value_id' => 1, 'governorate' => 'مصدر قديم'],
            ['household_id' => 1, 'kind' => 'current', 'valid_to' => '2020-01-01', 'governorate_value_id' => null, 'governorate' => 'رفح'],
            ['household_id' => 1, 'kind' => 'original', 'valid_to' => null, 'governorate_value_id' => null, 'governorate' => 'رفح'],
            ['household_id' => 2, 'kind' => 'current', 'valid_to' => null, 'governorate_value_id' => null, 'governorate' => '  '],
        ]);

        $insights = app(OrphanDashboard::class)->insights($user);

        self::assertSame(['غزة' => 2, 'غير محدد' => 2], collect($insights['approved_geography'])->pluck('count', 'label')->all());
        self::assertNull($insights['staged']);
    }

    public function test_staged_charts_use_latest_completed_batch_and_links_preserve_its_scope(): void
    {
        $user = $this->administrator();
        DB::table('orphan_import_batches')->insert([
            ['id' => 1, 'public_id' => 'old', 'status' => 'staged', 'file_name' => 'old.xlsx'],
            ['id' => 2, 'public_id' => 'latest', 'status' => 'staged', 'file_name' => 'latest.xlsx'],
            ['id' => 3, 'public_id' => 'running', 'status' => 'processing', 'file_name' => 'running.xlsx'],
        ]);
        DB::table('orphan_import_rows')->insert([
            ['batch_id' => 1, 'governorate_key' => 'رفح', 'review_status' => 'reviewed'],
            ['batch_id' => 2, 'governorate_key' => 'غزة', 'review_status' => 'pending_review'],
            ['batch_id' => 2, 'governorate_key' => 'غزة', 'review_status' => 'reviewed'],
            ['batch_id' => 2, 'governorate_key' => null, 'review_status' => 'needs_followup'],
            ['batch_id' => 3, 'governorate_key' => 'رفح', 'review_status' => 'reviewed'],
        ]);

        $staged = app(OrphanDashboard::class)->insights($user)['staged'];

        self::assertSame('latest', $staged['batch']['public_id']);
        self::assertSame(['غزة' => 2, 'غير محدد' => 1], collect($staged['geography'])->pluck('count', 'label')->all());
        self::assertSame(3, array_sum(array_column($staged['review'], 'count')));
        parse_str(parse_url($staged['geography'][1]['href'], PHP_URL_QUERY), $query);
        self::assertSame(['batch' => 'latest', 'governorate' => '__missing'], $query);
    }

    public function test_sponsorship_counts_exclude_cancelled_and_replaced_versions(): void
    {
        $user = $this->administrator();
        DB::table('sponsorships')->insert(array_map(fn (string $status): array => ['status' => $status], ['active', 'active', 'paused', 'cancelled', 'superseded']));

        $rows = app(OrphanDashboard::class)->insights($user)['sponsorships'];

        self::assertSame(['نشطة' => 2, 'موقوفة مؤقتًا' => 1], collect($rows)->pluck('count', 'label')->all());
    }

    public function test_view_only_staff_receive_no_sponsorship_chart(): void
    {
        $user = User::factory()->make(['id' => 20, 'is_active' => true, 'department_id' => 1]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'ORPHANS', 'is_active' => true]);
        DB::table('orphan_access_grants')->insert(['department_id' => 1, 'user_id' => 20, 'is_active' => true, 'capability' => 'view']);

        self::assertNull(app(OrphanDashboard::class)->insights($user)['sponsorships']);
    }

    public function test_inactive_user_cannot_read_aggregates(): void
    {
        $user = User::factory()->make(['is_active' => false]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('تحتاج إلى تفويض صريح');

        app(OrphanDashboard::class)->insights($user);
    }
}
