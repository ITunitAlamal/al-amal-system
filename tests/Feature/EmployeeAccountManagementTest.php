<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class EmployeeAccountManagementTest extends TestCase
{
    private User $administrator;

    private User $employee;

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
            $table->string('employee_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('department_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->unsignedBigInteger('department_id')->nullable();
        });
        foreach (['employees', 'employee_activation_codes', 'form_drafts', 'user_notification_preferences'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
                $table->timestamps();
            });
        }
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
        $this->administrator = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->employee = User::factory()->create(['employee_number' => 'EMP-TEST', 'is_active' => true, 'must_change_password' => false]);
        $this->assignRole($this->administrator, 'system_admin');
    }

    private function assignRole(User $user, string $code): void
    {
        $role = DB::table('roles')->insertGetId(['code' => $code]);
        DB::table('department_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
    }

    private function sessionFor(User $user): void
    {
        DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_password_reset_hashes_secret_revokes_credentials_and_does_not_log_password(): void
    {
        $rememberToken = $this->employee->remember_token;
        $this->sessionFor($this->employee);
        $this->sessionFor($this->administrator);
        DB::table('password_reset_tokens')->insert(['email' => $this->employee->email, 'token' => 'old-token']);
        DB::table('employee_activation_codes')->insert(['user_id' => $this->employee->id]);
        $this->actingAs($this->administrator)->put('/admin/employees/'.$this->employee->id.'/password', [
            'password' => 'Temporary-secret-123!', 'password_confirmation' => 'Temporary-secret-123!',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->employee->refresh();
        self::assertTrue(Hash::check('Temporary-secret-123!', $this->employee->password));
        self::assertTrue($this->employee->must_change_password);
        self::assertNotSame($rememberToken, $this->employee->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->employee->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $this->administrator->id]);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('employee_activation_codes', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.password_reset', 'actor_id' => $this->administrator->id]);
        self::assertStringNotContainsString('Temporary-secret-123!', DB::table('audit_logs')->value('metadata'));
    }

    public function test_password_reset_requires_confirmation_and_keeps_inactive_account_inactive(): void
    {
        $original = $this->employee->password;
        $this->actingAs($this->administrator)->putJson('/admin/employees/'.$this->employee->id.'/password', [
            'password' => 'short', 'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        self::assertSame($original, $this->employee->fresh()->password);
        $this->employee->update(['is_active' => false]);
        $this->put('/admin/employees/'.$this->employee->id.'/password', [
            'password' => 'Temporary-secret-123!', 'password_confirmation' => 'Temporary-secret-123!',
        ])->assertRedirect()->assertSessionHasNoErrors();
        self::assertFalse($this->employee->fresh()->is_active);
    }

    public function test_non_administrators_cannot_reset_or_delete_accounts(): void
    {
        $this->actingAs($this->employee)->putJson('/admin/employees/'.$this->administrator->id.'/password', [])->assertForbidden();
        $this->deleteJson('/admin/employees/'.$this->administrator->id, ['confirm' => true])->assertForbidden();
        $this->administrator->update(['is_active' => false]);
        $this->actingAs($this->administrator)->deleteJson('/admin/employees/'.$this->employee->id, ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_accounts_require_delete_confirmation_and_cannot_delete_self_or_protected_roles(): void
    {
        $this->actingAs($this->administrator)->deleteJson('/admin/employees/'.$this->employee->id, [])->assertUnprocessable();
        $this->deleteJson('/admin/employees/'.$this->administrator->id, ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->putJson('/admin/employees/'.$this->administrator->id.'/password', [])->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assignRole($this->employee, 'system_admin');
        $this->employee->update(['is_active' => false]);
        $this->deleteJson('/admin/employees/'.$this->employee->id, ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_deletion_removes_account_and_authentication_data_but_retains_profile(): void
    {
        $this->assignRole($this->employee, 'department_employee');
        $this->sessionFor($this->employee);
        foreach (['employees', 'employee_activation_codes', 'form_drafts', 'user_notification_preferences'] as $table) {
            DB::table($table)->insert(['user_id' => $this->employee->id]);
        }
        DB::table('notifications')->insert(['notifiable_type' => User::class, 'notifiable_id' => $this->employee->id]);
        DB::table('notifications')->insert(['notifiable_type' => 'Guardian', 'notifiable_id' => $this->employee->id]);
        $this->actingAs($this->administrator)->delete('/admin/employees/'.$this->employee->id, ['confirm' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $this->employee->id]);
        $this->assertDatabaseHas('employees', ['user_id' => null]);
        foreach (['employee_activation_codes', 'form_drafts', 'user_notification_preferences', 'sessions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('department_user_roles', ['user_id' => $this->employee->id]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => 'Guardian']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.deleted', 'subject_id' => (string) $this->employee->id]);
    }

    public function test_deletion_blocks_operational_cascades_without_partial_changes(): void
    {
        Schema::create('official_correspondences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
        });
        DB::table('official_correspondences')->insert(['author_id' => $this->employee->id]);
        DB::table('employees')->insert(['user_id' => $this->employee->id]);
        $this->sessionFor($this->employee);
        $this->actingAs($this->administrator)->deleteJson('/admin/employees/'.$this->employee->id, ['confirm' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseHas('users', ['id' => $this->employee->id]);
        $this->assertDatabaseHas('employees', ['user_id' => $this->employee->id]);
        $this->assertDatabaseCount('official_correspondences', 1);
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
