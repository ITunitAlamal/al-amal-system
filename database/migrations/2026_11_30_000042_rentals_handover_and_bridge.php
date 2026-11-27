<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rentals: what reaches Accounts, maintenance feedback, and preparation steps that no longer stall.
 *
 * RA-06. finance_receivables registered by FinanceSourceBridge were register-only. A source module may now
 * cancel one (cancelled_at, cancelled_by, cancel_reason; never cleared again) and adjust it through append-only
 * rows in finance_receivable_adjustments (one row per source reference, e.g. a posted rent settlement). The
 * database refuses UPDATE and DELETE on the adjustments and refuses reopening a cancelled receivable.
 *
 * RA-16. aims_rental_maintenance_links records who bears the cost (institution or tenant) and the accounting
 * reference, when the officer was told the linked maintenance request closed, and when the unit was returned to
 * «شاغرة». New notification event rental_maintenance_closed.
 *
 * RA-17. The descriptions of rental_contract_expiring / rental_contract_expired now name the executive
 * director as an information-only recipient (changed only while they are still the installed text).
 *
 * RA-18. RENTAL_APPLICATION (waitlist), RENTAL_CONTRACT (signing) and RENTAL_TERMINATION (handover) were bound to
 * the requester, so a request stalled when the officer who prepared it left or lost the role. Each gets a new
 * published version where that step is held by the role «مسؤول ملف الإيجارات» (rentals_officer) wherever it is
 * assigned (as RentalsAccess::canManage recognises a preparer), the preparing officer still acting while he holds it (requester_may_act), the head of the assets
 * department as the alternate and supervisor. Cloned from the current version and validated like a manual
 * publish: a problem this patch would introduce stops the migration, one the current version already has does
 * not. Running requests keep the version they started with; an open draft gets the same change.
 *
 * Idempotent: columns, tables, triggers and rows are added only when missing.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000042 (التسليم والعدادات وجسر الحسابات في الإيجارات)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, string> workflow => requester-bound step that becomes the rentals officer's */
    private const STEPS = ['RENTAL_APPLICATION' => 'waitlist', 'RENTAL_CONTRACT' => 'signing', 'RENTAL_TERMINATION' => 'handover'];

    public function up(): void
    {
        $this->receivables();
        $this->maintenanceLinks();
        $this->notifications();
        $assets = DB::table('departments')->where('code', 'ASSETS')->where('is_active', true)->whereNull('deleted_at')->value('id');
        if (! $assets) {
            throw new RuntimeException('قسم الأصول (ASSETS) غير موجود أو غير نشط؛ لا يمكن إسناد مراحل الإعداد في مسارات الإيجار إليه.');
        }
        foreach (self::STEPS as $code => $step) {
            $this->workflow($code, $step, (int) $assets);
        }
    }

    /**
     * The columns, the adjustments table and the triggers stay: they hold Accounts' record of what changed.
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    private function receivables(): void
    {
        $added = ! Schema::hasColumn('finance_receivables', 'cancelled_at');
        if ($added) {
            Schema::table('finance_receivables', function (Blueprint $table): void {
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('cancel_reason')->nullable();
            });
        }
        if (! Schema::hasTable('finance_receivable_adjustments')) {
            $added = true;
            Schema::create('finance_receivable_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('receivable_id')->constrained('finance_receivables')->restrictOnDelete();
                $table->decimal('delta', 18, 2);
                $table->string('source_reference', 150);
                $table->text('reason');
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['receivable_id', 'source_reference'], 'fin_rec_adj_source_uniq');
            });
            DB::statement('ALTER TABLE finance_receivable_adjustments ADD CONSTRAINT finance_receivable_adjustments_delta_check CHECK (delta <> 0)');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION finance_receivable_adjustments_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'finance_receivable_adjustments is append-only';
END;
$$;
DROP TRIGGER IF EXISTS finance_receivable_adjustments_append_only ON finance_receivable_adjustments;
CREATE TRIGGER finance_receivable_adjustments_append_only BEFORE UPDATE OR DELETE ON finance_receivable_adjustments
    FOR EACH ROW EXECUTE FUNCTION finance_receivable_adjustments_append_only();

CREATE OR REPLACE FUNCTION finance_receivables_cancel_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.cancelled_at IS NOT NULL AND (NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at OR NEW.amount <> OLD.amount
        OR NEW.cancel_reason IS DISTINCT FROM OLD.cancel_reason OR NEW.cancelled_by IS DISTINCT FROM OLD.cancelled_by) THEN
        RAISE EXCEPTION 'a cancelled receivable cannot be reopened or changed';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS finance_receivables_cancel_guard ON finance_receivables;
CREATE TRIGGER finance_receivables_cancel_guard BEFORE UPDATE ON finance_receivables
    FOR EACH ROW EXECUTE FUNCTION finance_receivables_cancel_guard();
SQL);
        if ($added) {
            $this->audit('schema.column.added', 'finance_receivables', 0, null, ['columns' => ['cancelled_at', 'cancelled_by', 'cancel_reason'], 'table' => 'finance_receivable_adjustments']);
        }
    }

    private function maintenanceLinks(): void
    {
        if (Schema::hasColumn('aims_rental_maintenance_links', 'cost_bearer')) {
            return;
        }
        Schema::table('aims_rental_maintenance_links', function (Blueprint $table): void {
            $table->string('cost_bearer', 20)->nullable();
            $table->string('cost_reference', 150)->nullable();
            $table->foreignId('cost_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cost_recorded_at')->nullable();
            $table->timestamp('closed_notified_at')->nullable();
            $table->timestamp('unit_released_at')->nullable();
        });
        DB::statement("ALTER TABLE aims_rental_maintenance_links ADD CONSTRAINT aims_rental_maintenance_links_cost_bearer_check
            CHECK (cost_bearer IS NULL OR cost_bearer IN ('institution', 'tenant'))");
        $this->audit('schema.column.added', 'aims_rental_maintenance_links', 0, null,
            ['columns' => ['cost_bearer', 'cost_reference', 'cost_recorded_by', 'cost_recorded_at', 'closed_notified_at', 'unit_released_at']]);
    }

    private function notifications(): void
    {
        DB::table('notification_settings')->insertOrIgnore(['event_code' => 'rental_maintenance_closed', 'label_ar' => 'إغلاق طلب صيانة لعين مؤجرة',
            'description' => 'لمسؤولي ملف الإيجارات عند إغلاق طلب صيانة مرتبط بعين ما زالت «تحت الصيانة»: أعدها شاغرة بنقرة، وسجّل من يتحمل التكلفة.',
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            'rental_contract_expiring' => ['لرئيس قسم الأصول ومسؤولي ملف الإيجارات عند دخول العقد فترة التنبيه المهيأة (افتراضيًا ثلاثة أشهر)، مرة واحدة لكل عقد.',
                'لرئيس قسم الأصول ومسؤولي ملف الإيجارات، وللمدير التنفيذي للعلم، عند دخول العقد فترة التنبيه المهيأة (افتراضيًا ثلاثة أشهر)، مرة واحدة لكل عقد.'],
            'rental_contract_expired' => ['عند انتهاء مدة العقد دون تجديد مفعّل؛ تبقى العين مشغولة حتى محضر الاستلام.',
                'لمسؤولي ملف الإيجارات، وللمدير التنفيذي للعلم، عند انتهاء مدة العقد دون تجديد مفعّل؛ تبقى العين مشغولة حتى محضر الاستلام.'],
        ] as $event => [$installed, $description]) {
            DB::table('notification_settings')->where('event_code', $event)->where('description', $installed)->update(['description' => $description, 'updated_at' => now()]);
        }
    }

    private function workflow(string $code, string $stepCode, int $assetsId): void
    {
        $definition = DB::table('workflow_definitions')->where('code', $code)->first();
        if (! $definition) {
            return;
        }
        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')->orderBy('id')->get() as $draft) {
            $this->patch($code, $draft, $stepCode, $assetsId);
        }
        $published = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->orderByDesc('version_number')->first();
        if (! $published || ! $this->patchable($published, $stepCode)) {
            return;
        }

        $version = $this->cloneVersion($code, $definition, $published);
        $validator = app(WorkflowPublishingService::class);
        $before = $validator->problems((int) $version->id);
        $this->patch($code, $version, $stepCode, $assetsId);
        $introduced = array_values(array_diff($validator->problems((int) $version->id), $before));
        if ($introduced !== []) {
            throw new RuntimeException("تعذر نشر الإصدار الجديد من مسار {$code}: ".implode(' | ', $introduced));
        }

        $now = now();
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', (int) $version->id, ['status' => 'draft', 'retired_versions' => $retired],
            ['status' => 'published', 'workflow' => $code, 'version' => (int) $version->version_number]);

        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')
            ->where('version_number', '<', (int) $version->version_number)->orderBy('version_number')->get() as $draft) {
            $number = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1;
            DB::table('workflow_versions')->where('id', $draft->id)->update(['version_number' => $number, 'updated_at' => $now]);
            $this->audit('workflow.version.updated', 'workflow_versions', (int) $draft->id, ['version_number' => (int) $draft->version_number],
                ['version_number' => $number, 'after_published' => (int) $version->id, 'workflow' => $code]);
        }
    }

    /** The step exists and is still bound to the requester. */
    private function patchable(object $version, string $stepCode): bool
    {
        return DB::table('workflow_steps')->where('workflow_version_id', $version->id)->where('code', $stepCode)->where('assignment_type', 'requester')->exists();
    }

    private function patch(string $code, object $version, string $stepCode, int $assetsId): void
    {
        $step = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->where('code', $stepCode)->where('assignment_type', 'requester')->first();
        if (! $step) {
            return;
        }
        // Scope 'any' mirrors RentalsAccess::canManage (rentals.manage in any scope), so an officer appointed in an
        // assets unit (e.g. «العقود والتأجير») holds the step exactly as he may prepare the request.
        $config = ['department_scope' => 'any', 'requester_may_act' => true,
            'fallback_department_id' => $assetsId, 'supervisor_department_id' => $assetsId];
        $row = ['assignment_type' => 'role', 'assigned_role_code' => 'rentals_officer', 'assignment_config' => json_encode($config, self::JSON)];
        DB::table('workflow_steps')->where('id', $step->id)->update($row + ['updated_at' => now()]);
        $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_type' => 'requester', 'assigned_role_code' => $step->assigned_role_code],
            ['assignment_type' => 'role', 'assigned_role_code' => 'rentals_officer', 'assignment_config' => $config, 'step' => $stepCode,
                'workflow' => $code, 'version' => (int) $version->version_number, 'version_status' => $version->status]);
    }

    /** A new draft holding an exact copy of the version's stages and actions (as WorkflowPublishingService::draftFor). */
    private function cloneVersion(string $code, object $definition, object $source): object
    {
        $now = now();
        $id = (int) DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definition->id,
            'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $steps = [];
        foreach (DB::table('workflow_steps')->where('workflow_version_id', $source->id)->orderBy('sort_order')->get() as $step) {
            $row = array_diff_key((array) $step, array_flip(['id', 'workflow_version_id', 'created_at', 'updated_at']));
            $steps[(int) $step->id] = (int) DB::table('workflow_steps')->insertGetId([...$row, 'workflow_version_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('workflow_transitions')->where('workflow_version_id', $source->id)->orderBy('id')->get() as $transition) {
            $row = array_diff_key((array) $transition, array_flip(['id', 'workflow_version_id', 'from_step_id', 'to_step_id', 'created_at', 'updated_at']));
            DB::table('workflow_transitions')->insert([
                ...$row, 'workflow_version_id' => $id,
                'from_step_id' => $steps[(int) $transition->from_step_id], 'to_step_id' => $steps[(int) $transition->to_step_id],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $version = DB::table('workflow_versions')->where('id', $id)->first();
        $this->audit('workflow.version.drafted', 'workflow_versions', $id, null,
            ['cloned_from' => (int) $source->id, 'cloned_version' => (int) $source->version_number, 'workflow' => $code, 'version' => (int) $version->version_number]);

        return $version;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after, 'source' => self::SOURCE], self::JSON),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
