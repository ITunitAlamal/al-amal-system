<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CorrespondenceInboxTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('session.connection', 'sqlite');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('department_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->default('A');
            $table->string('node_type')->default('department');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('department_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });
        Schema::create('official_correspondences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('subject');
            $table->string('reference');
            $table->string('channel')->default('internal');
            $table->unsignedBigInteger('department_id');
        });
        Schema::create('correspondence_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('correspondence_id');
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('actor_id');
            $table->timestamps();
        });
        Schema::create('correspondence_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('department_id');
            $table->string('authority_role')->default('staff');
            $table->date('valid_until')->nullable();
        });
        Schema::create('correspondence_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('correspondence_id')->unique();
            $table->unsignedBigInteger('action_id');
            $table->unsignedBigInteger('assigned_to');
            $table->string('status')->default('open');
            $table->timestamp('read_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('event_code')->nullable();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function recipient(): User
    {
        $department = DB::table('departments')->insertGetId([]);
        $role = DB::table('roles')->insertGetId(['code' => 'department_employee']);
        $user = User::factory()->create(['department_id' => $department, 'is_active' => true, 'must_change_password' => false]);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'department_id' => $department]);

        return $user;
    }

    private function deliver(User $recipient, User $sender, ?int $letter = null, string $channel = 'internal'): array
    {
        $letter ??= DB::table('official_correspondences')->insertGetId([
            'public_id' => (string) Str::uuid(), 'subject' => 'رسالة خاصة', 'reference' => 'MAIL-1',
            'department_id' => $sender->department_id, 'channel' => $channel,
        ]);
        $action = DB::table('correspondence_actions')->insertGetId([
            'correspondence_id' => $letter, 'recipient_id' => $recipient->id, 'actor_id' => $sender->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('correspondence_access')->insert([
            'action_id' => $action, 'user_id' => $recipient->id, 'department_id' => $recipient->department_id,
        ]);
        DB::table('correspondence_tasks')->updateOrInsert(['correspondence_id' => $letter], [
            'action_id' => $action, 'assigned_to' => $recipient->id, 'status' => 'open', 'read_at' => null,
        ]);

        return [$letter, $action];
    }

    public function test_recent_mail_requires_authentication_and_an_active_account(): void
    {
        $this->getJson('/correspondence/recent')->assertUnauthorized();
        $user = $this->recipient();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->getJson('/correspondence/recent')->assertForbidden();
    }

    public function test_inbox_only_returns_internal_deliveries_to_the_current_user(): void
    {
        $recipient = $this->recipient();
        $sender = $this->recipient();
        [$letter, $action] = $this->deliver($recipient, $sender);
        $this->deliver($sender, $recipient);
        $this->deliver($recipient, $sender, channel: 'external');

        $this->actingAs($recipient)->getJson('/correspondence/recent?user_id='.$sender->id)
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('unread', 1)
            ->assertJsonPath('items.0.id', $action)->assertJsonPath('items.0.read', false)
            ->assertJsonPath('items.0.body', $sender->name.' — MAIL-1')
            ->assertJsonPath('items.0.url', '/correspondence/'.DB::table('official_correspondences')->where('id', $letter)->value('public_id'));
        $this->assertDatabaseHas('correspondence_tasks', ['correspondence_id' => $letter, 'read_at' => null]);
    }

    public function test_reading_and_cancelling_tasks_updates_the_unread_badge(): void
    {
        $recipient = $this->recipient();
        $sender = $this->recipient();
        [$read] = $this->deliver($recipient, $sender);
        [$cancelled] = $this->deliver($recipient, $sender);
        DB::table('correspondence_tasks')->where('correspondence_id', $read)->update(['read_at' => now()]);
        DB::table('correspondence_tasks')->where('correspondence_id', $cancelled)->update(['status' => 'cancelled']);

        $this->actingAs($recipient)->getJson('/correspondence/recent')->assertOk()
            ->assertJsonCount(2, 'items')->assertJsonPath('unread', 0)
            ->assertJsonPath('items.0.read', true)->assertJsonPath('items.1.read', true);
    }

    public function test_returned_mail_uses_a_new_delivery_id_without_duplicate_letters(): void
    {
        $recipient = $this->recipient();
        $sender = $this->recipient();
        [$letter] = $this->deliver($recipient, $sender);
        $this->deliver($sender, $recipient, $letter);
        [, $latestAction] = $this->deliver($recipient, $sender, $letter);

        $this->actingAs($recipient)->getJson('/correspondence/recent')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('unread', 1)->assertJsonPath('items.0.id', $latestAction);
    }

    public function test_expired_access_and_expired_roles_hide_mail(): void
    {
        $this->freezeTime();
        $recipient = $this->recipient();
        $sender = $this->recipient();
        $this->deliver($recipient, $sender);
        $this->deliver($sender, $recipient);
        DB::table('correspondence_access')->where('user_id', $recipient->id)->update(['valid_until' => today()->subDay()]);
        DB::table('department_user_roles')->where('user_id', $sender->id)->update(['ends_on' => today()->subDay()]);

        $this->actingAs($recipient)->getJson('/correspondence/recent')->assertOk()->assertExactJson(['items' => [], 'unread' => 0]);
        $this->actingAs($sender)->getJson('/correspondence/recent')->assertOk()->assertExactJson(['items' => [], 'unread' => 0]);
    }

    public function test_inbox_limits_previews_but_counts_all_unread_letters(): void
    {
        $recipient = $this->recipient();
        $sender = $this->recipient();
        for ($index = 0; $index < 10; $index++) {
            [, $latest] = $this->deliver($recipient, $sender);
        }

        $this->actingAs($recipient)->getJson('/correspondence/recent')->assertOk()
            ->assertJsonCount(8, 'items')->assertJsonPath('unread', 10)->assertJsonPath('items.0.id', $latest);
    }

    public function test_bell_and_mark_all_read_exclude_correspondence_and_other_users(): void
    {
        $recipient = $this->recipient();
        $other = $this->recipient();
        foreach ([[$recipient, 'correspondence.assigned'], [$recipient, 'workflow_task_assigned'], [$other, 'workflow_task_assigned']] as [$user, $event]) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(), 'type' => 'aims', 'notifiable_type' => User::class,
                'notifiable_id' => $user->id, 'event_code' => $event, 'data' => json_encode(['title' => $event]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($recipient)->getJson('/notifications/recent')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('unread', 1)->assertJsonPath('items.0.event', 'workflow_task_assigned');
        $this->post('/notifications/read-all')->assertRedirect();
        $this->getJson('/notifications/recent')->assertJsonPath('unread', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id, 'event_code' => 'correspondence.assigned', 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $other->id, 'read_at' => null]);
        self::assertSame(1, app(NotificationCenter::class)->visible($recipient)->count());
    }
}
