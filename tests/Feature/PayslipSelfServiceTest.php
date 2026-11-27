<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Finance\FinancePayslips;
use App\Services\Progress\TransactionTracker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;

class PayslipSelfServiceTest extends TestCase
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
            'departments' => ['name', 'code', 'head_user_id', 'deleted_at'],
            'job_titles' => ['name_ar'],
            'employees' => ['user_id', 'employee_number', 'department_id', 'job_title_id', 'full_name_ar', 'first_name_ar', 'father_name_ar', 'family_name_ar', 'deleted_at'],
            'roles' => ['code', 'name'],
            'permissions' => ['code', 'name'],
            'permission_role' => ['role_id', 'permission_id'],
            'department_user_roles' => ['role_id', 'user_id', 'department_id', 'starts_on', 'ends_on'],
            'finance_staff_assignments' => ['user_id', 'specialization', 'is_active', 'valid_from', 'valid_until'],
            'finance_user_permissions' => ['user_id', 'permission_code', 'effect', 'valid_until'],
            'finance_board_approvers' => ['user_id', 'is_active'],
            'finance_payroll_batches' => ['period', 'currency', 'state', 'source', 'source_reference'],
            'finance_payroll_items' => ['batch_id', 'employee_id', 'gross', 'deductions', 'net', 'earnings', 'deduction_lines'],
            'finance_payslips' => ['reference', 'payroll_item_id', 'batch_id', 'employee_id', 'user_id', 'period', 'currency', 'published_at', 'view_count', 'first_viewed_at', 'last_viewed_at', 'created_at', 'updated_at'],
            'finance_payslip_requests' => ['reference', 'user_id', 'employee_id', 'period', 'note', 'state', 'payslip_id', 'fulfilled_at', 'created_at', 'updated_at'],
            'finance_audit_events' => ['entity_type', 'entity_id', 'actor_id', 'action', 'detail', 'created_at', 'updated_at'],
            'audit_logs' => ['actor_id', 'actor_guardian_id', 'action', 'subject_type', 'subject_id', 'subject_ref', 'metadata', 'ip_address', 'outcome', 'reason_code', 'severity', 'sensitivity', 'created_at', 'updated_at'],
            'notification_settings' => ['event_code', 'in_app_enabled', 'sms_enabled', 'sms_template'],
            'workflow_definitions' => ['code', 'name'],
            'workflow_versions' => ['workflow_definition_id'],
            'workflow_steps' => ['name', 'assignment_type'],
            'workflow_instances' => ['public_id', 'reference_no', 'status', 'due_at', 'sla_breached_at', 'created_at', 'updated_at', 'started_at', 'workflow_version_id', 'current_step_id', 'subject_type', 'subject_id', 'requested_by', 'held_at', 'hold_until'],
            'aims_requests' => ['title'],
            'employee_leave_requests' => ['status', 'start_date', 'end_date', 'requested_by', 'employee_id', 'requested_days', 'workflow_instance_id', 'created_at', 'submitted_at', 'leave_type_value_id'],
            'master_data_values' => ['name_ar'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        Schema::table('finance_payslip_requests', fn (Blueprint $table) => $table->unique(['user_id', 'period']));
        Schema::create('notifications', function (Blueprint $table): void {
            $table->string('id')->primary();
            foreach (['type', 'notifiable_type', 'notifiable_id', 'event_code', 'dedupe_key', 'data', 'read_at', 'created_at', 'updated_at'] as $column) {
                $table->text($column)->nullable();
            }
        });
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    public function test_employee_without_finance_permissions_can_open_self_service_and_guests_cannot(): void
    {
        $this->get('/requests/payslip')->assertRedirect('/login');
        $user = $this->employee();

        $this->actingAs($user)->get('/requests/payslip', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('component', 'Requests/Payslip')->assertJsonPath('props.linked', true)
            ->assertJsonPath('props.months', [])->assertJsonPath('props.requests', []);
    }

    public function test_available_months_are_unique_published_months_of_the_current_employee_newest_first(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $this->slip($user, 'published', '2024-01');
        $this->slip($user, 'published', '2026-10');
        $this->slip($user, 'published', '2026-10', 'USD');
        $this->slip($user, 'draft', '2026-11');
        $this->slip($user, 'published', '2026-12');
        $this->slip($other, 'published', '2026-09');

        $this->actingAs($user)->get('/requests/payslip', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.months', ['2026-10', '2024-01']);

        DB::table('employees')->where('id', $user->id)->update(['user_id' => null]);

        $this->get('/requests/payslip', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.linked', false)->assertJsonPath('props.months', []);
    }

    public function test_request_ignores_forged_employee_identity_and_reuses_own_published_slip(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $ownSlip = $this->slip($user);
        $this->slip($other);

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-10', 'employee_id' => $other->id, 'user_id' => $other->id])
            ->assertRedirect('/payslips/PS-202610-ILS-000001')->assertSessionHasNoErrors();
        $this->post('/requests/payslip', ['period' => '2026-10'])->assertRedirect('/payslips/PS-202610-ILS-000001');

        $this->assertDatabaseCount('finance_payslip_requests', 1);
        $this->assertDatabaseHas('finance_payslip_requests', ['user_id' => $user->id, 'employee_id' => $user->id, 'state' => 'fulfilled', 'payslip_id' => $ownSlip]);
        self::assertSame('PS-202610-ILS-000001', app(FinancePayslips::class)->requestsOf($user)->sole()->slip);
        $this->assertDatabaseCount('finance_audit_events', 2);
    }

    public function test_inertia_issue_opens_the_printable_slip_immediately_without_approval_or_notifications(): void
    {
        $user = $this->employee();
        $this->slip($user);
        $this->enablePayslipNotifications();

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-10'], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/payslips/PS-202610-ILS-000001')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('finance_payslip_requests', ['user_id' => $user->id, 'state' => 'fulfilled', 'payslip_id' => 1]);
        $this->assertDatabaseCount('finance_payslip_requests', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('workflow_instances', 0);
        $this->get('/payslips/PS-202610-ILS-000001')->assertOk()->assertSee('2850.00')->assertSee('طباعة أو حفظ PDF');
        $overview = app(TransactionTracker::class)->overview($user, 5);
        self::assertSame(0, $overview['open']);
        self::assertSame(1, $overview['completed']);
        self::assertTrue($overview['latest'][0]['printable']);
        self::assertSame('/payslips/PS-202610-ILS-000001', $overview['latest'][0]['url']);
    }

    public function test_month_without_payroll_is_rejected_without_creating_a_waiting_request(): void
    {
        $user = $this->employee();
        $this->enablePayslipNotifications();

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-10'])->assertSessionHasErrors('period');

        $this->assertNoPayslipRequestSideEffects();
    }

    #[TestWith(['draft'])]
    #[TestWith(['pending_approval'])]
    #[TestWith(['pending_executive'])]
    #[TestWith(['approved'])]
    public function test_unpublished_payroll_does_not_issue_a_slip_or_create_an_approval_request(string $state): void
    {
        $user = $this->employee();
        $this->slip($user, $state);
        $this->enablePayslipNotifications();

        $this->actingAs($user)->get('/requests/payslip', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.months', []);
        $this->post('/requests/payslip', ['period' => '2026-10'])->assertSessionHasErrors('period');
        $this->get('/payslips/PS-202610-ILS-000001')->assertNotFound()->assertDontSee('2850.00');

        $this->assertNoPayslipRequestSideEffects();
        $this->assertDatabaseHas('finance_payroll_batches', ['id' => 1, 'state' => $state]);
    }

    public function test_published_payroll_for_another_employee_does_not_create_a_waiting_request(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $this->slip($other);
        $this->enablePayslipNotifications();

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-10'])->assertSessionHasErrors('period');

        $this->assertNoPayslipRequestSideEffects();
    }

    public function test_account_without_employee_record_cannot_issue_or_create_a_waiting_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->enablePayslipNotifications();

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-10'])->assertSessionHasErrors('period');

        $this->assertNoPayslipRequestSideEffects();
    }

    public function test_only_current_owner_can_print_with_private_headers_and_escaped_content(): void
    {
        $user = $this->employee('<script>private_name()</script>');
        $other = $this->employee();
        $this->slip($user);

        $this->actingAs($user)->get('/payslips/PS-202610-ILS-000001')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('طباعة أو حفظ PDF')->assertSee('2850.00')->assertSee('&lt;script&gt;private_name()&lt;/script&gt;', false)
            ->assertDontSee('<script>private_name()</script>', false)->assertDontSee('bank_account')->assertDontSee('national_id');
        $this->actingAs($other)->get('/payslips/PS-202610-ILS-000001')->assertForbidden()->assertDontSee('2850.00');

        $this->assertDatabaseHas('finance_payslips', ['id' => 1, 'view_count' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.payslip.viewed', 'subject_id' => '1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.payslip.view_denied', 'subject_id' => '1']);
    }

    public function test_employee_link_change_revokes_historical_account_and_current_account_can_print(): void
    {
        $former = $this->employee();
        $current = User::factory()->create(['is_active' => true]);
        $this->slip($former);
        DB::table('employees')->where('id', $former->id)->update(['user_id' => $current->id]);

        $this->actingAs($former)->get('/payslips/PS-202610-ILS-000001')->assertForbidden();
        $this->actingAs($current)->get('/payslips/PS-202610-ILS-000001')->assertOk()->assertSee('2850.00');
        DB::table('employees')->where('id', $former->id)->update(['deleted_at' => now()]);
        $this->get('/payslips/PS-202610-ILS-000001')->assertForbidden();
    }

    public function test_published_only_history_and_tracker_never_offer_a_draft_or_reassigned_slip_url(): void
    {
        $user = $this->employee();
        $id = $this->slip($user, 'draft');
        DB::table('finance_payslip_requests')->insert(['reference' => 'PSR-2026-00001', 'user_id' => $user->id, 'employee_id' => $user->id,
            'period' => '2026-10', 'state' => 'fulfilled', 'payslip_id' => $id, 'created_at' => now(), 'fulfilled_at' => now()]);

        $this->actingAs($user)->get('/payslips/PS-202610-ILS-000001')->assertNotFound();
        self::assertNull(app(FinancePayslips::class)->requestsOf($user)->sole()->slip);
        $item = app(TransactionTracker::class)->requestsOf($user, [], 1)->items()[0];
        self::assertSame('/requests/payslip', $item['url']);
        self::assertFalse($item['printable']);
        DB::table('finance_payroll_batches')->where('id', 1)->update(['state' => 'published']);
        $item = app(TransactionTracker::class)->requestsOf($user, [], 1)->items()[0];
        self::assertSame('/payslips/PS-202610-ILS-000001', $item['url']);
        self::assertTrue($item['printable']);
        DB::table('employees')->where('id', $user->id)->update(['user_id' => null]);
        self::assertNull(app(FinancePayslips::class)->requestsOf($user)->sole()->slip);
    }

    public function test_waiting_request_is_fulfilled_and_notified_only_after_payroll_publication(): void
    {
        $user = $this->employee();
        DB::table('notification_settings')->insert(['event_code' => 'finance_payslip_ready', 'in_app_enabled' => true, 'sms_enabled' => false]);
        $this->legacyWaitingRequest($user);
        $this->assertDatabaseHas('finance_payslip_requests', ['user_id' => $user->id, 'state' => 'awaiting_payroll', 'payslip_id' => null]);
        $this->slip($user);

        app(FinancePayslips::class)->fulfilWaiting('2026-10');

        $this->assertDatabaseHas('finance_payslip_requests', ['user_id' => $user->id, 'state' => 'fulfilled', 'payslip_id' => 1]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'event_code' => 'finance_payslip_ready']);
    }

    public function test_waiting_request_cannot_deliver_former_employees_slip_after_account_reassignment(): void
    {
        $user = $this->employee();
        $this->legacyWaitingRequest($user);
        $this->slip($user);
        DB::table('employees')->where('id', $user->id)->update(['user_id' => null]);

        app(FinancePayslips::class)->fulfilWaiting('2026-10');

        $this->assertDatabaseHas('finance_payslip_requests', ['user_id' => $user->id, 'state' => 'no_employee_record', 'payslip_id' => null]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_future_and_invalid_periods_do_not_create_requests_and_inactive_users_are_refused(): void
    {
        $user = $this->employee();

        $this->actingAs($user)->post('/requests/payslip', ['period' => '2026-12'])->assertSessionHasErrors(['period' => 'لا تُطلب قسيمة شهر لم يأتِ بعد.']);
        $this->post('/requests/payslip', ['period' => 'not-a-month'])->assertSessionHasErrors('period');
        $this->assertDatabaseCount('finance_payslip_requests', 0);
        $user->is_active = false;
        $this->get('/requests/payslip')->assertForbidden();
        $this->post('/requests/payslip', ['period' => '2026-10'])->assertForbidden();
    }

    public function test_explicit_payroll_staff_permission_can_still_print_an_employees_slip(): void
    {
        $user = $this->employee();
        $this->slip($user);
        $staff = User::factory()->create(['is_active' => true]);
        DB::table('finance_user_permissions')->insert(['user_id' => $staff->id, 'permission_code' => 'finance.payroll.record', 'effect' => 'grant']);
        DB::table('finance_staff_assignments')->insert(['user_id' => $staff->id, 'specialization' => 'payroll', 'is_active' => true]);

        $this->actingAs($staff)->get('/payslips/PS-202610-ILS-000001')->assertOk()->assertSee('2850.00');
    }

    private function employee(string $name = 'موظف الاختبار'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        DB::table('employees')->insert(['id' => $user->id, 'user_id' => $user->id, 'employee_number' => 'E-'.$user->id, 'full_name_ar' => $name]);

        return $user;
    }

    private function assertNoPayslipRequestSideEffects(): void
    {
        $this->assertDatabaseCount('finance_payslip_requests', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    private function enablePayslipNotifications(): void
    {
        foreach (['finance_payslip_requested', 'finance_payslip_ready'] as $event) {
            DB::table('notification_settings')->insert(['event_code' => $event, 'in_app_enabled' => true, 'sms_enabled' => false]);
        }
        $staff = User::factory()->create(['is_active' => true]);
        DB::table('finance_user_permissions')->insert(['user_id' => $staff->id, 'permission_code' => 'finance.payroll.record', 'effect' => 'grant']);
        DB::table('finance_staff_assignments')->insert(['user_id' => $staff->id, 'specialization' => 'payroll', 'is_active' => true]);
    }

    private function legacyWaitingRequest(User $user): void
    {
        DB::table('finance_payslip_requests')->insert(['reference' => 'PSR-2026-00001', 'user_id' => $user->id, 'employee_id' => $user->id,
            'period' => '2026-10', 'state' => 'awaiting_payroll', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function slip(User $user, string $state = 'published', string $period = '2026-10', string $currency = 'ILS'): int
    {
        $batchId = DB::table('finance_payroll_batches')->where('period', $period)->where('currency', $currency)->value('id');
        $batchId ??= DB::table('finance_payroll_batches')->insertGetId(['period' => $period, 'state' => $state, 'currency' => $currency, 'source' => 'local']);
        $id = DB::table('finance_payroll_items')->insertGetId(['batch_id' => $batchId, 'employee_id' => $user->id, 'gross' => '3000.00', 'deductions' => '150.00', 'net' => '2850.00',
            'earnings' => json_encode([['label' => 'الراتب الأساسي', 'amount' => '3000.00']]), 'deduction_lines' => json_encode([['label' => 'خصومات', 'amount' => '150.00']])]);

        return DB::table('finance_payslips')->insertGetId(['reference' => 'PS-'.str_replace('-', '', $period).'-'.$currency.'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            'payroll_item_id' => $id, 'batch_id' => $batchId, 'employee_id' => $user->id, 'user_id' => $user->id, 'period' => $period, 'currency' => $currency,
            'published_at' => now(), 'view_count' => 0]);
    }
}
