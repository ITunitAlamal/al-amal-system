<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MonthlyDepartmentBudgetMigrationTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('cache.default', 'array');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('finance_department_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('currency', 3);
            $table->decimal('allocated_amount', 18, 2);
            $table->unique(['department_id', 'fiscal_year', 'currency'], 'fin_department_budget_uniq');
        });
    }

    public function test_annual_allocations_retain_their_original_amount_without_an_inferred_month(): void
    {
        $annualId = $this->budget(['allocated_amount' => '12500.75']);

        $this->monthlyMigration()->up();

        $this->assertDatabaseCount('finance_department_budgets', 1);
        $this->assertDatabaseHas('finance_department_budgets', [
            'id' => $annualId,
            'department_id' => 5,
            'fiscal_year' => 2026,
            'fiscal_month' => null,
            'currency' => 'ILS',
            'allocated_amount' => '12500.75',
        ]);
    }

    public function test_the_same_department_year_and_currency_can_have_distinct_monthly_allocations(): void
    {
        $this->monthlyMigration()->up();

        $this->budget(['fiscal_month' => 11, 'allocated_amount' => '1000.50']);
        $this->budget(['fiscal_month' => 12, 'allocated_amount' => '2000.75']);

        $this->assertDatabaseCount('finance_department_budgets', 2);
        $this->assertDatabaseHas('finance_department_budgets', ['fiscal_month' => 11, 'allocated_amount' => '1000.50']);
        $this->assertDatabaseHas('finance_department_budgets', ['fiscal_month' => 12, 'allocated_amount' => '2000.75']);
    }

    public function test_a_duplicate_allocation_for_the_same_month_is_rejected_without_changing_the_original(): void
    {
        $this->monthlyMigration()->up();
        $monthlyId = $this->budget(['fiscal_month' => 11, 'allocated_amount' => '1000.50']);

        try {
            $this->budget(['fiscal_month' => 11, 'allocated_amount' => '9000.00']);
            self::fail('The migration must enforce one allocation per department, year, month and currency.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('finance_department_budgets', 1);
        $this->assertDatabaseHas('finance_department_budgets', [
            'id' => $monthlyId,
            'fiscal_month' => 11,
            'allocated_amount' => '1000.50',
        ]);
    }

    public function test_rollback_refuses_to_remove_monthly_allocations_and_preserves_all_records(): void
    {
        $annualId = $this->budget(['allocated_amount' => '12500.75']);
        $migration = $this->monthlyMigration();
        $migration->up();
        $monthlyId = $this->budget(['fiscal_month' => 11, 'allocated_amount' => '1000.50']);

        try {
            $migration->down();
            self::fail('Rollback must refuse to discard recorded monthly allocations.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('توجد ميزانيات شهرية', $exception->getMessage());
        }

        self::assertTrue(Schema::hasColumn('finance_department_budgets', 'fiscal_month'));
        $this->assertDatabaseCount('finance_department_budgets', 2);
        $this->assertDatabaseHas('finance_department_budgets', [
            'id' => $annualId,
            'fiscal_month' => null,
            'allocated_amount' => '12500.75',
        ]);
        $this->assertDatabaseHas('finance_department_budgets', [
            'id' => $monthlyId,
            'fiscal_month' => 11,
            'allocated_amount' => '1000.50',
        ]);
    }

    public function test_rollback_with_annual_records_only_preserves_them_and_restores_annual_uniqueness(): void
    {
        $annualId = $this->budget(['allocated_amount' => '12500.75']);
        $migration = $this->monthlyMigration();
        $migration->up();

        $migration->down();

        self::assertFalse(Schema::hasColumn('finance_department_budgets', 'fiscal_month'));
        $this->assertDatabaseHas('finance_department_budgets', [
            'id' => $annualId,
            'department_id' => 5,
            'fiscal_year' => 2026,
            'currency' => 'ILS',
            'allocated_amount' => '12500.75',
        ]);
        try {
            $this->budget(['allocated_amount' => '9000.00']);
            self::fail('Rollback must restore one annual allocation per department, year and currency.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('finance_department_budgets', 1);
    }

    private function monthlyMigration(): Migration
    {
        return require database_path('migrations/2026_11_30_000020_add_fiscal_month_to_finance_department_budgets_table.php');
    }

    private function budget(array $attributes = []): int
    {
        return DB::table('finance_department_budgets')->insertGetId([
            'department_id' => 5,
            'fiscal_year' => 2026,
            'currency' => 'ILS',
            'allocated_amount' => '12000.00',
            ...$attributes,
        ]);
    }
}
