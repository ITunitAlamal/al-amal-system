<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR staff and the HR head's review of employee files (approved HR requirements §7.1, §11, §13; TRANSFORM §3, §8).
 *
 * - Permission `hr.files.prepare` and the role «موظف الموارد البشرية» (hr_staff) that carries it. A holder assigned in
 *   the HR department (or one of its units) opens the employee files, prepares a new file or a change to one and
 *   attaches ordinary and identity documents; nothing they prepare takes effect until the appointed HR head approves
 *   it. No user is assigned here: the system administrator assigns the role per person.
 * - `employee_file_changes`: one prepared new file or change, with the submitted data, the values it replaces (to
 *   refuse an approval over data changed since), who prepared it and who approved or returned it. A change is
 *   approved, returned with a note, or withdrawn by its preparer; nobody decides their own change (CHECK).
 * - Two notification types: to the HR head when a change waits, to the preparer when it is decided.
 *
 * Safe to run twice; no business rows are changed. Pay and bank changes stay on EMPLOYEE_PAY_CHANGE.
 */
return new class extends Migration
{
    private const PERMISSION = ['hr.files.prepare', 'إعداد ملفات الموظفين وتعديلاتها لاعتماد رئيس الموارد البشرية'];

    private const ROLE = ['hr_staff', 'موظف الموارد البشرية'];

    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['employee_file_change_submitted', 'ملف موظف أو تعديل عليه بانتظار اعتمادك', 'لرئيس الموارد البشرية عندما يرسل موظف الموارد البشرية ملفًا جديدًا أو تعديلًا على ملف للاعتماد.'],
        ['employee_file_change_decided', 'قرار ملف الموظف الذي أعددته', 'لموظف الموارد البشرية عندما يعتمد رئيس القسم الملف أو التعديل الذي أعده أو يعيده للاستكمال.'],
    ];

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore(['code' => self::PERMISSION[0], 'name' => self::PERMISSION[1], 'created_at' => $now, 'updated_at' => $now]);
        $createdRole = ! DB::table('roles')->where('code', self::ROLE[0])->exists();
        DB::table('roles')->insertOrIgnore(['code' => self::ROLE[0], 'name' => self::ROLE[1], 'created_at' => $now, 'updated_at' => $now]);
        $roleId = (int) DB::table('roles')->where('code', self::ROLE[0])->value('id');
        $permissionId = (int) DB::table('permissions')->where('code', self::PERMISSION[0])->value('id');
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);

        if (! Schema::hasTable('employee_file_changes')) {
            Schema::create('employee_file_changes', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 10);
                $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
                $table->string('employee_number', 50)->nullable();
                $table->jsonb('payload');
                $table->jsonb('before')->nullable();
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('revision')->default(1);
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('submitted_at');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();
                $table->index(['status', 'submitted_at']);
                $table->index(['prepared_by', 'status']);
                $table->index('employee_id');
            });
        }
        DB::statement('ALTER TABLE employee_file_changes DROP CONSTRAINT IF EXISTS employee_file_changes_rules');
        DB::statement("ALTER TABLE employee_file_changes ADD CONSTRAINT employee_file_changes_rules CHECK (
            kind IN ('create', 'update')
            AND status IN ('pending', 'returned', 'approved', 'withdrawn')
            AND (kind = 'create' OR employee_id IS NOT NULL)
            AND (kind = 'update' OR employee_number IS NOT NULL)
            AND (reviewed_by IS NULL OR reviewed_by <> prepared_by)
            AND (status NOT IN ('approved', 'returned') OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))
            AND (status <> 'returned' OR length(btrim(coalesce(review_note, ''))) >= 3)
            AND (status <> 'approved' OR employee_id IS NOT NULL))");
        // One open change per file, and one open new file per employee number.
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS employee_file_changes_open_update ON employee_file_changes (employee_id) WHERE kind = 'update' AND status IN ('pending', 'returned')");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS employee_file_changes_open_create ON employee_file_changes (employee_number) WHERE kind = 'create' AND status IN ('pending', 'returned')");

        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if ($createdRole) {
            DB::table('audit_logs')->insert([
                'actor_id' => null, 'action' => 'role.created', 'subject_type' => 'roles', 'subject_id' => (string) $roleId,
                'metadata' => json_encode(['code' => self::ROLE[0], 'name' => self::ROLE[1], 'permissions' => [self::PERMISSION[0]],
                    'source' => '2026_11_30_000035_hr_staff_file_review'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Prepared changes and their decisions are part of the employee file's history and are kept; the role may still
     * be assigned to people.
     */
    public function down(): void
    {
        throw new RuntimeException('Employee file changes are part of the employee file history and are kept.');
    }
};
