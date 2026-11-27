<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Finance\DepartmentBudgets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\DatabasePresenceVerifier;
use PHPUnit\Framework\Attributes\DataProvider;

class DepartmentBudgetsTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-27 10:00:00');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('node_type')->default('department');
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('department_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('finance_module_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('accounting_mode');
            $table->string('external_system_name');
        });
        Schema::create('finance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->text('detail')->nullable();
            $table->timestamps();
        });
        Schema::create('finance_department_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('fiscal_month')->nullable();
            $table->string('currency', 3);
            $table->decimal('allocated_amount', 18, 2);
            $table->date('as_of');
            $table->string('reference', 120);
            $table->text('notes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['department_id', 'fiscal_year', 'fiscal_month', 'currency']);
        });
        foreach ([
            'finance_budget_lines' => ['department_id'],
            'finance_transactions' => ['kind', 'state', 'reversal_of_id', 'budget_line_id', 'currency', 'amount', 'effective_on'],
            'finance_purchase_requests' => ['department_id'],
            'finance_purchase_orders' => ['purchase_request_id'],
            'finance_purchase_invoices' => ['order_id', 'currency', 'amount', 'state', 'external_voucher_no', 'external_voucher_on', 'external_recorded_at'],
            'finance_invoice_payments' => ['invoice_id', 'transaction_id', 'amount', 'reversed_by_transaction_id'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        $this->app['validator']->setPresenceVerifier(new DatabasePresenceVerifier($this->app['db']));
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    public function test_budget_routes_require_authentication(): void
    {
        $this->get('/finance/department-budgets')->assertRedirect('/login');
        $this->getJson('/departments/budgets')->assertUnauthorized();
        $this->postJson('/finance/department-budgets', [])->assertUnauthorized();
        $this->patchJson('/finance/department-budgets/1', [])->assertUnauthorized();
        $this->assertDatabaseCount('finance_department_budgets', 0);
    }

    public static function headConditions(): array
    {
        return [
            'appointed FIN head' => [[], [], [], true],
            'former ACC code' => [['code' => 'ACC'], [], [], true],
            'inactive user' => [[], ['is_active' => false], [], false],
            'inactive department' => [['is_active' => false], [], [], false],
            'deleted department' => [['deleted_at' => '2026-11-26 10:00:00'], [], [], false],
            'appointment without head role' => [[], [], ['omit' => true], false],
            'role held in another department' => [[], [], ['department_id' => 999], false],
            'future head role' => [[], [], ['starts_on' => '2026-11-28'], false],
            'expired head role' => [[], [], ['ends_on' => '2026-11-26'], false],
            'head role starts and ends today' => [[], [], ['starts_on' => '2026-11-27', 'ends_on' => '2026-11-27'], true],
        ];
    }

    #[DataProvider('headConditions')]
    public function test_budget_management_requires_the_current_accounts_appointment_and_its_scoped_role(array $departmentAttributes, array $userAttributes, array $roleAttributes, bool $allowed): void
    {
        $department = $this->department('FIN', $departmentAttributes);
        $user = $this->person(['department_id' => null, ...$userAttributes]);
        DB::table('departments')->where('id', $department)->update(['head_user_id' => $user->id]);
        if (! ($roleAttributes['omit'] ?? false)) {
            $this->role($user, 'department_head', (int) ($roleAttributes['department_id'] ?? $department), array_intersect_key($roleAttributes, array_flip(['starts_on', 'ends_on'])));
        }

        self::assertSame($allowed, app(DepartmentBudgets::class)->canManage($user));
    }

    public function test_head_role_without_an_appointment_and_technical_admin_cannot_manage_budgets(): void
    {
        $accounts = $this->department('FIN');
        $user = $this->person(['department_id' => $accounts]);
        $this->role($user, 'department_head', $accounts);
        $this->role($user, 'system_admin', null);

        $this->actingAs($user)->getJson('/finance/department-budgets')->assertForbidden();
        $this->postJson('/finance/department-budgets', $this->payload($accounts))->assertForbidden();
        $this->assertDatabaseCount('finance_department_budgets', 0);
        self::assertSame([], app(DepartmentBudgets::class)->summary($user, '2026-11')['items']);
    }

    public function test_department_head_cannot_read_or_update_another_departments_budget(): void
    {
        $own = $this->department('HR');
        $other = $this->department('ORPHANS');
        $user = $this->appoint($own);
        $id = $this->budget($other, $user);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11&department_id='.$other)->assertForbidden();
        $this->patchJson('/finance/department-budgets/'.$id, $this->changes())->assertForbidden();
        $this->assertDatabaseHas('finance_department_budgets', ['id' => $id, 'allocated_amount' => '100.30', 'revision' => 1]);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public function test_two_current_head_appointments_are_visible_without_other_departments_or_child_units(): void
    {
        $first = $this->department('HR');
        $second = $this->department('PR');
        $other = $this->department('ORPHANS');
        $child = $this->department('HR_UNIT', ['node_type' => 'unit', 'parent_id' => $first]);
        $user = $this->appoint($first);
        $this->appoint($second, $user);
        $this->budget($first, $user);
        $this->budget($second, $user);
        $this->budget($other, $user, ['reference' => 'SECRET-OTHER-BUDGET']);
        $this->budget($child, $user, ['reference' => 'SECRET-UNIT-BUDGET']);

        $response = $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk();

        $response->assertJsonCount(2, 'items')->assertDontSee('SECRET-OTHER-BUDGET')->assertDontSee('SECRET-UNIT-BUDGET');
        self::assertEqualsCanonicalizing([$first, $second], array_column($response->json('items'), 'departmentId'));
        $this->getJson('/departments/budgets?period=2026-11&department_id='.$first)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.departmentId', $first);
    }

    public function test_an_unset_budget_is_distinct_from_an_explicit_zero_budget(): void
    {
        $unset = $this->department('HR');
        $zero = $this->department('PR');
        $user = $this->appoint($unset);
        $this->appoint($zero, $user);
        $this->budget($zero, $user, ['allocated_amount' => '0.00']);

        $summary = app(DepartmentBudgets::class)->summary($user, '2026-11');

        $items = collect($summary['items'])->keyBy('departmentId');
        self::assertFalse($items[$unset]['configured']);
        self::assertNull($items[$unset]['allocated']);
        self::assertNull($items[$unset]['spent']);
        self::assertNull($items[$unset]['remaining']);
        self::assertNull($items[$unset]['currency']);
        self::assertTrue($items[$zero]['configured']);
        self::assertSame('0.00', $items[$zero]['remaining']);
        self::assertNull($summary['manageUrl']);
    }

    public function test_summary_keeps_currencies_and_months_separate_and_preserves_cents_and_overspending(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user, ['allocated_amount' => '100.30']);
        $this->budget($department, $user, ['currency' => 'USD', 'allocated_amount' => '0.30']);
        $this->budget($department, $user, ['period' => '2026-10', 'allocated_amount' => '500.00']);
        $this->payment($department, '120.31');
        $this->payment($department, '0.10', ['currency' => 'USD']);
        $this->payment($department, '1.00', ['effective_on' => '2026-10-31']);
        $this->payment($this->department('OTHER'), '9999.00');
        $this->payment($department, '9999.00', ['state' => 'draft']);
        $this->payment($department, '9999.00', ['kind' => 'receipt']);

        $response = $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()->assertJsonPath('period', '2026-11')->assertJsonCount(2, 'items');

        $items = collect($response->json('items'))->keyBy('currency');
        self::assertSame('-20.01', $items['ILS']['remaining']);
        self::assertSame('100.30', $items['ILS']['allocated']);
        self::assertSame('120.31', $items['ILS']['spent']);
        self::assertSame('0.20', $items['USD']['remaining']);
        $this->getJson('/departments/budgets?period=2026-10')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.allocated', '500.00')->assertJsonPath('items.0.remaining', '499.00');
    }

    public static function closedCalendarMonths(): array
    {
        return [
            'thirty day month' => ['2026-09', '2026-08-31', '2026-09-01', '2026-09-30', '2026-10-01'],
            'leap year February' => ['2024-02', '2024-01-31', '2024-02-01', '2024-02-29', '2024-03-01'],
            'December year end' => ['2025-12', '2025-11-30', '2025-12-01', '2025-12-31', '2026-01-01'],
            'January year start' => ['2026-01', '2025-12-31', '2026-01-01', '2026-01-31', '2026-02-01'],
        ];
    }

    #[DataProvider('closedCalendarMonths')]
    public function test_monthly_spending_includes_both_calendar_boundaries_and_excludes_adjacent_months(string $period, string $before, string $first, string $last, string $after): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user, ['period' => $period]);
        $this->payment($department, '999.00', ['effective_on' => $before]);
        $this->payment($department, '1.10', ['effective_on' => $first]);
        $this->payment($department, '2.20', ['effective_on' => $last]);
        $this->payment($department, '999.00', ['effective_on' => $after]);
        $this->externalInvoice($department, '999.00', ['external_voucher_on' => $before]);
        $this->externalInvoice($department, '3.30', ['external_voucher_on' => $first]);
        $this->externalInvoice($department, '4.40', ['external_voucher_on' => $last]);
        $this->externalInvoice($department, '999.00', ['external_voucher_on' => $after]);

        $this->actingAs($user)->getJson('/departments/budgets?period='.$period)->assertOk()
            ->assertJsonPath('period', $period)->assertJsonPath('items.0.spent', '11.00')->assertJsonPath('items.0.remaining', '89.30');
    }

    public function test_a_payment_in_an_earlier_month_is_not_counted_again_when_the_external_invoice_is_settled(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user, ['period' => '2026-10']);
        $this->budget($department, $user);
        $invoice = $this->externalInvoice($department, '40.00');
        $transaction = $this->payment($department, '10.00', ['effective_on' => '2026-10-31']);
        DB::table('finance_invoice_payments')->insert(['invoice_id' => $invoice, 'transaction_id' => $transaction, 'amount' => '10.00']);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-10')->assertOk()
            ->assertJsonPath('items.0.spent', '10.00')->assertJsonPath('items.0.remaining', '90.30');
        $this->getJson('/departments/budgets?period=2026-11')->assertOk()
            ->assertJsonPath('items.0.spent', '30.00')->assertJsonPath('items.0.remaining', '70.30');
    }

    public function test_legacy_annual_allocations_are_preserved_without_becoming_monthly_allocations(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->appoint($target, $user);
        $this->currency();
        $annual = $this->budget($target, $user, ['fiscal_month' => null, 'allocated_amount' => '1200.00', 'reference' => 'ANNUAL-LEGACY']);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11&department_id='.$target)->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.configured', false)->assertJsonPath('items.0.allocated', null)->assertDontSee('ANNUAL-LEGACY');
        $this->get('/finance/department-budgets?period=2026-11', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonCount(0, 'props.budgets')->assertDontSee('ANNUAL-LEGACY');
        $this->patchJson('/finance/department-budgets/'.$annual, $this->changes())->assertNotFound();
        $this->post('/finance/department-budgets', $this->payload($target))->assertRedirect('/finance/department-budgets?period=2026-11');

        $this->assertDatabaseHas('finance_department_budgets', ['id' => $annual, 'fiscal_year' => 2026, 'fiscal_month' => null, 'allocated_amount' => '1200.00', 'revision' => 1]);
        $this->assertDatabaseHas('finance_department_budgets', ['department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'allocated_amount' => '100.30']);
        $this->assertDatabaseCount('finance_department_budgets', 2);
        $this->assertDatabaseCount('finance_audit_events', 1);
    }

    public function test_expired_head_roles_and_retired_departments_are_not_in_the_read_scope(): void
    {
        $current = $this->department('HR');
        $inactive = $this->department('INACTIVE', ['is_active' => false]);
        $deleted = $this->department('DELETED', ['deleted_at' => '2026-11-26 10:00:00']);
        $expired = $this->department('EXPIRED');
        $user = $this->appoint($current);
        foreach ([$inactive, $deleted, $expired] as $department) {
            $this->appoint($department, $user);
        }
        DB::table('department_user_roles')->where('user_id', $user->id)->where('department_id', $expired)->update(['ends_on' => '2026-11-26']);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.departmentId', $current);
    }

    public function test_executed_reversals_cancel_spending_while_pending_reversals_do_not(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user);
        $reversed = $this->payment($department, '50.00');
        $this->payment($department, '50.00', ['kind' => 'receipt', 'reversal_of_id' => $reversed]);
        $pending = $this->payment($department, '20.10');
        $this->payment($department, '20.10', ['kind' => 'receipt', 'state' => 'pending_director', 'reversal_of_id' => $pending]);
        $this->payment($department, '999.00', ['reversal_of_id' => 999]);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()
            ->assertJsonPath('items.0.spent', '20.10')->assertJsonPath('items.0.remaining', '80.20');
    }

    public function test_only_paid_recorded_external_vouchers_in_the_selected_month_count_as_spending(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user, ['as_of' => '2026-01-01']);
        $this->externalInvoice($department, '10.15');
        $this->externalInvoice($department, '999.00', ['state' => 'pending']);
        $this->externalInvoice($department, '999.00', ['external_voucher_no' => null]);
        $this->externalInvoice($department, '999.00', ['external_recorded_at' => null]);
        $this->externalInvoice($department, '999.00', ['external_voucher_on' => '2026-10-31']);
        $this->externalInvoice($department, '999.00', ['external_voucher_on' => '2026-11-28']);
        $this->externalInvoice($department, '999.00', ['currency' => 'USD']);
        $this->externalInvoice($this->department('OTHER'), '999.00');

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()
            ->assertJsonPath('items.0.spent', '10.15')->assertJsonPath('items.0.remaining', '90.15')
            ->assertJsonPath('items.0.asOf', '2026-11-27')->assertJsonPath('items.0.allocationAsOf', '2026-01-01');
    }

    public function test_internal_and_external_invoice_payments_are_counted_once_including_partial_settlements(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user, ['allocated_amount' => '500.00']);
        foreach ([['100.30', '100.30'], ['50.20', '20.10']] as [$amount, $paid]) {
            $invoice = $this->externalInvoice($department, $amount);
            $transaction = $this->payment($department, $paid);
            DB::table('finance_invoice_payments')->insert(['invoice_id' => $invoice, 'transaction_id' => $transaction, 'amount' => $paid]);
        }
        $invoice = $this->externalInvoice($department, '40.00');
        $transaction = $this->payment($department, '10.00');
        $reversal = $this->payment($department, '10.00', ['kind' => 'receipt', 'reversal_of_id' => $transaction]);
        DB::table('finance_invoice_payments')->insert(['invoice_id' => $invoice, 'transaction_id' => $transaction, 'amount' => '10.00', 'reversed_by_transaction_id' => $reversal]);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()
            ->assertJsonPath('items.0.spent', '190.50')->assertJsonPath('items.0.remaining', '309.50');
    }

    public function test_subordinate_unit_spending_belongs_to_the_nearest_department_even_after_unit_retirement(): void
    {
        $department = $this->department('FIN');
        $unit = $this->department('FIN-U1', ['node_type' => 'unit', 'parent_id' => $department]);
        $nestedUnit = $this->department('FIN-U1-A', ['node_type' => 'unit', 'parent_id' => $unit, 'is_active' => false, 'deleted_at' => now()]);
        $nestedDepartment = $this->department('HR', ['parent_id' => $department]);
        $otherUnit = $this->department('HR-U1', ['node_type' => 'unit', 'parent_id' => $nestedDepartment]);
        $user = $this->appoint($department);
        $this->budget($department, $user);
        $this->payment($unit, '5.10');
        $this->externalInvoice($nestedUnit, '10.20');
        $this->externalInvoice($otherUnit, '999.00');
        $this->payment($nestedDepartment, '999.00');

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.departmentId', $department)->assertJsonPath('items.0.spent', '15.30')->assertJsonPath('items.0.remaining', '85.00');
    }

    public function test_future_payments_do_not_reduce_the_current_or_future_budget_before_their_effective_date(): void
    {
        $department = $this->department('HR');
        $user = $this->appoint($department);
        $this->budget($department, $user);
        $this->budget($department, $user, ['period' => '2026-12']);
        $this->payment($department, '99.00', ['effective_on' => '2026-11-28']);
        $this->payment($department, '99.00', ['effective_on' => '2026-12-01']);

        $this->actingAs($user)->getJson('/departments/budgets?period=2026-11')->assertOk()->assertJsonPath('items.0.spent', '0.00');
        $this->getJson('/departments/budgets?period=2026-12')->assertOk()->assertJsonPath('items.0.spent', '0.00');
    }

    public function test_accounts_head_opens_management_with_only_active_departments_and_currencies(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $this->department('UNIT', ['node_type' => 'unit', 'parent_id' => $accounts]);
        $this->department('OFFICE', ['node_type' => 'office']);
        $this->department('INACTIVE', ['is_active' => false]);
        $this->department('DELETED', ['deleted_at' => '2026-11-26 10:00:00']);
        $user = $this->appoint($accounts);
        $this->currency('ILS');
        $this->currency('USD', false);
        $this->budget($target, $user);

        $this->actingAs($user)->get('/finance/department-budgets?period=2026-11', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('component', 'Finance/DepartmentBudgets')->assertJsonPath('props.period', '2026-11')
            ->assertJsonCount(2, 'props.departments')->assertJsonCount(1, 'props.currencies')->assertJsonCount(1, 'props.budgets');
        self::assertSame('/finance/department-budgets?period=2026-11', app(DepartmentBudgets::class)->summary($user, '2026-11')['manageUrl']);
    }

    public function test_budget_can_be_created_in_documentary_mode_without_changing_accounting_mode(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();
        DB::table('finance_module_settings')->insert(['id' => 1, 'accounting_mode' => 'documentary', 'external_system_name' => 'البرنامج الخارجي']);

        $this->actingAs($user)->post('/finance/department-budgets', $this->payload($target))->assertRedirect('/finance/department-budgets?period=2026-11');

        $this->assertDatabaseHas('finance_department_budgets', ['department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'currency' => 'ILS', 'allocated_amount' => '100.30', 'revision' => 1, 'updated_by' => $user->id]);
        $this->assertDatabaseHas('finance_module_settings', ['id' => 1, 'accounting_mode' => 'documentary']);
        $this->assertDatabaseCount('finance_audit_events', 1);
        $this->assertDatabaseHas('finance_audit_events', ['entity_type' => 'department_budget', 'actor_id' => $user->id, 'action' => 'create']);
    }

    public function test_accounts_head_can_allocate_two_independent_months_in_the_same_year_and_currency(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();

        $this->actingAs($user)->post('/finance/department-budgets', $this->payload($target))->assertRedirect('/finance/department-budgets?period=2026-11');
        $this->post('/finance/department-budgets', [...$this->payload($target), 'period' => '2026-12', 'allocated_amount' => '200.50'])
            ->assertRedirect('/finance/department-budgets?period=2026-12');

        $this->assertDatabaseHas('finance_department_budgets', ['department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'currency' => 'ILS', 'allocated_amount' => '100.30']);
        $this->assertDatabaseHas('finance_department_budgets', ['department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 12, 'currency' => 'ILS', 'allocated_amount' => '200.50']);
        $this->assertDatabaseCount('finance_department_budgets', 2);
        $this->assertDatabaseCount('finance_audit_events', 2);
        $this->get('/finance/department-budgets?period=2026-11', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonCount(1, 'props.budgets')->assertJsonPath('props.budgets.0.allocated', '100.30');
        $this->get('/finance/department-budgets?period=2026-12', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonCount(1, 'props.budgets')->assertJsonPath('props.budgets.0.allocated', '200.50');
    }

    public function test_omitting_the_report_period_defaults_to_the_current_month(): void
    {
        $accounts = $this->department('FIN');
        $user = $this->appoint($accounts);
        $this->budget($accounts, $user);
        $this->budget($accounts, $user, ['period' => '2026-10', 'allocated_amount' => '200.00']);

        $this->actingAs($user)->getJson('/departments/budgets')->assertOk()
            ->assertJsonPath('period', '2026-11')->assertJsonPath('items.0.allocated', '100.30');
        $this->get('/finance/department-budgets', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.period', '2026-11')->assertJsonPath('props.budgets.0.allocated', '100.30');
    }

    public static function invalidReportPeriods(): array
    {
        return [
            'annual period' => ['2026'],
            'invalid month' => ['2026-13'],
            'invalid day suffix' => ['2026-11-01'],
            'un-padded month' => ['2026-1'],
            'unsupported year' => ['2019-12'],
        ];
    }

    public static function supportedBoundaryPeriods(): array
    {
        return [
            'first supported month' => ['2020-01', 2020, 1],
            'last supported month' => ['2100-12', 2100, 12],
        ];
    }

    #[DataProvider('supportedBoundaryPeriods')]
    public function test_the_first_and_last_supported_months_can_be_allocated_and_reported(string $period, int $year, int $month): void
    {
        $accounts = $this->department('FIN');
        $user = $this->appoint($accounts);
        $this->currency();

        $this->actingAs($user)->post('/finance/department-budgets', [...$this->payload($accounts), 'period' => $period])
            ->assertRedirect('/finance/department-budgets?period='.$period);

        $this->assertDatabaseHas('finance_department_budgets', ['department_id' => $accounts, 'fiscal_year' => $year, 'fiscal_month' => $month, 'allocated_amount' => '100.30']);
        $this->assertDatabaseCount('finance_audit_events', 1);
        $this->getJson('/departments/budgets?period='.$period)->assertOk()
            ->assertJsonPath('period', $period)->assertJsonPath('items.0.allocated', '100.30');
        $response = $this->get('/finance/department-budgets?period='.$period, ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.period', $period)->assertJsonPath('props.budgets.0.allocated', '100.30');
        self::assertContains($period, $response->json('props.periods'));
    }

    #[DataProvider('invalidReportPeriods')]
    public function test_invalid_month_filters_return_422_from_the_summary_and_management_endpoints(string $period): void
    {
        $accounts = $this->department('FIN');
        $user = $this->appoint($accounts);

        $this->actingAs($user)->getJson('/departments/budgets?period='.$period)->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->getJson('/finance/department-budgets?period='.$period)->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_duplicate_department_month_and_currency_is_rejected_without_overwriting_existing_data(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();
        $this->budget($target, $user);

        $this->actingAs($user)->postJson('/finance/department-budgets', [...$this->payload($target), 'allocated_amount' => '999.00'])->assertUnprocessable()->assertJsonValidationErrors('department_id');

        $this->assertDatabaseCount('finance_department_budgets', 1);
        $this->assertDatabaseHas('finance_department_budgets', ['allocated_amount' => '100.30']);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public function test_update_records_a_new_revision_and_audit_and_stale_revision_does_not_overwrite_it(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();
        $id = $this->budget($target, $user);

        $this->actingAs($user)->patch('/finance/department-budgets/'.$id, $this->changes())->assertRedirect('/finance/department-budgets?period=2026-11');

        $this->assertDatabaseHas('finance_department_budgets', ['id' => $id, 'department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'currency' => 'ILS', 'allocated_amount' => '150.40', 'revision' => 2, 'updated_by' => $user->id]);
        $this->assertDatabaseHas('finance_audit_events', ['entity_type' => 'department_budget', 'actor_id' => $user->id, 'action' => 'update']);

        $this->patchJson('/finance/department-budgets/'.$id, [...$this->changes(), 'allocated_amount' => '0.00'])->assertUnprocessable()->assertJsonValidationErrors('revision');

        $this->assertDatabaseHas('finance_department_budgets', ['id' => $id, 'allocated_amount' => '150.40', 'revision' => 2]);
        $this->assertDatabaseCount('finance_audit_events', 1);
    }

    public function test_budget_updates_are_atomic_when_the_financial_audit_cannot_be_written(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();
        $id = $this->budget($target, $user);
        DB::statement("CREATE TRIGGER fail_budget_audit BEFORE INSERT ON finance_audit_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");

        $this->actingAs($user)->patchJson('/finance/department-budgets/'.$id, $this->changes())->assertServerError();

        $this->assertDatabaseHas('finance_department_budgets', ['id' => $id, 'allocated_amount' => '100.30', 'revision' => 1]);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public function test_updates_cannot_move_a_budget_to_another_department_month_or_currency(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $other = $this->department('PR');
        $user = $this->appoint($accounts);
        $this->currency();
        $id = $this->budget($target, $user);

        $this->actingAs($user)->patchJson('/finance/department-budgets/'.$id, [...$this->changes(), 'department_id' => $other, 'period' => '2026-12', 'fiscal_year' => 2025, 'fiscal_month' => 12, 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors(['department_id', 'period', 'fiscal_year', 'fiscal_month', 'currency']);

        $this->assertDatabaseHas('finance_department_budgets', ['id' => $id, 'department_id' => $target, 'fiscal_year' => 2026, 'fiscal_month' => 11, 'currency' => 'ILS', 'allocated_amount' => '100.30', 'revision' => 1]);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public function test_update_of_a_missing_budget_returns_404_without_inserting_a_record(): void
    {
        $accounts = $this->department('FIN');
        $user = $this->appoint($accounts);

        $this->actingAs($user)->patchJson('/finance/department-budgets/999', $this->changes())->assertNotFound();

        $this->assertDatabaseCount('finance_department_budgets', 0);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'negative allocation' => ['allocated_amount', '-0.01'],
            'client supplied spending' => ['spent_amount', '0.00'],
            'fractional cent' => ['allocated_amount', '0.001'],
            'exponent money' => ['allocated_amount', '1e5'],
            'future as of date' => ['as_of', '2026-11-28'],
            'period outside supported years' => ['period', '1900-11'],
            'missing month' => ['period', '2026'],
            'invalid month' => ['period', '2026-13'],
            'zero month' => ['period', '2026-00'],
            'un-padded month' => ['period', '2026-1'],
            'annual allocation key' => ['fiscal_year', 2026],
            'client supplied fiscal month' => ['fiscal_month', 11],
            'missing external reference' => ['reference', ''],
            'unknown currency' => ['currency', 'XYZ'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_budget_inputs_return_422_and_write_no_record_or_audit(string $field, mixed $value): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency();

        $this->actingAs($user)->postJson('/finance/department-budgets', [...$this->payload($target), $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('finance_department_budgets', 0);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    public static function invalidDepartmentConditions(): array
    {
        return [
            'unit' => [['node_type' => 'unit']],
            'office' => [['node_type' => 'office']],
            'inactive' => [['is_active' => false]],
            'deleted' => [['deleted_at' => '2026-11-26 10:00:00']],
        ];
    }

    #[DataProvider('invalidDepartmentConditions')]
    public function test_budgets_cannot_be_entered_for_units_offices_or_inactive_departments(array $attributes): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('TARGET', $attributes);
        $user = $this->appoint($accounts);
        $this->currency();

        $this->actingAs($user)->postJson('/finance/department-budgets', $this->payload($target))->assertUnprocessable()->assertJsonValidationErrors('department_id');

        $this->assertDatabaseCount('finance_department_budgets', 0);
    }

    public function test_inactive_currency_is_rejected_without_writing_budget_or_audit(): void
    {
        $accounts = $this->department('FIN');
        $target = $this->department('HR');
        $user = $this->appoint($accounts);
        $this->currency('ILS', false);

        $this->actingAs($user)->postJson('/finance/department-budgets', $this->payload($target))->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->assertDatabaseCount('finance_department_budgets', 0);
        $this->assertDatabaseCount('finance_audit_events', 0);
    }

    private function department(string $code, array $attributes = []): int
    {
        return DB::table('departments')->insertGetId(['code' => $code, 'name' => 'قسم '.$code, 'is_active' => true, 'node_type' => 'department', ...$attributes]);
    }

    private function person(array $attributes = []): User
    {
        return User::factory()->create(['is_active' => true, 'must_change_password' => false, ...$attributes]);
    }

    private function appoint(int $department, ?User $user = null): User
    {
        $user ??= $this->person();
        DB::table('departments')->where('id', $department)->update(['head_user_id' => $user->id]);
        $this->role($user, 'department_head', $department);

        return $user;
    }

    private function role(User $user, string $code, ?int $department, array $dates = []): void
    {
        $role = DB::table('roles')->where('code', $code)->value('id') ?? DB::table('roles')->insertGetId(['code' => $code, 'name' => $code]);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => $department, ...$dates]);
    }

    private function currency(string $code = 'ILS', bool $active = true): void
    {
        DB::table('currencies')->insert(['code' => $code, 'name_ar' => $code, 'is_active' => $active]);
    }

    private function payload(int $department): array
    {
        return ['department_id' => $department, 'period' => '2026-11', 'currency' => 'ILS', 'allocated_amount' => '100.30', 'as_of' => '2026-11-27', 'reference' => 'EXT-2026-11', 'notes' => 'مطابقة مع سجل الحسابات'];
    }

    private function changes(): array
    {
        return ['allocated_amount' => '150.40', 'as_of' => '2026-11-27', 'reference' => 'EXT-2026-12', 'notes' => null, 'revision' => 1];
    }

    private function budget(int $department, User $actor, array $attributes = []): int
    {
        $values = [...$this->payload($department), 'updated_by' => $actor->id, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(), ...$attributes];
        [$year, $month] = explode('-', $values['period']);
        unset($values['period']);

        return DB::table('finance_department_budgets')->insertGetId(['fiscal_year' => (int) $year, 'fiscal_month' => (int) $month, ...$values]);
    }

    private function payment(int $department, string $amount, array $attributes = []): int
    {
        $line = DB::table('finance_budget_lines')->insertGetId(['department_id' => $department]);

        return DB::table('finance_transactions')->insertGetId(['kind' => 'payment', 'state' => 'executed', 'budget_line_id' => $line, 'currency' => 'ILS', 'amount' => $amount, 'effective_on' => '2026-11-27', ...$attributes]);
    }

    private function externalInvoice(int $department, string $amount, array $attributes = []): int
    {
        $purchase = DB::table('finance_purchase_requests')->insertGetId(['department_id' => $department]);
        $order = DB::table('finance_purchase_orders')->insertGetId(['purchase_request_id' => $purchase]);

        return DB::table('finance_purchase_invoices')->insertGetId(['order_id' => $order, 'currency' => 'ILS', 'amount' => $amount, 'state' => 'paid', 'external_voucher_no' => 'EXT-PAID', 'external_voucher_on' => '2026-11-27', 'external_recorded_at' => '2026-11-27 10:00:00', ...$attributes]);
    }
}
