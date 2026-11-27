<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\DatabasePresenceVerifier;
use PHPUnit\Framework\Attributes\DataProvider;

class FinanceUnitPermissionsTest extends TestCase
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
        foreach ([
            'departments' => ['name', 'code', 'parent_id', 'node_type', 'head_user_id', 'is_active', 'deleted_at', 'created_at', 'updated_at'],
            'roles' => ['code', 'name', 'created_at', 'updated_at'],
            'permissions' => ['code', 'name', 'name_ar', 'created_at', 'updated_at'],
            'permission_role' => ['role_id', 'permission_id'],
            'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on', 'created_at', 'updated_at'],
            'finance_staff_assignments' => ['user_id', 'specialization', 'is_active', 'is_primary', 'valid_from', 'valid_until', 'grant_id', 'assigned_by', 'created_at', 'updated_at'],
            'finance_user_permissions' => ['user_id', 'permission_code', 'effect', 'reason', 'valid_until', 'grant_id', 'created_at', 'updated_at'],
            'finance_access_grants' => ['public_id', 'user_id', 'kind', 'code', 'state', 'valid_from', 'valid_until', 'previous_effect', 'created_at', 'updated_at'],
            'finance_board_approvers' => ['user_id', 'is_active'],
            'finance_module_settings' => ['accounting_mode', 'external_system_name'],
            'finance_audit_events' => ['entity_type', 'entity_id', 'actor_id', 'action', 'detail', 'created_at', 'updated_at'],
            'finance_transactions' => ['kind', 'document_no', 'treasury_id', 'budget_line_id', 'source_id', 'reversal_of_id', 'amount', 'currency', 'effective_on', 'counterparty', 'description', 'state', 'created_by', 'reviewed_by', 'approved_by', 'executed_by', 'executed_at', 'created_at', 'updated_at'],
            'finance_treasuries' => ['name', 'currency', 'is_active'],
            'finance_budget_lines' => ['title', 'currency'],
            'finance_sources' => ['source_module', 'source_id'],
            'finance_cheques' => ['transaction_id', 'state'],
            'finance_suppliers' => ['name'],
            'finance_warehouses' => ['name', 'is_active'],
            'finance_stock_items' => ['name', 'sku', 'is_active', 'minimum_qty'],
            'finance_stock_movements' => ['stock_item_id', 'warehouse_id', 'kind', 'quantity'],
            'finance_purchase_requests' => ['state', 'number', 'created_by', 'updated_at'],
            'finance_purchase_orders' => ['purchase_request_id', 'state', 'created_by', 'updated_at'],
            'finance_purchase_invoices' => ['order_id', 'invoice_no', 'state', 'amount', 'currency', 'external_voucher_no', 'external_voucher_on', 'external_recorded_by', 'external_recorded_at', 'updated_at'],
            'finance_restricted_funds' => ['name', 'currency'],
            'finance_advances' => ['state'],
            'finance_payroll_batches' => ['state'],
            'finance_receivables' => ['state'],
            'finance_internal_transfers' => ['state'],
            'finance_reconciliations' => ['state'],
            'finance_inventory_counts' => ['state'],
            'employees' => ['employee_number', 'first_name_ar', 'family_name_ar'],
            'currencies' => ['code', 'name_ar'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        // Keep real existence queries in SQLite; the production wrapper inspects PostgreSQL metadata.
        $this->app['validator']->setPresenceVerifier(new DatabasePresenceVerifier($this->app['db']));
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    public function test_migration_preserves_existing_units_and_assignments_and_separates_executor_templates(): void
    {
        $payer = $this->staff('cash_executor', 'finance_cash_executor', ['finance.transaction.execute']);
        $accountant = $this->staff('general_accountant', 'finance_accountant', ['finance.transaction.draft', 'finance.rentals.record']);
        DB::table('departments')->insert([
            ['id' => 18, 'name' => 'المشتريات', 'code' => 'FIN-U1', 'parent_id' => 1, 'node_type' => 'unit'],
            ['id' => 19, 'name' => 'المخازن', 'code' => 'FIN-U2', 'parent_id' => 1, 'node_type' => 'unit'],
        ]);
        DB::table('finance_user_permissions')->insert(['user_id' => $accountant->id, 'permission_code' => 'finance.transaction.execute', 'effect' => 'deny', 'valid_until' => '2026-12-31']);
        $this->migrationConstraints();

        (require database_path('migrations/2026_11_30_000018_finance_units_and_cash_permissions.php'))->up();

        self::assertSame([18, 19], DB::table('departments')->where('parent_id', 1)->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('finance_staff_assignments', 2);
        $this->assertDatabaseCount('department_user_roles', 2);
        $guard = new FinanceGuard;
        self::assertTrue($guard->allowsTransaction($payer, 'payment', 'execute'));
        self::assertTrue($guard->allows($payer, 'finance.payment.record'));
        self::assertFalse($guard->allowsTransaction($payer, 'receipt', 'execute'));
        self::assertFalse($guard->allows($accountant, 'finance.rentals.record'));
        foreach (['finance.payment.execute', 'finance.receipt.execute'] as $permission) {
            $this->assertDatabaseHas('finance_user_permissions', ['user_id' => $accountant->id, 'permission_code' => $permission, 'effect' => 'deny', 'valid_until' => '2026-12-31']);
        }
        $this->assertDatabaseHas('roles', ['code' => 'finance_receipt_executor']);
        $this->assertDatabaseMissing('finance_user_permissions', ['permission_code' => 'finance.transaction.execute']);
    }

    public function test_migration_creates_only_missing_accounts_units_and_splits_pending_shared_grants(): void
    {
        DB::table('departments')->insert(['id' => 1, 'name' => 'الحسابات', 'code' => 'FIN', 'node_type' => 'department']);
        DB::table('finance_access_grants')->insert([
            ['user_id' => 1, 'public_id' => 'old', 'kind' => 'permission', 'code' => 'finance.transaction.execute', 'state' => 'pending_approval'],
            ['user_id' => 1, 'public_id' => 'existing', 'kind' => 'permission', 'code' => 'finance.payment.execute', 'state' => 'pending_approval'],
        ]);
        $this->migrationConstraints();
        DB::statement("CREATE UNIQUE INDEX finance_pending_test ON finance_access_grants (user_id, kind, code) WHERE state = 'pending_approval'");

        (require database_path('migrations/2026_11_30_000018_finance_units_and_cash_permissions.php'))->up();

        self::assertSame(['المشتريات', 'المخازن'], DB::table('departments')->where('parent_id', 1)->orderBy('id')->pluck('name')->all());
        $this->assertDatabaseHas('departments', ['code' => 'FIN_PURCHASING', 'parent_id' => 1, 'node_type' => 'unit']);
        $this->assertDatabaseHas('departments', ['code' => 'FIN_STORES', 'parent_id' => 1, 'node_type' => 'unit']);
        self::assertSame(['finance.payment.execute', 'finance.receipt.execute'], DB::table('finance_access_grants')->where('state', 'pending_approval')->orderBy('code')->pluck('code')->all());
        $this->assertDatabaseCount('finance_staff_assignments', 0);
    }

    public function test_approval_queue_and_document_cycle_only_expose_the_assigned_area(): void
    {
        $approver = $this->staff('director_approver', 'finance_director_approver', ['finance.receipt.approve']);
        $this->voucher(1, 'receipt', 'pending_director');
        $this->voucher(2, 'payment', 'pending_director');
        $queue = app(FinanceApprovalQueue::class);
        self::assertSame([1], array_column($queue->entries($approver), 'id'));
        self::assertSame([1], array_values($queue->counts($approver)));

        $storekeeper = $this->staff('warehouse', 'finance_warehouse', ['finance.stock.receive']);
        $this->actingAs($storekeeper)->getJson('/finance/cycle')->assertOk()->assertJsonCount(2, 'stages')
            ->assertJsonPath('stages.0.area', 'stores')->assertJsonPath('stages.1.area', 'stores')->assertJsonPath('pending', []);
    }

    private function migrationConstraints(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->unique('code'));
        Schema::table('permissions', fn (Blueprint $table) => $table->unique('code'));
        Schema::table('permission_role', fn (Blueprint $table) => $table->unique(['role_id', 'permission_id']));
        Schema::table('finance_user_permissions', fn (Blueprint $table) => $table->unique(['user_id', 'permission_code']));
    }

    public static function financeSpecializations(): array
    {
        return [
            'procurement' => ['procurement', 'finance_procurement', ['finance.procurement.manage'], 'finance.procurement.manage'],
            'stores' => ['warehouse', 'finance_warehouse', ['finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue'], 'finance.stock.receive'],
            'payroll' => ['payroll', 'finance_payroll', ['finance.payroll.record'], 'finance.payroll.record'],
            'payments' => ['cash_executor', 'finance_cash_executor', ['finance.payment.execute', 'finance.payment.record'], 'finance.payment.execute'],
            'receipts' => ['receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute', 'finance.rentals.record'], 'finance.receipt.execute'],
        ];
    }

    #[DataProvider('financeSpecializations')]
    public function test_each_specialization_exposes_only_its_own_operational_permissions(string $specialization, string $role, array $permissions, string $expected): void
    {
        $user = $this->staff($specialization, $role, $permissions);
        $guard = new FinanceGuard;
        $operations = ['finance.procurement.manage', 'finance.stock.receive', 'finance.payroll.record', 'finance.payment.execute', 'finance.receipt.execute'];

        self::assertSame([$expected], array_values(array_intersect($operations, $guard->uiPermissions($user))));
        self::assertFalse($guard->allows($user, 'finance.transaction.execute'));
    }

    public function test_multiple_specializations_accumulate_and_ending_one_does_not_revoke_the_others(): void
    {
        $user = $this->staff('procurement', 'finance_procurement', ['finance.procurement.manage']);
        $this->assign($user, 'warehouse', 'finance_warehouse', ['finance.stock.receive']);
        $this->assign($user, 'payroll', 'finance_payroll', ['finance.payroll.record']);
        self::assertTrue((new FinanceGuard)->allows($user, 'finance.stock.receive'));

        DB::table('finance_staff_assignments')->where('user_id', $user->id)->where('specialization', 'warehouse')->update(['is_active' => false]);

        $guard = new FinanceGuard;
        self::assertTrue($guard->allows($user, 'finance.procurement.manage'));
        self::assertTrue($guard->allows($user, 'finance.payroll.record'));
        self::assertFalse($guard->allows($user, 'finance.stock.receive'));
        self::assertSame(3, DB::table('department_user_roles')->where('user_id', $user->id)->count());
    }

    public function test_expired_and_future_specializations_do_not_borrow_another_current_assignment(): void
    {
        $user = $this->staff('warehouse', 'finance_warehouse', ['finance.stock.issue']);
        $this->assign($user, 'cash_executor', 'finance_cash_executor', ['finance.payment.record'], ['valid_until' => '2026-11-26']);
        $this->assign($user, 'payroll', 'finance_payroll', ['finance.payroll.record'], ['valid_from' => '2026-11-28']);

        $guard = new FinanceGuard;

        self::assertTrue($guard->allows($user, 'finance.stock.issue'));
        self::assertFalse($guard->allows($user, 'finance.payment.record'));
        self::assertFalse($guard->allows($user, 'finance.payroll.record'));
        DB::table('finance_staff_assignments')->where('user_id', $user->id)->where('specialization', 'payroll')->update(['valid_from' => '2026-11-27', 'valid_until' => '2026-11-27']);
        self::assertTrue((new FinanceGuard)->allows($user, 'finance.payroll.record'));
    }

    public function test_personal_grants_wait_for_the_start_date_and_denials_override_roles(): void
    {
        $user = $this->staff('warehouse', 'finance_warehouse', ['finance.stock.receive']);
        $grant = DB::table('finance_access_grants')->insertGetId(['user_id' => $user->id, 'kind' => 'permission', 'code' => 'finance.payment.record', 'state' => 'applied', 'valid_from' => '2026-11-28']);
        DB::table('finance_user_permissions')->insert([
            ['user_id' => $user->id, 'permission_code' => 'finance.payment.record', 'effect' => 'grant', 'grant_id' => $grant],
            ['user_id' => $user->id, 'permission_code' => 'finance.stock.receive', 'effect' => 'deny', 'grant_id' => null],
        ]);

        $guard = new FinanceGuard;

        self::assertFalse($guard->allows($user, 'finance.payment.record'));
        self::assertFalse($guard->allows($user, 'finance.stock.receive'));
        DB::table('finance_access_grants')->where('id', $grant)->update(['valid_from' => '2026-11-27']);
        self::assertTrue((new FinanceGuard)->allows($user, 'finance.payment.record'));
        DB::table('finance_user_permissions')->where('permission_code', 'finance.payment.record')->update(['valid_until' => '2026-11-26']);
        self::assertFalse((new FinanceGuard)->allows($user, 'finance.payment.record'));
    }

    public function test_payment_and_receipt_permissions_can_be_combined_explicitly(): void
    {
        $user = $this->staff('cash_executor', 'finance_cash_executor', ['finance.payment.execute', 'finance.payment.record']);
        $this->assign($user, 'receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute', 'finance.rentals.record']);

        $guard = new FinanceGuard;

        self::assertTrue($guard->allowsTransaction($user, 'payment', 'execute'));
        self::assertTrue($guard->allowsTransaction($user, 'receipt', 'execute'));
        self::assertTrue($guard->allows($user, 'finance.payment.record'));
        self::assertTrue($guard->allows($user, 'finance.rentals.record'));
        self::assertFalse($guard->allowsTransaction($user, 'payment', 'approve'));
    }

    public function test_future_grant_keeps_the_previous_denial_until_its_start_date(): void
    {
        $user = $this->staff('warehouse', 'finance_warehouse', ['finance.stock.receive']);
        $grant = DB::table('finance_access_grants')->insertGetId(['user_id' => $user->id, 'kind' => 'permission', 'code' => 'finance.stock.receive', 'state' => 'applied', 'valid_from' => '2026-11-28', 'previous_effect' => 'deny']);
        DB::table('finance_user_permissions')->insert(['user_id' => $user->id, 'permission_code' => 'finance.stock.receive', 'effect' => 'grant', 'grant_id' => $grant]);

        self::assertFalse((new FinanceGuard)->allows($user, 'finance.stock.receive'));

        $this->travelTo('2026-11-28 10:00:00');
        self::assertTrue((new FinanceGuard)->allows($user, 'finance.stock.receive'));
    }

    public function test_documentary_mode_keeps_external_payment_and_receipt_recording_separate(): void
    {
        DB::table('finance_module_settings')->insert(['id' => 1, 'accounting_mode' => 'documentary']);
        $payer = $this->staff('cash_executor', 'finance_cash_executor', ['finance.payment.execute', 'finance.payment.record']);
        $collector = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute', 'finance.rentals.record']);
        $guard = new FinanceGuard;
        self::assertTrue($guard->allows($payer, 'finance.payment.record'));
        self::assertFalse($guard->allows($payer, 'finance.rentals.record'));
        self::assertFalse($guard->allowsTransaction($payer, 'payment', 'execute'));
        self::assertTrue($guard->allows($collector, 'finance.rentals.record'));
        self::assertFalse($guard->allows($collector, 'finance.payment.record'));

        $this->actingAs($collector)->postJson('/finance/invoices/1/external-voucher', [])->assertForbidden();
        $this->actingAs($payer)->postJson('/finance/rentals/receipts', [])->assertForbidden();
        $this->actingAs($payer)->postJson('/finance/invoices/1/external-voucher', [])->assertUnprocessable()->assertJsonValidationErrors(['voucher_no', 'voucher_on']);
        $this->actingAs($collector)->postJson('/finance/rentals/receipts', [])->assertUnprocessable()->assertJsonValidationErrors(['reference', 'allocations']);
    }

    public function test_transaction_records_filter_by_authorized_kind_and_refuse_an_explicit_other_kind(): void
    {
        $user = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute']);
        $this->voucher(1, 'receipt');
        $this->voucher(2, 'payment');

        $this->actingAs($user)->getJson('/finance/records/transactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'receipt');
        $this->getJson('/finance/records/transactions?kind=receipt')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/finance/records/transactions?kind=payment')->assertForbidden();
    }

    public function test_execution_authorizes_the_stored_kind_even_if_the_payload_claims_another_kind(): void
    {
        $user = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute']);
        $this->voucher(1, 'payment', 'approved');
        $this->voucher(2, 'receipt', 'approved');

        $this->actingAs($user)->postJson('/finance/transactions/1/execute', ['kind' => 'receipt'])->assertForbidden();
        $this->assertDatabaseHas('finance_transactions', ['id' => 1, 'state' => 'approved', 'executed_by' => null]);
        $this->postJson('/finance/transactions/2/execute')->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('finance_transactions', ['id' => 2, 'state' => 'executed', 'executed_by' => $user->id]);
        $this->assertDatabaseHas('finance_audit_events', ['entity_type' => 'transaction', 'entity_id' => '2', 'action' => 'execute']);
    }

    public function test_creation_requires_the_permission_for_the_requested_kind(): void
    {
        $user = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.draft']);
        DB::table('finance_treasuries')->insert(['id' => 1, 'name' => 'Cash', 'currency' => 'ILS', 'is_active' => true]);
        $data = ['kind' => 'receipt', 'treasury_id' => 1, 'amount' => '50.00', 'effective_on' => '2026-11-27'];

        $this->actingAs($user)->postJson('/finance/transactions', [...$data, 'kind' => 'payment'])->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->postJson('/finance/transactions', $data)->assertCreated();
        $this->assertDatabaseHas('finance_transactions', ['kind' => 'receipt', 'amount' => '50.00', 'created_by' => $user->id, 'state' => 'draft']);
    }

    public function test_bootstrap_does_not_leak_other_units_or_the_other_voucher_kind(): void
    {
        $user = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute']);
        $this->voucher(1, 'receipt');
        $this->voucher(2, 'payment');
        $this->voucher(3, 'payment');
        DB::table('finance_suppliers')->insert(['name' => 'SECRET SUPPLIER']);
        DB::table('employees')->insert(['employee_number' => 'SECRET EMPLOYEE']);
        DB::table('finance_budget_lines')->insert(['title' => 'SECRET BUDGET', 'currency' => 'ILS']);

        $response = $this->actingAs($user)->getJson('/finance/bootstrap')->assertOk();

        $response->assertJsonPath('summary.transactions', 1)->assertJsonPath('lookups.suppliers', [])->assertJsonPath('lookups.employees', [])->assertJsonPath('lookups.budgets', []);
        $response->assertDontSee('SECRET SUPPLIER')->assertDontSee('SECRET EMPLOYEE')->assertDontSee('SECRET BUDGET');
        self::assertNotContains('finance.payment.execute', $response->json('allowed'));
    }

    public function test_reports_and_exports_refuse_the_other_kind_and_list_only_authorized_reports(): void
    {
        $user = $this->staff('receipt_executor', 'finance_receipt_executor', ['finance.receipt.execute', 'finance.reports.view', 'finance.report.export']);
        $this->voucher(1, 'receipt');
        $this->voucher(2, 'payment');

        $this->actingAs($user)->getJson('/finance/reports')->assertOk()->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.key', 'receipts');
        $this->getJson('/finance/reports/receipts')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('page.total', 1);
        $this->getJson('/finance/reports/payments')->assertForbidden();
        $this->getJson('/finance/reports/payments/export/csv')->assertForbidden();
    }

    public function test_finance_routes_require_authentication_and_membership_alone_is_insufficient(): void
    {
        $this->get('/finance/records/transactions')->assertRedirect('/login');
        $user = User::factory()->create(['is_active' => true, 'department_id' => 1]);

        $this->actingAs($user)->getJson('/finance/records/transactions')->assertForbidden();
        $this->getJson('/finance/bootstrap')->assertForbidden();
    }

    private function staff(string $specialization, string $role, array $permissions): User
    {
        DB::table('departments')->updateOrInsert(['id' => 1], ['name' => 'الحسابات', 'code' => 'FIN', 'is_active' => true, 'node_type' => 'department']);
        $user = User::factory()->create(['is_active' => true, 'department_id' => 1]);
        $this->assign($user, $specialization, $role, $permissions);

        return $user;
    }

    private function assign(User $user, string $specialization, string $roleCode, array $permissions, array $dates = []): void
    {
        $role = DB::table('roles')->where('code', $roleCode)->value('id') ?? DB::table('roles')->insertGetId(['code' => $roleCode, 'name' => $roleCode]);
        foreach (array_unique(['finance.view', ...$permissions]) as $code) {
            $permission = DB::table('permissions')->where('code', $code)->value('id') ?? DB::table('permissions')->insertGetId(['code' => $code, 'name' => $code]);
            DB::table('permission_role')->updateOrInsert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => 1]);
        DB::table('finance_staff_assignments')->insert(['user_id' => $user->id, 'specialization' => $specialization, 'is_active' => true, ...$dates]);
    }

    private function voucher(int $id, string $kind, string $state = 'draft'): void
    {
        DB::table('finance_transactions')->insert(['id' => $id, 'kind' => $kind, 'document_no' => strtoupper($kind).'-'.$id, 'currency' => 'ILS', 'amount' => '25.00', 'effective_on' => '2026-11-27', 'state' => $state, 'created_by' => 999, 'created_at' => now(), 'updated_at' => now()]);
    }
}
