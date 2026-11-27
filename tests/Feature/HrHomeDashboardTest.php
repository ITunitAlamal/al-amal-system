<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Hr\HrHomeDashboard;
use App\Services\Hr\HrServicesDashboard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrHomeDashboardTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
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
        $this->travelTo('2026-11-26 10:00:00');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        foreach ([
            'roles' => ['code', 'name'],
            'department_user_roles' => ['user_id', 'role_id', 'department_id', 'starts_on', 'ends_on'],
            'departments' => ['name', 'code', 'head_user_id', 'is_active', 'deleted_at', 'parent_id'],
            'employees' => ['user_id', 'employee_number', 'first_name_ar', 'family_name_ar', 'department_id', 'employment_status', 'onboarding_status', 'name_review_status', 'deleted_at'],
            'employee_documents' => ['employee_id', 'verification_status', 'is_current', 'archived_at'],
            'employee_photos' => ['employee_id', 'status'],
            'attendance_daily_results' => ['employee_id', 'work_date', 'status'],
            'employee_leave_requests' => ['employee_id', 'requested_by', 'leave_type_value_id', 'status', 'start_date', 'end_date', 'requested_days', 'submitted_at', 'workflow_instance_id'],
            'workflow_definitions' => ['code', 'name', 'is_confidential'],
            'workflow_versions' => ['workflow_definition_id'],
            'workflow_steps' => ['name', 'assignment_type'],
            'workflow_instances' => ['public_id', 'reference_no', 'workflow_version_id', 'current_step_id', 'department_id', 'requested_by', 'subject_type', 'subject_id', 'status', 'held_at', 'hold_until', 'due_at', 'sla_breached_at', 'started_at', 'completed_at', 'created_at', 'updated_at'],
            'workflow_participants' => ['workflow_instance_id', 'user_id', 'participation_type', 'is_active', 'revoked_at'],
            'aims_requests' => ['title'],
            'approval_delegations' => ['scope_code', 'status', 'starts_on', 'ends_on', 'delegate_user_id', 'delegator_user_id'],
            'custom_field_definitions' => ['code'],
            'custom_field_values' => ['definition_id', 'entity_id', 'entity_type', 'value_master_id', 'value_text'],
            'master_data_values' => ['name_ar', 'code'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    public function test_file_review_counts_each_current_employee_once_and_excludes_archived_evidence(): void
    {
        foreach (range(1, 7) as $id) {
            DB::table('employees')->insert(['id' => $id, 'name_review_status' => $id === 3 ? 'suggested' : 'reviewed', 'deleted_at' => $id === 7 ? now() : null]);
        }
        DB::table('employee_documents')->insert([
            ['employee_id' => 1, 'verification_status' => 'pending', 'is_current' => true, 'archived_at' => null],
            ['employee_id' => 1, 'verification_status' => 'pending', 'is_current' => true, 'archived_at' => null],
            ['employee_id' => 4, 'verification_status' => 'pending', 'is_current' => true, 'archived_at' => now()],
            ['employee_id' => 5, 'verification_status' => 'pending', 'is_current' => false, 'archived_at' => null],
            ['employee_id' => 7, 'verification_status' => 'pending', 'is_current' => true, 'archived_at' => null],
        ]);
        DB::table('employee_photos')->insert([
            ['employee_id' => 1, 'status' => 'pending_review'], ['employee_id' => 2, 'status' => 'pending_review'],
            ['employee_id' => 6, 'status' => 'approved'], ['employee_id' => 7, 'status' => 'pending_review'],
        ]);

        self::assertSame([1, 2, 3], app(HrServicesDashboard::class)->staffFilesForReview()->orderBy('e.id')->pluck('e.id')->all());
    }

    public function test_summary_shows_four_metrics_and_counts_only_the_viewers_actionable_leaves(): void
    {
        $head = $this->createHrHead();
        DB::table('employees')->insert(['employment_status' => 'active', 'onboarding_status' => 'completed', 'name_review_status' => 'suggested']);
        DB::table('attendance_daily_results')->insert([
            ['employee_id' => 1, 'work_date' => '2026-11-26', 'status' => 'present'],
            ['employee_id' => 2, 'work_date' => '2026-11-26', 'status' => 'missing_punches'],
            ['employee_id' => 3, 'work_date' => '2026-11-26', 'status' => 'off'],
        ]);
        // Another person's pending leave must not inflate "إجازات تنتظر إجرائي".
        DB::table('employee_leave_requests')->insert(['status' => 'submitted', 'start_date' => '2026-11-26', 'end_date' => '2026-11-27']);
        $tasks = collect([$this->task('leave-1', 'leave'), $this->task('duplicate', 'leave', ['url' => '/tasks/leave-1']), $this->task('other-role', 'finance')]);

        $cards = collect(app(HrHomeDashboard::class)->summary($head, $tasks)['cards'])->keyBy('key');

        self::assertSame(['leaves', 'attendance', 'services', 'staff_files'], $cards->keys()->all());
        self::assertSame(1, $cards['leaves']['value']);
        self::assertSame('/tasks?type=leave', $cards['leaves']['url']);
        self::assertSame(1, $cards['attendance']['value']);
        self::assertStringContainsString('حالات تحتاج مراجعة: 1', $cards['attendance']['detail']);
        self::assertSame(1, $cards['staff_files']['value']);
        self::assertSame('/admin/employee-profiles?state=review', $cards['staff_files']['url']);
    }

    public function test_action_queue_deduplicates_before_limiting_and_keeps_other_role_duties(): void
    {
        $tasks = collect(range(1, 9))->map(fn (int $id): array => $this->task('task-'.$id, 'leave', ['dueAt' => '2026-12-'.str_pad((string) $id, 2, '0', STR_PAD_LEFT)]));
        $tasks->push($this->task('duplicate', 'leave', ['url' => '/tasks/task-1', 'dueAt' => '2026-12-01']));
        $tasks->push($this->task('finance', 'finance', ['overdue' => true]));
        $tasks->push($this->task('registration', 'leave', ['step' => 'تسجيل الإجازة', 'overdue' => true, 'dueAt' => '2026-11-20']));

        $result = app(HrHomeDashboard::class)->actions($tasks);

        self::assertSame(11, $result['total']);
        self::assertCount(7, $result['data']);
        self::assertSame(['registration', 'finance'], array_column(array_slice($result['data'], 0, 2), 'key'));
        self::assertSame('تسجيل الإجازة', $result['data'][0]['action']);
        self::assertSame(1, collect($result['data'])->where('url', '/tasks/task-1')->count());
    }

    public function test_follow_up_excludes_my_assignments_and_prioritizes_overdue_then_urgent(): void
    {
        $head = $this->createHrHead();
        $other = User::factory()->create(['is_active' => true]);
        $this->workflow(1, 'MAINTENANCE');
        DB::table('custom_field_definitions')->insert(['id' => 1, 'code' => 'priority']);
        DB::table('master_data_values')->insert(['id' => 1, 'code' => 'urgent', 'name_ar' => 'عاجل']);
        foreach (range(1, 8) as $id) {
            $this->workflowInstance($id, 1, $other->id, ['due_at' => $id === 2 ? '2026-11-25 10:00:00' : null]);
            DB::table('workflow_participants')->insert(['workflow_instance_id' => $id, 'user_id' => $id === 1 ? $head->id : $other->id, 'participation_type' => 'assignee', 'is_active' => true]);
        }
        DB::table('custom_field_values')->insert(['definition_id' => 1, 'entity_id' => 3, 'entity_type' => 'aims_request', 'value_master_id' => 1]);

        $result = app(HrServicesDashboard::class)->services($head, 'open', 1, 5, true);

        self::assertSame(7, $result['total']);
        self::assertCount(5, $result['data']);
        self::assertSame(['REQ-2', 'REQ-3'], array_column(array_slice($result['data'], 0, 2), 'reference'));
        self::assertNotContains('REQ-1', array_column($result['data'], 'reference'));
        self::assertSame($other->name, $result['data'][0]['holders']);
        self::assertNull($result['data'][0]['url']); // Overview access does not grant permission to open another department's request.
    }

    public function test_leave_task_link_filters_actual_assignments_without_losing_other_tasks(): void
    {
        $head = $this->createHrHead();
        $other = User::factory()->create(['is_active' => true]);
        $this->workflow(1, 'MAINTENANCE');
        $this->workflow(2, 'LEAVE_EMPLOYEE');
        DB::table('employees')->insert(['id' => 1, 'user_id' => $other->id, 'department_id' => 1]);
        DB::table('employee_leave_requests')->insert(['id' => 2, 'employee_id' => 1, 'requested_by' => $other->id, 'status' => 'pending_registration', 'workflow_instance_id' => 2, 'start_date' => '2026-11-26', 'end_date' => '2026-11-27']);
        $this->workflowInstance(1, 1, $other->id);
        $this->workflowInstance(2, 2, $other->id, ['subject_type' => 'employee_leave_request']);
        $this->workflowInstance(3, 1, $other->id);
        foreach ([1 => $head->id, 2 => $head->id, 3 => $other->id] as $id => $holder) {
            DB::table('workflow_participants')->insert(['workflow_instance_id' => $id, 'user_id' => $holder, 'participation_type' => 'assignee', 'is_active' => true]);
        }

        $this->actingAs($head)->getJson('/tasks?type=leave', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.leaveOnly', true)->assertJsonCount(1, 'props.tasks')->assertJsonPath('props.tasks.0.url', '/leaves/2');
        $this->getJson('/tasks', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.leaveOnly', false)->assertJsonCount(2, 'props.tasks');
    }

    private function createHrHead(): User
    {
        $user = User::factory()->create(['is_active' => true, 'department_id' => 1]);
        DB::table('departments')->insert(['id' => 1, 'code' => 'HR', 'name' => 'HR', 'head_user_id' => $user->id, 'is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => 'department_head']);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => 1]);

        return $user;
    }

    private function task(string $key, string $type, array $extra = []): array
    {
        return [...['key' => $key, 'type' => $type, 'title' => $key, 'kind' => $type, 'step' => 'مراجعة الطلب', 'reference' => $key,
            'requester' => 'Fixture', 'dueAt' => null, 'overdue' => false, 'url' => '/tasks/'.$key], ...$extra];
    }

    private function workflow(int $id, string $code): void
    {
        DB::table('workflow_definitions')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_confidential' => false]);
        DB::table('workflow_versions')->insert(['id' => $id, 'workflow_definition_id' => $id]);
        DB::table('workflow_steps')->insert(['id' => $id, 'name' => $code === 'LEAVE_EMPLOYEE' ? 'تسجيل الإجازة' : 'تنفيذ الصيانة', 'assignment_type' => 'role']);
    }

    private function workflowInstance(int $id, int $workflow, int $requester, array $extra = []): void
    {
        DB::table('aims_requests')->insert(['id' => $id, 'title' => 'Fixture '.$id]);
        DB::table('workflow_instances')->insert([...['id' => $id, 'public_id' => '00000000-0000-4000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT),
            'reference_no' => 'REQ-'.$id, 'workflow_version_id' => $workflow, 'current_step_id' => $workflow, 'department_id' => null, 'requested_by' => $requester,
            'subject_type' => 'aims_request', 'subject_id' => $id, 'status' => 'in_progress', 'started_at' => '2026-11-26 08:00:00', 'created_at' => now(), 'updated_at' => now()], ...$extra]);
    }
}
