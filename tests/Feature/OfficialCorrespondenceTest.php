<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceEditions;
use App\Services\Correspondence\CorrespondenceRegistry;
use App\Services\Dashboard\PersonalWorkspace;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowInbox;
use App\Services\WorkflowVisibilityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

final class OfficialCorrespondenceTest extends TestCase
{
    private array $people = [];

    private array $departments = [];

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'pgsql');
        $port = (int) (getenv('AIMS_CORRESPONDENCE_TEST_PORT') ?: 55439);
        self::assertContains($port, [55439, 55440]);
        $app['config']->set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'aims_correspondence_test_20261022', 'username' => 'kpitest', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('aims_correspondence_test_20261022', DB::connection()->getDatabaseName());
        self::assertContains(config('database.connections.pgsql.port'), [55439, 55440]);
        self::assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $server = DB::selectOne('select current_database() as name, inet_server_port() as port');
        self::assertSame('aims_correspondence_test_20261022', $server->name);
        self::assertSame(config('database.connections.pgsql.port'), (int) $server->port);
        if (! Schema::hasColumn('workflow_instances', 'held_at')) {
            DB::transaction(function (): void {
                (require database_path('migrations/2026_11_13_000002_workflow_hold_and_reopen.php'))->up();
            });
        }
        if (! Schema::hasTable('official_correspondences')) {
            DB::transaction(function (): void {
                (require database_path('migrations/2026_11_14_000001_create_official_correspondence_registry.php'))->up();
            });
        }
        if (! DB::table('workflow_definitions')->where('code', 'BOARD_CORRESPONDENCE')->exists()) {
            DB::transaction(function (): void {
                (require database_path('migrations/2026_11_14_000002_install_board_correspondence_workflow.php'))->up();
            });
        }
        DB::beginTransaction();
        if (! Schema::hasColumn('departments', 'node_type')) {
            Schema::table('departments', fn (Blueprint $table) => $table->string('node_type', 20)->default('department'));
        }
        if (! Schema::hasColumn('workflow_definitions', 'is_confidential')) {
            Schema::table('workflow_definitions', fn (Blueprint $table) => $table->boolean('is_confidential')->default(false));
        }
        if (! Schema::hasTable('correspondence_editions')) {
            (require database_path('migrations/2026_11_21_000001_create_correspondence_editions_table.php'))->up();
        }
        if (! Schema::hasColumn('official_correspondences', 'channel')) {
            (require database_path('migrations/2026_11_21_000003_add_external_correspondence_archiving.php'))->up();
        }
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->withoutMiddleware(HandleInertiaRequests::class);
        foreach (['A', 'B', 'EXEC', 'SEC', 'SECRETARIAT'] as $code) {
            $this->departments[$code] = DB::table('departments')->insertGetId(['code' => $code, 'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['employee' => ['A', 'department_employee'], 'peer' => ['A', 'department_employee'], 'head' => ['A', 'department_head'], 'other' => ['B', 'department_head'], 'exec' => ['EXEC', 'executive_director'], 'secretary' => ['SEC', 'secretariat'], 'archive_clerk' => ['SECRETARIAT', 'department_employee'], 'archive_peer' => ['SECRETARIAT', 'department_employee'], 'admin' => ['EXEC', 'system_admin']] as $name => [$department, $role]) {
            $id = DB::table('roles')->where('code', $role)->value('id') ?? DB::table('roles')->insertGetId(['code' => $role, 'name' => $role, 'created_at' => now(), 'updated_at' => now()]);
            $user = User::factory()->create(['name' => $name, 'department_id' => $this->departments[$department], 'is_active' => true, 'must_change_password' => false]);
            DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $id, 'department_id' => $role === 'system_admin' ? null : $user->department_id, 'created_at' => now(), 'updated_at' => now()]);
            if ($role === 'department_head') {
                DB::table('departments')->where('id', $user->department_id)->update(['head_user_id' => $user->id]);
            }
            $this->people[$name] = $user;
        }
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function value(string $type, string $code): int
    {
        return (int) DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'correspondence_'.$type)->where('v.code', $code)->value('v.id');
    }

    private function proposeEdition(object $letter, string $actor, bool $substantive = false): object
    {
        return app(CorrespondenceEditions::class)->propose($this->people[$actor], $letter->public_id, [
            'submission_key' => (string) Str::uuid(), 'subject' => 'Replacement subject', 'body' => 'Replacement text', 'reason' => 'Correct documented error', 'substantive' => $substantive,
        ], $this->files(2));
    }

    private function editionTransition(object $edition, string $actor, string $code): void
    {
        $instance = DB::table('workflow_instances')->find($edition->workflow_instance_id);
        $transition = DB::table('workflow_transitions')->where('workflow_version_id', $instance->workflow_version_id)->where('from_step_id', $instance->current_step_id)->where('action_code', $code)->value('id');
        $this->actingAs($this->people[$actor])->post('/correspondence/editions/'.$edition->public_id.'/actions', ['transition_id' => $transition, 'revision' => $instance->revision, 'comment' => 'Reviewed text; no board resolution substance changed'])->assertRedirect();
    }

    #[Test]
    public function edition_workflow_rejects_skipped_approval_and_expired_reviewer_authority(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $edition = $this->proposeEdition($letter, 'employee');
        $instance = DB::table('workflow_instances')->find($edition->workflow_instance_id);
        $published = DB::table('workflow_steps')->where('workflow_version_id', $instance->workflow_version_id)->where('code', 'published')->value('id');
        $skip = DB::table('workflow_transitions')->insertGetId(['workflow_version_id' => $instance->workflow_version_id, 'from_step_id' => $instance->current_step_id, 'to_step_id' => $published, 'action_code' => 'approve', 'name' => 'Invalid shortcut', 'is_active' => true, 'requires_comment' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->people['head'])->postJson('/correspondence/editions/'.$edition->public_id.'/actions', ['transition_id' => $skip, 'revision' => $instance->revision, 'comment' => 'Attempted shortcut'])->assertForbidden();
        self::assertSame(1, DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->count());
        $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'pending']);
        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['ends_on' => today()->subDay()->toDateString()]);
        $this->get('/correspondence/editions/'.$edition->public_id)->assertNotFound();
        self::assertFalse(app(WorkflowInbox::class)->tasksFor($this->people['head'])->contains('subject_id', $edition->id));
    }

    #[Test]
    public function edition_submission_is_idempotent_private_and_rejected_text_never_becomes_effective(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload('employee', 'peer'), [], null, true);
        $payload = ['submission_key' => (string) Str::uuid(), 'subject' => 'Candidate', 'body' => 'Candidate body', 'reason' => 'Correction', 'substantive' => false, 'attachments' => $this->files(2)];
        $this->actingAs($this->people['employee'])->post('/correspondence/'.$letter->public_id.'/editions', $payload)->assertRedirect();
        $this->post('/correspondence/'.$letter->public_id.'/editions', $payload)->assertRedirect();
        $edition = DB::table('correspondence_editions')->where('correspondence_id', $letter->id)->first();
        self::assertSame(1, DB::table('correspondence_editions')->where('correspondence_id', $letter->id)->count());
        self::assertSame(2, DB::table('workflow_event_attachments')->where('correspondence_action_id', $edition->action_id)->count());
        $this->postJson('/correspondence/'.$letter->public_id.'/editions', [...$payload, 'body' => 'Changed retry'])->assertConflict();
        $this->postJson('/correspondence/'.$letter->public_id.'/editions', [...$payload, 'submission_key' => (string) Str::uuid()])->assertConflict();
        $file = DB::table('workflow_event_attachments')->where('correspondence_action_id', $edition->action_id)->first();
        $this->actingAs($this->people['peer'])->get('/correspondence/files/'.$file->public_id)->assertNotFound();
        $this->get('/correspondence/editions/'.$edition->public_id)->assertNotFound();
        $this->actingAs($this->people['head'])->get('/correspondence/files/'.$file->public_id)->assertOk();
        $this->editionTransition($edition, 'head', 'reject');
        $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'rejected', 'approved_at' => null]);
        $this->actingAs($this->people['peer'])->getJson('/correspondence/'.$letter->public_id.'/print', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.document.body', 'Immutable original text');
        $next = $this->proposeEdition($letter, 'employee');
        self::assertSame(2, $next->version);
        self::assertNull($next->predecessor_id);
        $this->editionTransition($next, 'head', 'reviewed');
        $this->editionTransition($next, 'exec', 'approve');
        $this->actingAs($this->people['peer'])->getJson('/correspondence/editions/'.$next->public_id, ['X-Inertia' => 'true'])->assertOk();
        self::assertSame('public_status', app(WorkflowVisibilityService::class)->accessLevel($this->people['peer'], (int) $next->workflow_instance_id));
        $third = app(CorrespondenceEditions::class)->propose($this->people['employee'], $letter->public_id, [...$payload, 'submission_key' => (string) Str::uuid(), 'body' => 'Third text'], []);
        self::assertSame($next->id, $third->predecessor_id);
        self::assertSame('Replacement text', json_decode($third->differences, true)['before']['body']);
        $this->assertDatabaseHas('correspondence_editions', ['id' => $next->id, 'body' => 'Replacement text', 'status' => 'approved']);
    }

    #[Test]
    public function board_issued_text_cannot_bypass_board_authority_by_claiming_a_non_substantive_edit(): void
    {
        $role = DB::table('roles')->insertGetId(['code' => 'board_secretary', 'name' => 'Board secretary', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('department_user_roles')->where('user_id', $this->people['employee']->id)->update(['role_id' => $role]);
        $payload = $this->payload('employee', 'secretary');
        $payload['type_value_id'] = $this->value('types', 'board_transmission');
        $payload['board_reference'] = 'Synthetic board text';
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, [], null, true);
        $edition = $this->proposeEdition($letter, 'employee', false);
        self::assertSame('board', $edition->origin_role);
        $instance = DB::table('workflow_instances')->find($edition->workflow_instance_id);
        self::assertSame([], app(WorkflowEngine::class)->availableActions($instance, $this->people['employee']));
        $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'pending']);
        $this->assertDatabaseHas('official_correspondences', ['id' => $letter->id, 'body' => 'Immutable original text']);
    }

    #[Test]
    public function staff_edition_requires_head_then_executive_and_publishes_without_mutating_original(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $edition = $this->proposeEdition($letter, 'employee');
        self::assertSame('staff', $edition->origin_role);
        self::assertNull(app(CorrespondenceEditions::class)->effective((int) $letter->id));
        self::assertTrue(app(WorkflowInbox::class)->tasksFor($this->people['head'])->contains('subject_id', $edition->id));
        $this->actingAs($this->people['employee'])->getJson('/correspondence/'.$letter->public_id.'/print', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.document.body', 'Immutable original text');
        $this->actingAs($this->people['other'])->get('/correspondence/editions/'.$edition->public_id)->assertNotFound();
        $this->editionTransition($edition, 'head', 'reviewed');
        self::assertNull(app(CorrespondenceEditions::class)->effective((int) $letter->id));
        $instance = DB::table('workflow_instances')->find($edition->workflow_instance_id);
        $approval = DB::table('workflow_transitions')->where('from_step_id', $instance->current_step_id)->where('action_code', 'approve')->value('id');
        $this->actingAs($this->people['admin'])->postJson('/correspondence/editions/'.$edition->public_id.'/actions', ['transition_id' => $approval, 'revision' => $instance->revision, 'comment' => 'Technical role only'])->assertForbidden();
        $this->editionTransition($edition, 'exec', 'approve');
        $this->assertDatabaseHas('official_correspondences', ['id' => $letter->id, 'body' => 'Immutable original text', 'reference' => $letter->reference]);
        $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'approved', 'approved_by' => $this->people['exec']->id]);
        $this->actingAs($this->people['employee'])->getJson('/correspondence/'.$letter->public_id.'/print', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.document.body', 'Replacement text')->assertJsonPath('props.document.edition_version', 1);
        self::assertSame('Immutable original text', json_decode($edition->differences, true)['before']['body']);
        self::assertSame(3, DB::table('workflow_events')->where('workflow_instance_id', $edition->workflow_instance_id)->count());
    }

    #[Test]
    public function head_and_executive_editions_require_secretariat_registration_before_approval(): void
    {
        foreach (['head', 'exec'] as $origin) {
            $letter = app(CorrespondenceRegistry::class)->draft($this->people[$origin], $this->payload($origin, $origin === 'head' ? 'exec' : 'head'), [], null, true);
            $edition = $this->proposeEdition($letter, $origin);
            self::assertSame($origin === 'exec' ? 'executive' : 'head', $edition->origin_role);
            $this->editionTransition($edition, 'secretary', 'reviewed');
            $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'pending', 'approved_at' => null]);
            $this->editionTransition($edition, 'exec', 'approve');
            $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'approved']);
        }
    }

    #[Test]
    public function substantive_board_related_editions_remain_pending_and_non_substantive_letters_use_regular_approval(): void
    {
        $payload = $this->payload('head', 'exec');
        $payload['board_reference'] = 'Synthetic board reference';
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['head'], $payload, [], null, true);
        $edition = $this->proposeEdition($letter, 'head', true);
        self::assertSame('board', $edition->origin_role);
        $instance = DB::table('workflow_instances')->find($edition->workflow_instance_id);
        self::assertSame([], app(WorkflowEngine::class)->availableActions($instance, $this->people['head']));
        self::assertSame(0, DB::table('workflow_transitions')->where('from_step_id', $instance->current_step_id)->count());
        $this->assertDatabaseHas('correspondence_editions', ['id' => $edition->id, 'status' => 'pending']);
        $payload['creation_key'] = (string) Str::uuid();
        $second = app(CorrespondenceRegistry::class)->draft($this->people['head'], $payload, [], null, true);
        $nonSubstantive = $this->proposeEdition($second, 'head', false);
        $this->editionTransition($nonSubstantive, 'secretary', 'reviewed');
        $this->editionTransition($nonSubstantive, 'exec', 'approve');
        $this->assertDatabaseHas('correspondence_editions', ['id' => $nonSubstantive->id, 'status' => 'approved', 'board_related' => true, 'substantive' => false]);
    }

    #[Test]
    public function migration_reuses_existing_reference_types_and_preserves_customized_values(): void
    {
        $type = DB::table('master_data_types')->where('code', 'correspondence_types')->first();
        $value = $this->value('types', 'letter');
        DB::table('master_data_types')->where('id', $type->id)->update(['name_ar' => 'Existing institutional type']);
        DB::table('master_data_values')->where('id', $value)->update(['name_ar' => 'Existing institutional value', 'is_active' => false]);
        $counts = [DB::table('master_data_groups')->count(), DB::table('master_data_types')->count(), DB::table('master_data_values')->count()];
        $migration = require database_path('migrations/2026_11_14_000001_create_official_correspondence_registry.php');
        $method = new \ReflectionMethod($migration, 'installReferences');
        $method->invoke($migration);
        $method->invoke($migration);
        self::assertSame($counts, [DB::table('master_data_groups')->count(), DB::table('master_data_types')->count(), DB::table('master_data_values')->count()]);
        self::assertSame($type->id, DB::table('master_data_types')->where('code', 'correspondence_types')->value('id'));
        self::assertSame($type->group_id, DB::table('master_data_types')->where('id', $type->id)->value('group_id'));
        self::assertSame('Existing institutional type', DB::table('master_data_types')->where('id', $type->id)->value('name_ar'));
        self::assertSame('Existing institutional value', DB::table('master_data_values')->where('id', $value)->value('name_ar'));
        self::assertFalse(DB::table('master_data_values')->where('id', $value)->value('is_active'));
    }

    private function payload(string $from = 'employee', string $to = 'head'): array
    {
        return ['creation_key' => (string) Str::uuid(), 'department_id' => $this->people[$from]->department_id, 'recipient_id' => $this->people[$to]->id, 'recipient_department_id' => $this->people[$to]->department_id, 'type_value_id' => $this->value('types', 'letter'), 'classification_value_id' => $this->value('classifications', 'internal'), 'priority_value_id' => $this->value('priorities', 'normal'), 'subject' => 'Private subject sentinel', 'body' => 'Immutable original text', 'letter_date' => today()->toDateString(), 'acknowledgment_required' => true];
    }

    private function files(int $count): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->createWithContent('document'.$i.'.txt', 'Official document '.$i), range(1, $count));
    }

    private function action(object $letter, string $actor, string $action, ?string $recipient = null, array $files = [], ?string $key = null): object
    {
        $data = ['revision' => $letter->revision, 'action' => $action, 'idempotency_key' => $key ?? (string) Str::uuid(), 'note' => 'Recorded reason'];
        if ($recipient) {
            $data += ['recipient_id' => $this->people[$recipient]->id, 'recipient_department_id' => $this->people[$recipient]->department_id];
        }

        return app(CorrespondenceRegistry::class)->act($this->people[$actor], $letter->public_id, $data, $files);
    }

    #[Test]
    public function attachments_keep_provenance_and_recipient_specific_visibility_through_three_handoffs(): void
    {
        $registry = app(CorrespondenceRegistry::class);
        $letter = $registry->draft($this->people['employee'], $this->payload(), $this->files(3));
        $letter = $this->action($letter, 'employee', 'issue');
        $reference = $letter->reference;
        $letter = $this->action($letter, 'head', 'forward', 'exec', $this->files(2));
        $headAction = DB::table('correspondence_actions')->where('action', 'forward')->first()->id;
        $letter = $this->action($letter, 'exec', 'forward', 'secretary', $this->files(1));
        $letter = $this->action($letter, 'secretary', 'reply', 'exec', $this->files(2));
        self::assertSame($reference, $letter->reference);
        self::assertSame('Immutable original text', $letter->body);
        self::assertSame(8, DB::table('workflow_event_attachments')->count());
        self::assertCount(8, Storage::disk('local')->allFiles());
        $access = app(CorrespondenceAccess::class);
        self::assertFalse($access->actionVisible($this->people['secretary'], (int) $headAction));
        $file = DB::table('workflow_event_attachments')->where('correspondence_action_id', $headAction)->first();
        $this->actingAs($this->people['secretary'])->get('/correspondence/files/'.$file->public_id)->assertNotFound();
        $this->actingAs($this->people['exec'])->get('/correspondence/files/'.$file->public_id)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $original = DB::table('workflow_event_attachments')->orderBy('id')->first();
        $this->actingAs($this->people['secretary'])->get('/correspondence/files/'.$original->public_id)->assertOk();
        $this->actingAs($this->people['other'])->get('/correspondence/files/'.$original->public_id)->assertNotFound();
        self::assertStringNotContainsString('Private subject sentinel', DB::table('notifications')->pluck('data')->implode(' '));
        self::assertSame((int) $this->people['exec']->id, (int) DB::table('correspondence_tasks')->value('assigned_to'));
    }

    #[Test]
    public function retry_does_not_duplicate_files_number_or_notifications_and_changed_payload_conflicts(): void
    {
        $registry = app(CorrespondenceRegistry::class);
        $payload = $this->payload();
        $files = $this->files(3);
        $letter = $registry->draft($this->people['employee'], $payload, $files);
        self::assertSame($letter->id, $registry->draft($this->people['employee'], $payload, $files)->id);
        $key = (string) Str::uuid();
        $issued = $this->action($letter, 'employee', 'issue', key: $key);
        self::assertSame($issued->reference, $this->action($letter, 'employee', 'issue', key: $key)->reference);
        self::assertSame(1, DB::table('notifications')->count());
        self::assertSame(3, DB::table('workflow_event_attachments')->count());
        self::assertSame(1, (int) DB::table('correspondence_sequences')->value('last_number'));
        $this->expectException(HttpException::class);
        $this->action($letter, 'employee', 'issue', files: $this->files(1), key: $key);
    }

    #[Test]
    public function employees_cannot_route_to_executive_cross_department_or_sign_as_admin(): void
    {
        $access = app(CorrespondenceAccess::class);
        $type = $this->value('types', 'letter');
        $class = $this->value('classifications', 'internal');
        foreach ([['employee', 'exec'], ['employee', 'other'], ['head', 'other'], ['admin', 'head']] as [$from, $to]) {
            self::assertFalse($access->canRoute($this->people[$from], $this->people[$from]->department_id, $this->people[$to], $this->people[$to]->department_id, $type, $class));
        }
        self::assertTrue($access->canRoute($this->people['employee'], $this->departments['A'], $this->people['peer'], $this->departments['A'], $type, $class));
        $this->actingAs($this->people['employee'])->postJson('/correspondence', $this->payload('employee', 'exec'))->assertForbidden();
        $this->actingAs($this->people['employee'])->postJson('/correspondence/policies', [])->assertForbidden();
    }

    #[Test]
    public function acknowledgments_do_not_complete_tasks_and_closed_records_reject_new_attachments(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), []);
        $letter = $this->action($letter, 'employee', 'issue');
        $letter = $this->action($letter, 'head', 'acknowledge');
        self::assertSame('open', DB::table('correspondence_tasks')->value('status'));
        $letter = $this->action($letter, 'head', 'complete');
        self::assertSame('closed', $letter->status);
        $this->actingAs($this->people['employee'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['action' => 'correction', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'note' => 'Late alteration', 'attachments' => $this->files(1)])->assertUnprocessable();
        self::assertSame(0, DB::table('workflow_event_attachments')->count());
    }

    #[Test]
    public function rejected_mime_count_size_and_partial_storage_failure_leave_no_successful_action(): void
    {
        $registry = app(CorrespondenceRegistry::class);
        foreach ([UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo 1;'), UploadedFile::fake()->createWithContent('fake.pdf', 'Plain text, not PDF')] as $file) {
            try {
                $registry->draft($this->people['employee'], $this->payload(), [$file]);
                self::fail('Unsafe file accepted');
            } catch (ValidationException $expected) {
                self::assertNotEmpty($expected->errors());
            }
        }
        $policy = json_decode(DB::table('correspondence_policies')->value('configuration'), true);
        $policy['file_kb'] = 1;
        $policy['total_kb'] = 1;
        $policy['file_count'] = 2;
        DB::table('correspondence_policies')->update(['configuration' => json_encode($policy)]);
        foreach ([$this->files(3), [UploadedFile::fake()->createWithContent('large.txt', str_repeat('a', 1100))], [UploadedFile::fake()->createWithContent('a.txt', str_repeat('a', 600)), UploadedFile::fake()->createWithContent('b.txt', str_repeat('b', 600))]] as $files) {
            try {
                app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $files);
                self::fail('Limit bypass');
            } catch (ValidationException $expected) {
                self::assertNotEmpty($expected->errors());
            }
        }
        self::assertSame(0, DB::table('official_correspondences')->count());
        $disk = Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturn($disk, new class
        {
            public function putFileAs(...$args): bool
            {
                return false;
            }
        }, $disk);
        try {
            app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $this->files(2));
            self::fail('Partial upload accepted');
        } catch (\RuntimeException $expected) {
            self::assertStringContainsString('Unable to store', $expected->getMessage());
        }
        self::assertSame(0, DB::table('official_correspondences')->count());
        self::assertSame(0, DB::table('correspondence_actions')->count());
        self::assertCount(0, $disk->allFiles());
    }

    #[Test]
    public function expired_acting_role_and_department_change_revoke_action_access(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $this->files(1));
        $letter = $this->action($letter, 'employee', 'issue');
        $action = (int) DB::table('correspondence_actions')->where('action', 'issue')->value('id');
        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['ends_on' => today()->subDay()->toDateString()]);
        self::assertFalse(app(CorrespondenceAccess::class)->actionVisible($this->people['head'], $action));
        DB::table('users')->where('id', $this->people['employee']->id)->update(['department_id' => $this->departments['B']]);
        DB::table('department_user_roles')->where('user_id', $this->people['employee']->id)->update(['department_id' => $this->departments['B']]);
        self::assertFalse(app(CorrespondenceAccess::class)->visible($this->people['employee']->fresh())->where('c.id', $letter->id)->exists());
    }

    #[Test]
    public function confidential_cross_department_forward_is_denied_and_original_is_immutable(): void
    {
        $payload = $this->payload();
        $payload['classification_value_id'] = $this->value('classifications', 'confidential');
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, $this->files(1));
        $letter = $this->action($letter, 'employee', 'issue');
        $this->actingAs($this->people['head'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'action' => 'forward', 'note' => 'Reason', 'recipient_id' => $this->people['exec']->id, 'recipient_department_id' => $this->people['exec']->department_id])->assertForbidden();
        $payload['revision'] = $letter->revision;
        $payload['body'] = 'Tampered';
        $this->actingAs($this->people['employee'])->postJson('/correspondence/'.$letter->public_id.'/draft', $payload)->assertForbidden();
        self::assertSame('Immutable original text', DB::table('official_correspondences')->value('body'));
    }

    #[Test]
    public function inbox_assignment_survives_missing_alert_and_reconcile_is_idempotent(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), []);
        $this->action($letter, 'employee', 'issue');
        DB::table('notifications')->delete();
        $this->actingAs($this->people['head'])->get('/correspondence', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 1);
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        self::assertSame(1, DB::table('notifications')->count());
    }

    #[Test]
    public function board_resolution_uses_real_workflow_and_cannot_skip_execution_review(): void
    {
        $role = DB::table('roles')->insertGetId(['code' => 'board_secretary', 'name' => 'Board secretary', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('department_user_roles')->where('user_id', $this->people['employee']->id)->update(['role_id' => $role]);
        $payload = $this->payload('employee', 'secretary');
        $payload['type_value_id'] = $this->value('types', 'board_transmission');
        $payload['board_reference'] = 'BOARD-2026-01';
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, $this->files(3));
        $letter = $this->action($letter, 'employee', 'issue');
        self::assertNotNull($letter->workflow_instance_id);
        self::assertSame('processing', $letter->status);
        $this->actingAs($this->people['secretary'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'action' => 'close'])->assertForbidden();
        foreach ([['secretary', 'exec', 'transmit'], ['exec', 'head', 'assign'], ['head', 'exec', 'report'], ['exec', 'secretary', 'accept_execution'], ['secretary', null, 'file_report'], ['employee', null, 'close']] as [$actor,$recipient,$action]) {
            $instance = DB::table('workflow_instances')->find($letter->workflow_instance_id);
            $transition = DB::table('workflow_transitions')->where('from_step_id', $instance->current_step_id)->where('action_code', $action)->value('id');
            $letter = app(CorrespondenceRegistry::class)->act($this->people[$actor], $letter->public_id, ['action' => 'workflow', 'revision' => $letter->revision, 'workflow_revision' => $instance->revision, 'transition_id' => $transition, 'recipient_id' => $recipient ? $this->people[$recipient]->id : null, 'idempotency_key' => (string) Str::uuid(), 'note' => 'Documented execution / review'], $this->files(1));
            if ($action !== 'close') {
                self::assertSame('processing', $letter->status);
            }
        }
        self::assertSame('closed', $letter->status);
        self::assertSame('Immutable original text', $letter->body);
        self::assertSame('completed', DB::table('workflow_instances')->where('id', $letter->workflow_instance_id)->value('status'));
        self::assertSame(9, DB::table('workflow_event_attachments')->count());
        self::assertSame(7, DB::table('workflow_events')->where('workflow_instance_id', $letter->workflow_instance_id)->count());
    }

    #[Test]
    public function directional_policy_expires_and_does_not_authorize_confidential_head_to_head_routing(): void
    {
        $type = $this->value('types', 'letter');
        $class = $this->value('classifications', 'internal');
        $configuration = ['from_department_id' => $this->departments['A'], 'to_department_id' => $this->departments['B'], 'type_value_id' => $type, 'classification_value_id' => $class, 'purpose' => 'Documented coordination'];
        $row = ['policy_key' => 'test_pair', 'version' => 1, 'kind' => 'cross_head', 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'configuration' => json_encode($configuration), 'is_active' => true, 'approval_reference' => 'DEC-1', 'created_at' => now()];
        DB::table('correspondence_policies')->insert($row);
        self::assertTrue(app(CorrespondenceAccess::class)->canRoute($this->people['head'], $this->departments['A'], $this->people['other'], $this->departments['B'], $type, $class));
        self::assertFalse(app(CorrespondenceAccess::class)->canRoute($this->people['other'], $this->departments['B'], $this->people['head'], $this->departments['A'], $type, $class));
        self::assertFalse(app(CorrespondenceAccess::class)->canRoute($this->people['head'], $this->departments['A'], $this->people['other'], $this->departments['B'], $type, $this->value('classifications', 'confidential')));
        DB::table('correspondence_policies')->where('policy_key', 'test_pair')->update(['starts_on' => today()->subDays(2)->toDateString(), 'ends_on' => today()->subDay()->toDateString()]);
        self::assertFalse(app(CorrespondenceAccess::class)->canRoute($this->people['head'], $this->departments['A'], $this->people['other'], $this->departments['B'], $type, $class));
    }

    #[Test]
    public function maintenance_executive_handoff_reaches_hr_inbox_and_stays_open(): void
    {
        DB::table('departments')->where('id', $this->departments['B'])->update(['code' => 'HR']);
        DB::table('notification_settings')->insert(['event_code' => 'workflow_task_assigned', 'label_ar' => 'Task', 'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/maintenance-routing.json'), true, flags: JSON_THROW_ON_ERROR);
        $definition = DB::table('workflow_definitions')->insertGetId(['code' => 'MAINTENANCE', 'name' => 'Maintenance', 'subject_type' => 'aims_request', 'is_active' => true, 'is_user_startable' => true, 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('workflow_versions')->insertGetId(['workflow_definition_id' => $definition, 'version_number' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ($fixture['steps'] as $step) {
            foreach (['department_id', 'fallback_department_id'] as $key) {
                if (isset($step['assignment_config'][$key])) {
                    $step['assignment_config'][$key] = $this->departments['B'];
                }
            }
            $step['assignment_config'] = json_encode($step['assignment_config']);
            $ids[$step['code']] = DB::table('workflow_steps')->insertGetId($step + ['workflow_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($fixture['transitions'] as $transition) {
            $transition['from_step_id'] = $ids[$transition['from']];
            $transition['to_step_id'] = $ids[$transition['to']];
            unset($transition['from'], $transition['to']);
            DB::table('workflow_transitions')->insert($transition + ['workflow_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        }
        $engine = app(WorkflowEngine::class);
        $instance = $engine->start($this->people['employee'], 'MAINTENANCE', ['title' => 'Synthetic maintenance'], []);
        foreach ([['head', 'executive_director'], ['exec', 'hr_head']] as [$actor, $target]) {
            $transition = DB::table('workflow_transitions')->where('from_step_id', $instance->current_step_id)->where('to_step_id', $ids[$target])->first();
            $engine->act((int) $instance->id, $this->people[$actor], (int) $transition->id, 'Formal approval and referral', [], null, (int) $instance->revision);
            $instance = DB::table('workflow_instances')->find($instance->id);
        }
        self::assertSame('in_progress', $instance->status);
        self::assertSame($ids['hr_head'], (int) $instance->current_step_id);
        self::assertTrue(app(WorkflowInbox::class)->tasksFor($this->people['other'])->contains('id', $instance->id));
        self::assertTrue(DB::table('notifications')->where('notifiable_id', $this->people['other']->id)->where('event_code', 'workflow_task_assigned')->exists());
    }

    #[Test]
    public function correspondence_creation_link_matches_department_membership(): void
    {
        $this->actingAs($this->people['admin'])->get('/correspondence', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.canCreate', false);
        $this->get('/correspondence/create')->assertForbidden();

        $this->actingAs($this->people['employee'])->get('/correspondence', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.canCreate', true);
        $this->get('/correspondence/create', ['X-Inertia' => 'true'])->assertOk();
    }

    #[Test]
    public function scoped_dashboard_tasks_and_search_do_not_count_unrelated_letters(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), []);
        $letter = $this->action($letter, 'employee', 'issue');
        $tracker = app(TransactionTracker::class);
        self::assertSame(1, $tracker->tasksOf($this->people['head'])->filter(fn ($task) => str_starts_with($task['key'], 'correspondence-'))->count());
        self::assertSame(0, $tracker->tasksOf($this->people['other'])->filter(fn ($task) => str_starts_with($task['key'], 'correspondence-'))->count());
        $this->actingAs($this->people['other'])->get('/correspondence?box=all&q=Private', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 0);
        $this->actingAs($this->people['head'])->get('/correspondence/'.$letter->public_id, ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.letter.reference', $letter->reference);
        $this->actingAs($this->people['employee'])->get('/correspondence/create', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('component', 'Correspondence/Form');
    }

    #[Test]
    public function acting_head_has_dated_authority_and_technical_admin_cannot_issue_their_letter(): void
    {
        $role = DB::table('roles')->where('code', 'acting_department_head')->value('id');
        DB::table('department_user_roles')->insert(['user_id' => $this->people['peer']->id, 'department_id' => $this->departments['A'], 'role_id' => $role, 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->payload('peer', 'exec');
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['peer'], $payload, []);
        $this->actingAs($this->people['admin'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['action' => 'issue', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->action($letter, 'peer', 'issue');
        DB::table('department_user_roles')->where('user_id', $this->people['peer']->id)->where('role_id', $role)->update(['starts_on' => today()->subDays(2)->toDateString(), 'ends_on' => today()->subDay()->toDateString()]);
        self::assertFalse(app(CorrespondenceAccess::class)->visible($this->people['peer'])->where('c.id', $letter->id)->exists());
    }

    #[Test]
    public function correction_versions_files_without_replacing_original_bytes(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $this->files(1));
        $letter = $this->action($letter, 'employee', 'issue');
        $original = DB::table('workflow_event_attachments')->first();
        $bytes = Storage::disk('local')->get($original->storage_path);
        app(CorrespondenceRegistry::class)->act($this->people['employee'], $letter->public_id, ['action' => 'correction', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'note' => 'Corrected supporting information', 'replacements' => [$original->public_id]], $this->files(1));
        $new = DB::table('workflow_event_attachments')->orderByDesc('id')->first();
        self::assertSame(2, (int) $new->document_version);
        self::assertSame((int) $original->id, (int) $new->replaces_attachment_id);
        self::assertSame($bytes, Storage::disk('local')->get($original->storage_path));
        $this->actingAs($this->people['head'])->get('/correspondence/files/'.$new->public_id)->assertOk();
    }

    #[Test]
    public function draft_edit_preserves_attachments_and_revalidates_changed_recipient_at_issue(): void
    {
        $registry = app(CorrespondenceRegistry::class);
        $payload = $this->payload();
        $letter = $registry->draft($this->people['employee'], $payload, $this->files(1));
        $payload['revision'] = $letter->revision;
        $payload['subject'] = 'Revised draft';
        $letter = $registry->draft($this->people['employee'], $payload, $this->files(1), $letter->public_id);
        self::assertSame('Revised draft', $letter->subject);
        self::assertSame(2, DB::table('workflow_event_attachments')->count());
        DB::table('users')->where('id', $this->people['head']->id)->update(['is_active' => false]);
        $this->actingAs($this->people['employee'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['action' => 'issue', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        self::assertNull(DB::table('official_correspondences')->value('reference'));
    }

    #[Test]
    public function refusal_requires_reason_and_routes_back_without_marking_execution_complete(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), []);
        $letter = $this->action($letter, 'employee', 'issue');
        $this->actingAs($this->people['head'])->postJson('/correspondence/'.$letter->public_id.'/actions', ['action' => 'refuse', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'recipient_id' => $this->people['employee']->id, 'recipient_department_id' => $this->departments['A']])->assertUnprocessable();
        $letter = $this->action($letter, 'head', 'refuse', 'employee', $this->files(1));
        self::assertSame('processing', $letter->status);
        self::assertSame('open', DB::table('correspondence_tasks')->value('status'));
        self::assertSame((int) $this->people['employee']->id, (int) DB::table('correspondence_tasks')->value('assigned_to'));
    }

    #[Test]
    public function external_incoming_is_archived_for_secretariat_members_without_an_internal_workflow(): void
    {
        $this->actingAs($this->people['employee'])->get('/correspondence/external/create')->assertForbidden();

        $this->actingAs($this->people['archive_clerk'])->post('/correspondence/external', [
            'creation_key' => (string) Str::uuid(),
            'direction' => 'incoming',
            'external_party' => 'جهة خارجية',
            'external_reference' => 'EXT-2026-19',
            'external_date' => '2026-10-25',
            'type_value_id' => $this->value('types', 'letter'),
            'classification_value_id' => $this->value('classifications', 'internal'),
            'priority_value_id' => $this->value('priorities', 'normal'),
            'subject' => 'كتاب وارد للأرشفة',
            'body' => '',
            'attachments' => $this->files(1),
        ])->assertRedirect();

        $letter = DB::table('official_correspondences')->where('subject', 'كتاب وارد للأرشفة')->first();
        self::assertNotNull($letter);
        $this->assertDatabaseHas('official_correspondences', [
            'id' => $letter->id,
            'channel' => 'external',
            'direction' => 'incoming',
            'external_party' => 'جهة خارجية',
            'external_reference' => 'EXT-2026-19',
            'status' => 'archived',
            'recipient_id' => null,
            'recipient_department_id' => null,
            'workflow_instance_id' => null,
        ]);
        self::assertSame(0, DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->count());
        self::assertSame(1, DB::table('workflow_event_attachments')->whereIn('correspondence_action_id',
            DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->select('id'))->count());

        $this->actingAs($this->people['archive_peer'])->get('/correspondence/'.$letter->public_id, ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.letter.channel', 'external')->assertJsonPath('props.allowed', [])
            ->assertJsonPath('props.history.0.action', 'external_register')->assertJsonPath('props.history.0.attachments.0.original_filename', 'document1.txt');
        $this->postJson('/correspondence/'.$letter->public_id.'/actions', [
            'action' => 'close',
            'revision' => $letter->revision,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $attachment = DB::table('workflow_event_attachments')->whereIn('correspondence_action_id',
            DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->select('id'))->value('public_id');
        $this->get('/correspondence/files/'.$attachment)->assertOk();
        $this->actingAs($this->people['employee'])->get('/correspondence/'.$letter->public_id)->assertNotFound();
        $this->get('/correspondence/files/'.$attachment)->assertNotFound();
        $this->get('/correspondence?channel=external&direction=incoming&box=all', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 0)->assertJsonPath('props.counts.external_incoming', 0);
    }

    #[Test]
    public function external_outgoing_is_optional_attachment_archive_and_appears_only_in_its_direction(): void
    {
        $this->actingAs($this->people['archive_clerk'])->post('/correspondence/external', [
            'creation_key' => (string) Str::uuid(),
            'direction' => 'outgoing',
            'external_party' => 'جهة مستلمة',
            'external_reference' => '',
            'external_date' => '2026-10-26',
            'type_value_id' => $this->value('types', 'letter'),
            'classification_value_id' => $this->value('classifications', 'internal'),
            'priority_value_id' => $this->value('priorities', 'normal'),
            'subject' => 'كتاب صادر للأرشفة',
            'body' => null,
            'attachments' => [],
        ])->assertRedirect();

        $letter = DB::table('official_correspondences')->where('subject', 'كتاب صادر للأرشفة')->first();
        self::assertNotNull($letter);
        $this->assertDatabaseHas('official_correspondences', [
            'id' => $letter->id,
            'channel' => 'external',
            'direction' => 'outgoing',
            'external_party' => 'جهة مستلمة',
            'status' => 'archived',
            'reference' => 'AIMS/EXT/OUT/2026/000001',
        ]);
        self::assertSame(0, DB::table('workflow_event_attachments')->whereIn('correspondence_action_id',
            DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->select('id'))->count());

        $this->get('/correspondence?channel=external&direction=outgoing&box=all', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 1)->assertJsonPath('props.counts.external_outgoing', 1)
            ->assertJsonPath('props.counts.external_incoming', 0)->assertJsonPath('props.records.data.0.direction', 'outgoing');
        $this->get('/correspondence?channel=internal&direction=incoming&box=inbox', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 0);
    }

    #[Test]
    public function mailbox_filters_use_current_task_deadlines_and_preserve_received_and_sent_history(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $letter = app(CorrespondenceRegistry::class)->act($this->people['head'], $letter->public_id, [
            'revision' => $letter->revision, 'action' => 'forward', 'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['exec']->id, 'recipient_department_id' => $this->departments['EXEC'],
            'note' => 'Forward for review', 'due_at' => '2026-10-25 12:00:00',
        ]);

        $this->actingAs($this->people['exec'])->get('/correspondence?box=needs_action&overdue=1&unread=1&q=EMPLOYEE&from=2026-10-26&to=2026-10-26&status=processing', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 1)->assertJsonPath('props.records.data.0.due_at', '2026-10-25 12:00:00')
            ->assertJsonPath('props.records.data.0.overdue', true)->assertJsonPath('props.counts.needs_action', 1);
        $this->get('/correspondence?box=needs_action&from=2026-10-27', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 0);
        $this->get('/correspondence?box=needs_action&to=2026-10-26', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 1);
        $this->get('/correspondence?box=needs_action&q=head', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 1);
        $this->actingAs($this->people['head'])->get('/correspondence?box=sent', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 1)->assertJsonPath('props.counts.needs_action', 0)->assertJsonPath('props.counts.inbox', 1);
        $this->actingAs($this->people['other'])->get('/correspondence?box=all', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 0)->assertJsonPath('props.counts.all', 0);
        $this->getJson('/correspondence?from=2026-10-27&to=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    #[Test]
    public function official_print_excludes_private_handoffs_and_requires_separate_appendix_authority(): void
    {
        $payload = $this->payload();
        $payload['body'] = '<script>alert("synthetic")</script>';
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, $this->files(2), null, true);
        $letter = $this->action($letter, 'head', 'forward', 'exec', $this->files(1));

        $response = $this->actingAs($this->people['exec'])->get('/correspondence/'.$letter->public_id.'/print', ['X-Inertia' => 'true']);
        $response->assertOk()->assertJsonPath('component', 'Correspondence/Print')
            ->assertJsonPath('props.document.body', $payload['body'])->assertJsonPath('props.document.issuer', 'employee')
            ->assertJsonCount(2, 'props.files')->assertJsonPath('props.appendix', [])
            ->assertJsonCount(2, 'props.history')->assertJsonMissingPath('props.task')->assertJsonMissingPath('props.letter');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertNull(DB::table('correspondence_tasks')->value('read_at'));
        $this->get('/correspondence/'.$letter->public_id.'/print?appendix=1')->assertForbidden();
        $this->actingAs($this->people['other'])->get('/correspondence/'.$letter->public_id.'/print')->assertNotFound();
        $this->actingAs($this->people['admin'])->get('/correspondence/'.$letter->public_id.'/print?appendix=1', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonCount(3, 'props.appendix');
        self::assertTrue(DB::table('audit_logs')->where('action', 'correspondence.print')->exists());
        self::assertTrue(DB::table('audit_logs')->where('action', 'correspondence.print.attempted')->exists());
    }

    #[Test]
    public function official_print_preserves_full_content_and_scopes_each_transfer_attachment_to_its_history_entry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 14:05:01', config('app.timezone')));
        $longSenderName = str_repeat('موظف ذو اسم عربي طويل ', 8);
        $longRecipientName = str_repeat('رئيس قسم باسم عربي طويل ', 8);
        $longPrinterName = str_repeat('مسؤول الطباعة ', 8);
        $longReferralFilename = 'إحالة-Forwarded-'.str_repeat('LongName', 8).'.txt';
        $longReferralNote = str_repeat('تعليمات إحالة طويلة AIMS-2026-44 ', 80);
        $this->people['employee']->update(['name' => $longSenderName]);
        $this->people['head']->update(['name' => $longRecipientName]);
        $this->people['admin']->update(['name' => $longPrinterName]);
        $payload = $this->payload();
        $payload['letter_date'] = '2026-10-24';
        $payload['body'] = "First paragraph.\n\nSecond paragraph with <script>alert('safe')</script>.\n\n".str_repeat('محتوى أصلي طويل AIMS-2026-44 ', 80);
        $originalAttachments = [
            UploadedFile::fake()->createWithContent('نسخة-Original-report-'.str_repeat('LongName', 8).'.txt', 'Original long filename'),
            UploadedFile::fake()->createWithContent('original-second.txt', 'Original second attachment'),
            UploadedFile::fake()->createWithContent('original-third.txt', 'Original third attachment'),
        ];
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, $originalAttachments, null, true);
        $letter = app(CorrespondenceRegistry::class)->act($this->people['head'], $letter->public_id, [
            'revision' => $letter->revision,
            'action' => 'forward',
            'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['exec']->id,
            'recipient_department_id' => $this->people['exec']->department_id,
            'note' => $longReferralNote,
        ], [UploadedFile::fake()->createWithContent($longReferralFilename, 'First referral attachment')]);
        $letter = app(CorrespondenceRegistry::class)->act($this->people['exec'], $letter->public_id, [
            'revision' => $letter->revision,
            'action' => 'forward',
            'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['secretary']->id,
            'recipient_department_id' => $this->people['secretary']->department_id,
            'note' => "Second referral note.\nKeep both lines.",
        ], [UploadedFile::fake()->createWithContent('second-referral.txt', 'Second referral attachment')]);
        $letter = app(CorrespondenceRegistry::class)->act($this->people['secretary'], $letter->public_id, [
            'revision' => $letter->revision,
            'action' => 'return',
            'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['exec']->id,
            'recipient_department_id' => $this->people['exec']->department_id,
            'note' => 'Return note.',
        ], [UploadedFile::fake()->createWithContent('return-note.txt', 'Return attachment')]);
        $letter = app(CorrespondenceRegistry::class)->act($this->people['exec'], $letter->public_id, [
            'revision' => $letter->revision,
            'action' => 'reply',
            'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['secretary']->id,
            'recipient_department_id' => $this->people['secretary']->department_id,
            'note' => 'Reply note.',
        ]);

        $response = $this->actingAs($this->people['admin'])->get('/correspondence/'.$letter->public_id.'/print?printed_by=Forged&date=1900-01-01&time=00:00:00', ['X-Inertia' => 'true']);
        $response->assertOk()->assertJsonPath('props.document.reference', $letter->reference)
            ->assertJsonPath('props.document.date', '2026-10-24')->assertJsonPath('props.document.sender', $longSenderName)
            ->assertJsonPath('props.document.sender_department', 'A')->assertJsonPath('props.document.recipients.0', $longRecipientName.' — A')
            ->assertJsonPath('props.document.subject', 'Private subject sentinel')->assertJsonPath('props.document.body', $payload['body'])
            ->assertJsonPath('props.printAt.name', $longPrinterName)->assertJsonPath('props.printAt.date', '2026-10-27')
            ->assertJsonPath('props.printAt.time', '14:05:01')->assertJsonCount(3, 'props.files')
            ->assertJsonFragment(['original_filename' => 'نسخة-Original-report-'.str_repeat('LongName', 8).'.txt'])
            ->assertJsonCount(5, 'props.history')->assertJsonPath('props.history.0.action', 'issue')
            ->assertJsonPath('props.history.1.note', $longReferralNote)
            ->assertJsonPath('props.history.1.attachments.0.original_filename', $longReferralFilename)
            ->assertJsonPath('props.history.2.note', "Second referral note.\nKeep both lines.")
            ->assertJsonPath('props.history.2.attachments.0.original_filename', 'second-referral.txt')
            ->assertJsonPath('props.history.3.note', 'Return note.')
            ->assertJsonPath('props.history.3.attachments.0.original_filename', 'return-note.txt')
            ->assertJsonPath('props.history.4.action', 'reply')->assertJsonPath('props.history.4.note', 'Reply note.')
            ->assertJsonCount(0, 'props.history.4.attachments');

        $this->actingAs($this->people['secretary'])->get('/correspondence/'.$letter->public_id.'/print', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonMissing(['note' => 'First referral note.'])
            ->assertJsonPath('props.history.1.note', "Second referral note.\nKeep both lines.")
            ->assertJsonPath('props.history.2.note', 'Return note.')
            ->assertJsonPath('props.history.3.note', 'Reply note.')
            ->assertJsonCount(4, 'props.history');

        $template = file_get_contents(resource_path('js/Pages/Correspondence/Print.vue'));
        self::assertIsString($template);
        self::assertStringContainsString('{{ document.body }}', $template);
        self::assertStringContainsString('{{ entry.note }}', $template);
        self::assertStringNotContainsString('v-html', $template);
        self::assertStringNotContainsString('position: fixed;', $template);
        self::assertStringNotContainsString('bottom: -19mm;', $template);
        self::assertStringContainsString('@bottom-left { content: ${cssString(footer)};', $template);
        self::assertStringContainsString('margin: 14mm 16mm 22mm;', $template);
        self::assertStringContainsString('text-align: left;', $template);
        self::assertStringContainsString('white-space: pre-wrap;', $template);
        self::assertStringContainsString('overflow-wrap: anywhere;', $template);
        self::assertStringContainsString('v-if="index === 0" class="routing-heading"', $template);
        self::assertStringNotContainsString('break-before: page;', $template);
    }

    #[Test]
    public function draft_and_withdrawn_letters_cannot_be_printed_as_issued_documents(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), []);
        $this->actingAs($this->people['employee'])->get('/correspondence/'.$letter->public_id.'/print')->assertUnprocessable();
        $letter = $this->action($letter, 'employee', 'issue');
        $letter = $this->action($letter, 'employee', 'withdraw');
        $this->get('/correspondence/'.$letter->public_id.'/print')->assertUnprocessable();
    }

    #[Test]
    public function expired_assignments_and_cancelled_tasks_are_absent_from_personal_counters(): void
    {
        $this->freezeTime();
        $payload = $this->payload();
        $payload['due_at'] = now()->subHour()->toDateTimeString();
        app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, [], null, true);
        $summary = app(PersonalWorkspace::class)->correspondence($this->people['head']);
        self::assertSame([1, 1, 1], [$summary['pending'], $summary['unread'], $summary['overdue']]);
        $request = Request::create('/correspondence');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn (): User => $this->people['head']);
        self::assertSame(1, (new HandleInertiaRequests)->share($request)['workCounters']()['correspondence']);

        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['ends_on' => today()->subDay()->toDateString()]);
        $summary = app(PersonalWorkspace::class)->correspondence($this->people['head']);
        self::assertSame([0, 0, 0], [$summary['pending'], $summary['unread'], $summary['overdue']]);
        self::assertSame([], $summary['items']);
        self::assertSame(0, (new HandleInertiaRequests)->share($request)['workCounters']()['correspondence']);
        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['ends_on' => null]);
        DB::table('correspondence_tasks')->update(['status' => 'cancelled']);
        $summary = app(PersonalWorkspace::class)->correspondence($this->people['head']);
        self::assertSame([0, 0, 0], [$summary['pending'], $summary['unread'], $summary['overdue']]);
    }

    #[Test]
    public function one_step_send_and_return_preserve_multiple_attachments_and_read_does_not_complete(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->people['employee'])->post('/correspondence', $payload + ['send' => true, 'attachments' => $this->files(2)])
            ->assertRedirect();
        $letter = DB::table('official_correspondences')->first();
        self::assertSame('issued', $letter->status);
        $this->actingAs($this->people['head'])->get('/correspondence/'.$letter->public_id, ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.allowed', fn (array $actions): bool => in_array('return', $actions, true) && in_array('refuse', $actions, true));
        self::assertSame('open', DB::table('correspondence_tasks')->value('status'));
        self::assertNull(DB::table('correspondence_tasks')->value('completed_at'));
        $letter = $this->action($letter, 'head', 'return', 'employee', $this->files(2));
        self::assertSame('processing', $letter->status);
        self::assertSame(4, DB::table('workflow_event_attachments')->count());
        self::assertCount(4, Storage::disk('local')->allFiles());
        self::assertSame((int) $this->people['employee']->id, (int) DB::table('correspondence_tasks')->value('assigned_to'));
    }

    #[Test]
    public function reassigned_staff_cannot_use_stale_home_department_to_keep_mailbox_or_files(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $this->files(1), null, true);
        $attachment = DB::table('workflow_event_attachments')->value('public_id');
        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['department_id' => $this->departments['B']]);

        self::assertFalse(app(CorrespondenceAccess::class)->member($this->people['head'], $this->departments['A']));
        $this->actingAs($this->people['head'])->get('/correspondence/'.$letter->public_id)->assertNotFound();
        $this->get('/correspondence/files/'.$attachment)->assertNotFound();
        $this->get('/correspondence?box=needs_action', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.records.total', 0);
        self::assertFalse(app(CorrespondenceRegistry::class)->markOpened($this->people['head'], $letter));
        self::assertNull(DB::table('correspondence_tasks')->value('read_at'));
    }

    #[Test]
    public function overdue_reminders_are_private_deduplicated_and_stop_after_assignment_expiry(): void
    {
        $this->freezeTime();
        $payload = $this->payload();
        $payload['due_at'] = now()->subHour()->toDateTimeString();
        app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, [], null, true);

        $this->artisan('correspondence:reconcile')->assertSuccessful();
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        self::assertSame(2, DB::table('notifications')->count());
        $overdue = DB::table('notifications')->where('dedupe_key', 'like', '%:overdue:%')->value('data');
        self::assertSame('مراسلة رسمية تجاوزت موعد المتابعة', json_decode($overdue, true)['title']);
        self::assertStringNotContainsString($payload['subject'], $overdue);
        DB::table('department_user_roles')->where('user_id', $this->people['head']->id)->update(['ends_on' => today()->subDay()->toDateString()]);
        DB::table('notifications')->delete();
        $this->artisan('correspondence:reconcile')->assertFailed();
        self::assertSame(0, DB::table('notifications')->count());
        self::assertSame('open', DB::table('correspondence_tasks')->value('status'));
    }

    #[Test]
    public function mailbox_pagination_preserves_filters_and_never_counts_another_departments_mail(): void
    {
        $registry = app(CorrespondenceRegistry::class);
        for ($index = 0; $index < 26; $index++) {
            $registry->draft($this->people['employee'], $this->payload(), [], null, true);
        }
        $this->actingAs($this->people['head'])->get('/correspondence?box=needs_action&q=Private&page=2', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 26)->assertJsonCount(1, 'props.records.data')
            ->assertJsonPath('props.records.current_page', 2)->assertJsonPath('props.counts.needs_action', 26)
            ->assertJsonPath('props.records.prev_page_url', fn (string $url): bool => str_contains($url, 'box=needs_action') && str_contains($url, 'q=Private'));
        $this->actingAs($this->people['other'])->get('/correspondence?box=needs_action&q=Private&page=2', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.records.total', 0);
    }

    #[Test]
    public function executive_cannot_reopen_own_letter_and_reassignment_cannot_expand_confidential_access(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['exec'], $this->payload('exec', 'head'), [], null, true);
        $this->actingAs($this->people['head'])->postJson('/correspondence/'.$letter->public_id.'/actions', [
            'action' => 'reassign', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['peer']->id, 'recipient_department_id' => $this->departments['A'],
            'due_at' => now()->addDay()->toDateTimeString(), 'note' => 'Executive assignment cannot be delegated by a head',
        ])->assertForbidden();
        $letter = $this->action($letter, 'head', 'complete');
        $payload = ['action' => 'reopen', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['head']->id, 'recipient_department_id' => $this->departments['A'], 'due_at' => now()->addDay()->toDateTimeString(), 'note' => 'Self authorization denied'];
        $this->actingAs($this->people['exec'])->postJson('/correspondence/'.$letter->public_id.'/actions', $payload)->assertForbidden();
        $secret = $this->payload();
        $secret['classification_value_id'] = $this->value('classifications', 'confidential');
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $secret, [], null, true);
        $this->actingAs($this->people['head'])->postJson('/correspondence/'.$letter->public_id.'/actions', [
            ...$payload, 'action' => 'reassign', 'revision' => $letter->revision, 'recipient_id' => $this->people['peer']->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('correspondence_tasks', ['correspondence_id' => $letter->id, 'assigned_to' => $this->people['head']->id]);
    }

    #[Test]
    public function returned_forwarded_and_overdue_boxes_follow_authorized_handoffs(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $letter = $this->action($letter, 'head', 'forward', 'peer');
        $letter = $this->action($letter, 'peer', 'return', 'head');
        DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->update(['due_at' => now()->subHour()]);
        foreach (['forwarded', 'returned', 'overdue'] as $box) {
            $this->actingAs($this->people['head'])->getJson('/correspondence?box='.$box, ['X-Inertia' => 'true'])
                ->assertOk()->assertJsonPath('props.records.total', 1)->assertJsonPath('props.records.data.0.public_id', $letter->public_id);
            $this->actingAs($this->people['other'])->getJson('/correspondence?box='.$box, ['X-Inertia' => 'true'])
                ->assertOk()->assertJsonPath('props.records.total', 0);
        }
    }

    #[Test]
    public function approved_escalation_is_deduplicated_and_never_grants_executive_visibility(): void
    {
        $this->freezeTime();
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['head'], $this->payload('head', 'employee'), [], null, true);
        DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->update(['due_at' => now()->subHours(8)]);
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        self::assertSame(0, DB::table('notifications')->where('dedupe_key', 'like', '%:escalation:%')->count());
        $policy = ['kind' => 'escalation', 'starts_on' => today()->toDateString(), 'is_active' => true,
            'approval_reference' => 'Synthetic test authorization', 'head_after_hours' => 2, 'executive_after_hours' => 4];
        $this->actingAs($this->people['head'])->postJson('/correspondence/policies', $policy)->assertForbidden();
        $this->actingAs($this->people['admin'])->post('/correspondence/policies', $policy)->assertRedirect();
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        $this->artisan('correspondence:reconcile')->assertSuccessful();
        $notifications = DB::table('notifications')->where('dedupe_key', 'like', '%:escalation:%')->get();
        self::assertCount(1, $notifications);
        self::assertSame((string) $this->people['head']->id, (string) $notifications[0]->notifiable_id);
        self::assertStringNotContainsString('Private subject sentinel', $notifications[0]->data);
        self::assertFalse(app(CorrespondenceAccess::class)->visible($this->people['exec'])->where('c.id', $letter->id)->exists());
    }

    #[Test]
    public function attachment_replacement_rejects_branching_from_a_superseded_version(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), $this->files(1), null, true);
        $original = DB::table('workflow_event_attachments')->latest('id')->first();
        $payload = ['action' => 'correction', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'note' => 'Updated attachment', 'replacements' => [$original->public_id]];
        $letter = app(CorrespondenceRegistry::class)->act($this->people['employee'], $letter->public_id, $payload, $this->files(1));
        $count = DB::table('workflow_event_attachments')->count();
        $this->actingAs($this->people['employee'])->postJson('/correspondence/'.$letter->public_id.'/actions', [
            ...$payload, 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'attachments' => $this->files(1),
        ])->assertConflict();
        self::assertSame($count, DB::table('workflow_event_attachments')->count());
        self::assertSame(1, DB::table('workflow_event_attachments')->where('replaces_attachment_id', $original->id)->count());
    }

    #[Test]
    public function numbering_uses_issue_year_and_rolls_back_failed_allocations(): void
    {
        $this->travelTo(Carbon::parse('2030-12-31 23:59:59', config('app.timezone')));
        $first = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        self::assertStringContainsString('/2030-', $first->reference);
        self::assertStringEndsWith('/000001', $first->reference);
        $this->travelTo(Carbon::parse('2031-01-01 00:00:01', config('app.timezone')));
        $payload = $this->payload();
        $payload['letter_date'] = '2030-12-31';
        DB::beginTransaction();
        $discarded = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, [], null, true);
        DB::rollBack();
        $second = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $payload, [], null, true);
        self::assertStringContainsString('/2031-', $second->reference);
        self::assertStringEndsWith('/000001', $second->reference);
        self::assertSame($discarded->reference, $second->reference);
        $this->assertDatabaseHas('official_correspondences', ['id' => $first->id, 'reference' => $first->reference]);
    }

    #[Test]
    public function completion_accepts_private_evidence_and_author_can_archive_from_the_screen(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $this->actingAs($this->people['head'])->post('/correspondence/'.$letter->public_id.'/actions', [
            'action' => 'complete', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'note' => 'Execution evidence', 'attachments' => $this->files(2),
        ])->assertRedirect();
        $this->assertDatabaseHas('official_correspondences', ['id' => $letter->id, 'status' => 'closed', 'body' => 'Immutable original text']);
        $files = DB::table('workflow_event_attachments as f')->join('correspondence_actions as a', 'a.id', '=', 'f.correspondence_action_id')
            ->where('a.correspondence_id', $letter->id)->where('a.action', 'complete')->pluck('f.public_id');
        self::assertCount(2, $files);
        $this->actingAs($this->people['peer'])->get('/correspondence/files/'.$files[0])->assertNotFound();
        $this->actingAs($this->people['employee'])->get('/correspondence/files/'.$files[0])->assertOk();
        $this->actingAs($this->people['employee'])->getJson('/correspondence/'.$letter->public_id, ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonPath('props.allowed', ['archive']);
        $letter = DB::table('official_correspondences')->find($letter->id);
        $this->action($letter, 'employee', 'archive');
        $this->assertDatabaseHas('official_correspondences', ['id' => $letter->id, 'status' => 'archived']);
    }

    #[Test]
    public function executive_reopening_requires_reason_preserves_history_and_hands_off_once(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $letter = $this->action($letter, 'head', 'forward', 'exec');
        $letter = $this->action($letter, 'exec', 'complete');
        $history = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->count();
        $payload = ['action' => 'reopen', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['head']->id, 'recipient_department_id' => $this->departments['A'], 'due_at' => now()->addDay()->toDateTimeString(), 'note' => 'Correction required'];
        foreach (['employee', 'head', 'admin'] as $person) {
            $this->actingAs($this->people[$person])->postJson('/correspondence/'.$letter->public_id.'/actions', $payload)->assertForbidden();
        }
        $this->actingAs($this->people['exec'])->postJson('/correspondence/'.$letter->public_id.'/actions', [...$payload, 'note' => ''])->assertUnprocessable();
        $this->post('/correspondence/'.$letter->public_id.'/actions', $payload)->assertRedirect();
        $this->post('/correspondence/'.$letter->public_id.'/actions', $payload)->assertRedirect();
        $this->assertDatabaseHas('official_correspondences', ['id' => $letter->id, 'status' => 'processing', 'reference' => $letter->reference, 'closed_at' => null]);
        $this->assertDatabaseHas('correspondence_tasks', ['correspondence_id' => $letter->id, 'assigned_to' => $this->people['head']->id, 'status' => 'open']);
        self::assertSame($history + 1, DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->count());
        $event = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->where('action', 'reopen')->first();
        self::assertSame('closed', json_decode($event->metadata, true)['previous_status']);
        self::assertTrue(app(CorrespondenceAccess::class)->mailbox($this->people['head'], 'needs_action')->where('c.id', $letter->id)->exists());
    }

    #[Test]
    public function reassignment_requires_authority_current_grant_and_fresh_revision(): void
    {
        $letter = app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $payload = ['action' => 'reassign', 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(),
            'recipient_id' => $this->people['peer']->id, 'recipient_department_id' => $this->departments['A'], 'due_at' => now()->addDay()->toDateTimeString(), 'note' => 'Delegated within department'];
        $this->actingAs($this->people['employee'])->postJson('/correspondence/'.$letter->public_id.'/actions', $payload)->assertForbidden();
        $this->actingAs($this->people['admin'])->postJson('/correspondence/'.$letter->public_id.'/actions', $payload)->assertForbidden();
        $this->actingAs($this->people['head'])->postJson('/correspondence/'.$letter->public_id.'/actions', [...$payload, 'recipient_id' => $this->people['other']->id, 'recipient_department_id' => $this->departments['B']])->assertForbidden();
        $this->post('/correspondence/'.$letter->public_id.'/actions', $payload)->assertRedirect();
        $this->postJson('/correspondence/'.$letter->public_id.'/actions', [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseHas('correspondence_tasks', ['correspondence_id' => $letter->id, 'assigned_to' => $this->people['peer']->id, 'status' => 'open']);
        self::assertSame(1, DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->count());
        self::assertTrue(app(CorrespondenceAccess::class)->mailbox($this->people['peer'], 'needs_action')->where('c.id', $letter->id)->exists());
        self::assertFalse(app(CorrespondenceAccess::class)->mailbox($this->people['head'], 'needs_action')->where('c.id', $letter->id)->exists());
        $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first();
        DB::table('correspondence_access')->where('action_id', $task->action_id)->where('user_id', $this->people['peer']->id)->update(['valid_until' => today()->subDay()]);
        $letter = DB::table('official_correspondences')->find($letter->id);
        $this->postJson('/correspondence/'.$letter->public_id.'/actions', [...$payload, 'revision' => $letter->revision, 'idempotency_key' => (string) Str::uuid(), 'recipient_id' => $this->people['employee']->id])->assertForbidden();
    }

    #[Test]
    public function correspondence_inbox_runs_with_shared_navigation_middleware(): void
    {
        /** The isolated fixture predates the additive navigation migrations (2026_11_17 / 2026_11_19). */
        foreach (['route_params', 'audience_roles'] as $column) {
            if (! Schema::hasColumn('navigation_items', $column)) {
                Schema::table('navigation_items', fn (Blueprint $table) => $table->jsonb($column)->nullable());
            }
        }
        $this->withMiddleware(HandleInertiaRequests::class);
        app(CorrespondenceRegistry::class)->draft($this->people['employee'], $this->payload(), [], null, true);
        $this->actingAs($this->people['head'])->getJson('/correspondence?box=needs_action', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/correspondence')),
        ])
            ->assertOk()->assertJsonPath('component', 'Correspondence/Index')
            ->assertJsonPath('props.workCounters.correspondence', 1)->assertJsonPath('props.auth.user.id', $this->people['head']->id);
    }

    #[Test]
    public function concurrent_number_allocations_serialize_the_same_authority_and_type_sequence(): void
    {
        $department = random_int(1000000000, 2000000000);
        $scope = now()->year.'-'.$department.'-2';
        $connection = new \PDO('pgsql:host=127.0.0.1;port='.config('database.connections.pgsql.port').';dbname=aims_correspondence_test_20261022', 'kpitest', '');
        self::assertSame('aims_correspondence_test_20261022', $connection->query('select current_database()')->fetchColumn());
        $connection->beginTransaction();
        $insert = $connection->prepare('insert into correspondence_sequences (scope, last_number) values (?, 0)');
        $insert->execute([$scope]);
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => [
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => (int) $argv[3],
    'database' => 'aims_correspondence_test_20261022', 'username' => 'kpitest', 'password' => '',
    'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
]]);
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'aims_correspondence_test_20261022') {
    exit(2);
}
$registry = app(App\Services\Correspondence\CorrespondenceRegistry::class);
$method = new ReflectionMethod($registry, 'number');
fwrite(STDOUT, "READY\n");
$number = Illuminate\Support\Facades\DB::transaction(fn () => $method->invoke($registry, (object) ['department_id' => (int) $argv[2], 'type_value_id' => 2]));
fwrite(STDOUT, $number."\n");
PHP;
        $workers = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $department, (string) config('database.connections.pgsql.port')], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array']);
                $worker->setTimeout(20);
                $workers[] = $worker;
                $worker->start();
                self::assertTrue($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')));
            }
            $connection->commit();
            $numbers = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $numbers[] = end($lines);
            }
            sort($numbers);
            self::assertSame(['AIMS/'.$scope.'/000001', 'AIMS/'.$scope.'/000002'], $numbers);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $cleanup = $connection->prepare('delete from correspondence_sequences where scope = ?');
            $cleanup->execute([$scope]);
        }
    }
}
