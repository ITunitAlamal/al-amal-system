<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts reorganisation (owner decisions 2026-11-01), additions only:
 * - dated finance grants: start/end dates on assignments and per-user permissions, plus a request register
 *   (`finance_access_grants`) holding head and administrator grants, the executive director's decision on sensitive
 *   ones and on segregation-of-duties exceptions, and the history;
 * - the finalized payroll recorded from the external accounting software (record by one person, review and publish
 *   by another), payslips issued from published runs only, and the employee's payslip requests;
 * - new permissions: head monitoring, staff assignment, payroll record/publish; two role templates.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.monitor' => 'متابعة وحدات الحسابات كلها (اطلاع فقط)',
        'finance.staff.assign' => 'إسناد اختصاصات موظفي الحسابات وصلاحياتهم',
        'finance.payroll.record' => 'إدخال المسير المعتمد من البرنامج المحاسبي',
        'finance.payroll.publish' => 'مراجعة المسير ونشر قسائم الرواتب',
    ];

    /** role code => [Arabic name, permissions] */
    private const ROLES = [
        'finance_payroll' => ['مسؤول الرواتب (وحدة الحسابات)', ['finance.view', 'finance.payroll.record', 'finance.reports.view']],
        'finance_auditor' => ['مدقق مالي (اطلاع فقط)', ['finance.view', 'finance.monitor', 'finance.reports.view']],
    ];

    private const EVENTS = [
        'finance_access_grant_pending' => ['صلاحية مالية بانتظار قرارك', 'للمدير التنفيذي عند طلب إسناد صلاحية مالية حساسة أو استثناء من فصل المهام.'],
        'finance_access_grant_decided' => ['قرار على طلب صلاحية مالية', 'لمن طلب الإسناد عند اعتماده أو رفضه.'],
        'finance_access_changed' => ['تغيّرت صلاحياتك في الحسابات', 'للموظف عند إسناد اختصاص أو صلاحية مالية له أو إنهائها.'],
        'finance_payroll_review' => ['مسير رواتب بانتظار المراجعة والنشر', 'لمن يملك نشر القسائم عند إدخال مسير جديد.'],
        'finance_payslip_requested' => ['طلب قسيمة راتب بانتظار المسير', 'لمسؤول الرواتب عند طلب قسيمة شهر لم يُنشر مسيره.'],
        'finance_payslip_ready' => ['قسيمة راتبك جاهزة', 'للموظف عند توفر قسيمة الشهر الذي طلبه.'],
    ];

    public function up(): void
    {
        Schema::create('finance_access_grants', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->string('kind', 20);
            $t->string('code', 80);
            $t->boolean('is_primary')->default(false);
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->text('reason');
            $t->boolean('sensitive')->default(false);
            $t->boolean('is_exception')->default(false);
            $t->jsonb('conflicts')->nullable();
            $t->string('state', 20);
            $t->string('requested_as', 20);
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampTz('applied_at')->nullable();
            $t->foreignId('ended_by')->nullable()->constrained('users');
            $t->timestampTz('ended_at')->nullable();
            $t->text('end_reason')->nullable();
            $t->timestamps();
            $t->index(['state', 'created_at']);
            $t->index(['user_id', 'state']);
        });
        DB::statement("ALTER TABLE finance_access_grants ADD CONSTRAINT finance_access_grants_kind_check CHECK (kind IN ('specialization','permission'))");
        DB::statement("ALTER TABLE finance_access_grants ADD CONSTRAINT finance_access_grants_state_check CHECK (state IN ('pending_approval','applied','rejected','cancelled','ended','expired'))");
        DB::statement("ALTER TABLE finance_access_grants ADD CONSTRAINT finance_access_grants_as_check CHECK (requested_as IN ('head','system_admin'))");
        DB::statement('ALTER TABLE finance_access_grants ADD CONSTRAINT finance_access_grants_dates_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        DB::statement('ALTER TABLE finance_access_grants ADD CONSTRAINT finance_access_grants_exception_check CHECK (NOT is_exception OR valid_until IS NOT NULL)');
        // One open request per person and code: a second identical request is refused instead of piling up.
        DB::statement("CREATE UNIQUE INDEX finance_access_grants_open_uniq ON finance_access_grants (user_id, kind, code) WHERE state = 'pending_approval'");

        Schema::table('finance_staff_assignments', function (Blueprint $t): void {
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->foreignId('grant_id')->nullable()->constrained('finance_access_grants');
        });
        Schema::table('finance_user_permissions', function (Blueprint $t): void {
            $t->date('valid_until')->nullable();
            $t->foreignId('grant_id')->nullable()->constrained('finance_access_grants');
        });

        Schema::table('finance_payroll_batches', function (Blueprint $t): void {
            $t->string('source', 20)->default('aims');
            $t->string('source_reference', 120)->nullable();
            $t->string('source_file_name', 255)->nullable();
            $t->char('source_file_hash', 64)->nullable();
            $t->integer('employee_count')->default(0);
            $t->decimal('gross_total', 18, 2)->nullable();
            $t->decimal('deductions_total', 18, 2)->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users');
            $t->timestampTz('published_at')->nullable();
            $t->foreignId('returned_by')->nullable()->constrained('users');
            $t->text('returned_reason')->nullable();
        });
        DB::statement("ALTER TABLE finance_payroll_batches ADD CONSTRAINT finance_payroll_batches_source_check CHECK (source IN ('aims','external'))");
        DB::statement("ALTER TABLE finance_payroll_batches ADD CONSTRAINT finance_payroll_batches_state_check CHECK (state IN ('draft','pending_approval','approved','published','returned'))");
        // One live run per month and currency; a run returned for correction stays as history beside its replacement.
        DB::statement('ALTER TABLE finance_payroll_batches DROP CONSTRAINT finance_payroll_batches_period_currency_unique');
        DB::statement("CREATE UNIQUE INDEX finance_payroll_batches_live_uniq ON finance_payroll_batches (period, currency) WHERE state <> 'returned'");
        Schema::table('finance_payroll_items', function (Blueprint $t): void {
            $t->jsonb('earnings')->nullable();
            $t->jsonb('deduction_lines')->nullable();
            $t->integer('source_row')->nullable();
        });

        // A published run is final: corrections go into a later adjustment, never into the published figures.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION finance_payroll_published_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE published boolean;
BEGIN
    IF TG_TABLE_NAME = 'finance_payroll_batches' THEN
        IF OLD.state = 'published' AND (TG_OP = 'DELETE' OR NEW.state IS DISTINCT FROM OLD.state OR NEW.period IS DISTINCT FROM OLD.period
            OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.total IS DISTINCT FROM OLD.total OR NEW.gross_total IS DISTINCT FROM OLD.gross_total
            OR NEW.deductions_total IS DISTINCT FROM OLD.deductions_total OR NEW.employee_count IS DISTINCT FROM OLD.employee_count) THEN
            RAISE EXCEPTION 'published payroll runs are final' USING ERRCODE = 'P0001';
        END IF;
    ELSE
        SELECT b.state = 'published' INTO published FROM finance_payroll_batches b WHERE b.id = OLD.batch_id;
        IF published AND (TG_OP = 'DELETE' OR NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
            OR NEW.gross IS DISTINCT FROM OLD.gross OR NEW.deductions IS DISTINCT FROM OLD.deductions OR NEW.net IS DISTINCT FROM OLD.net
            OR NEW.earnings IS DISTINCT FROM OLD.earnings OR NEW.deduction_lines IS DISTINCT FROM OLD.deduction_lines) THEN
            RAISE EXCEPTION 'published payroll items are final' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER finance_payroll_batches_final BEFORE UPDATE OR DELETE ON finance_payroll_batches FOR EACH ROW EXECUTE FUNCTION finance_payroll_published_guard();
CREATE TRIGGER finance_payroll_items_final BEFORE UPDATE OR DELETE ON finance_payroll_items FOR EACH ROW EXECUTE FUNCTION finance_payroll_published_guard();
SQL);

        Schema::create('finance_payslips', function (Blueprint $t): void {
            $t->id();
            $t->string('reference', 32)->unique();
            $t->foreignId('payroll_item_id')->unique()->constrained('finance_payroll_items');
            $t->foreignId('batch_id')->constrained('finance_payroll_batches');
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->char('period', 7);
            $t->char('currency', 3);
            $t->timestampTz('published_at');
            $t->integer('view_count')->default(0);
            $t->timestampTz('first_viewed_at')->nullable();
            $t->timestampTz('last_viewed_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'period']);
            $t->index(['user_id', 'period']);
        });

        Schema::create('finance_payslip_requests', function (Blueprint $t): void {
            $t->id();
            $t->string('reference', 32)->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('employee_id')->nullable()->constrained('employees');
            $t->char('period', 7);
            $t->string('note', 1000)->nullable();
            $t->string('state', 25);
            $t->foreignId('payslip_id')->nullable()->constrained('finance_payslips');
            $t->timestampTz('fulfilled_at')->nullable();
            $t->timestamps();
            // A repeated request for the same month reuses the first one: no duplicate slip, no duplicate notice.
            $t->unique(['user_id', 'period']);
            $t->index(['state', 'period']);
        });
        DB::statement("ALTER TABLE finance_payslip_requests ADD CONSTRAINT finance_payslip_requests_state_check CHECK (state IN ('fulfilled','awaiting_payroll','not_included','no_employee_record'))");

        $now = now();
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (self::ROLES as $code => [$name, $permissions]) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                DB::table('roles')->insert(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Rolling back would erase grant history, recorded payroll or issued payslips: refused once any exist.
        foreach (['finance_access_grants', 'finance_payslips', 'finance_payslip_requests'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has records; this migration cannot be rolled back.");
            }
        }
        if (DB::table('finance_payroll_batches')->where('source', 'external')->exists()) {
            throw new RuntimeException('recorded external payroll exists; this migration cannot be rolled back.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS finance_payroll_batches_final ON finance_payroll_batches; DROP TRIGGER IF EXISTS finance_payroll_items_final ON finance_payroll_items; DROP FUNCTION IF EXISTS finance_payroll_published_guard()');
        Schema::dropIfExists('finance_payslip_requests');
        Schema::dropIfExists('finance_payslips');
        DB::statement('ALTER TABLE finance_payroll_batches DROP CONSTRAINT IF EXISTS finance_payroll_batches_state_check, DROP CONSTRAINT IF EXISTS finance_payroll_batches_source_check');
        DB::statement('DROP INDEX IF EXISTS finance_payroll_batches_live_uniq');
        DB::statement('ALTER TABLE finance_payroll_batches ADD CONSTRAINT finance_payroll_batches_period_currency_unique UNIQUE (period, currency)');
        Schema::table('finance_payroll_items', fn (Blueprint $t) => $t->dropColumn(['earnings', 'deduction_lines', 'source_row']));
        Schema::table('finance_payroll_batches', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('published_by');
            $t->dropConstrainedForeignId('returned_by');
            $t->dropColumn(['source', 'source_reference', 'source_file_name', 'source_file_hash', 'employee_count', 'gross_total', 'deductions_total', 'published_at', 'returned_reason']);
        });
        Schema::table('finance_user_permissions', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('grant_id');
            $t->dropColumn('valid_until');
        });
        Schema::table('finance_staff_assignments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('grant_id');
            $t->dropColumn(['valid_from', 'valid_until']);
        });
        Schema::dropIfExists('finance_access_grants');
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        foreach (array_keys(self::ROLES) as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if ($roleId && ! DB::table('department_user_roles')->where('role_id', $roleId)->exists()) {
                DB::table('permission_role')->where('role_id', $roleId)->delete();
                DB::table('roles')->where('id', $roleId)->delete();
            }
        }
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
