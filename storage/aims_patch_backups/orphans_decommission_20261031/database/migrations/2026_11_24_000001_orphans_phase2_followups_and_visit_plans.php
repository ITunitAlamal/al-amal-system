<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of «AIMS_Orphans_Sponsorships_Requirements_2026-10-29» (research and eligibility), additive only:
 *
 * - Q10 / Q27: case follow-ups. Each family file has at most one open follow-up with a due date and a responsible
 *   staff member; reminders before the due date and escalation to the department head when it is overdue. An
 *   approved visit completes it, and the next one is scheduled from the visit or the case's review interval.
 * - Q31: visit plans for a period, with the visits distributed among the researchers.
 * - Visits gain their plan, the follow-up they serve, and a recorded cancellation (head only, with a reason).
 *
 * The review interval is a policy value of the executive director and stays undecided (null) until recorded.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_24_000001 (المرحلة 2: المتابعة الدورية وخطط الزيارات)';

    private const GUARDED = ['orphan_followups', 'orphan_visit_plans'];

    private const EVENTS = [
        'orphans_followup_due' => ['متابعة حالة مستحقة', 'للموظف المسؤول عن متابعة ملف أسرة قبل موعدها وعند تأخرها.'],
        'orphans_followup_escalated' => ['تصعيد متابعة متأخرة', 'لرئيس قسم الكفالات عند تأخر متابعة ملف أسرة عن موعدها بالمهلة المحددة.'],
        'orphans_visit_assigned' => ['زيارات مسندة إليك', 'للباحث عند إسناد زيارات اجتماعية إليه ضمن خطة زيارات أو بإعادة الإسناد.'],
    ];

    public function up(): void
    {
        Schema::table('orphans_module_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('followup_interval_months')->nullable()->comment('default review interval of an active file; null: not decided');
            $table->unsignedSmallInteger('followup_reminder_days')->default(7)->comment('days before the due date to remind the responsible staff member');
            $table->unsignedSmallInteger('followup_escalation_days')->default(7)->comment('days overdue before the head is alerted');
        });
        DB::statement('ALTER TABLE orphans_module_settings ADD CONSTRAINT orphans_settings_followup CHECK ((followup_interval_months IS NULL OR followup_interval_months BETWEEN 1 AND 24)'
            .' AND followup_reminder_days BETWEEN 1 AND 60 AND followup_escalation_days BETWEEN 1 AND 90)');

        Schema::table('orphan_households', function (Blueprint $table): void {
            $table->unsignedSmallInteger('followup_interval_months')->nullable()->comment('case review interval set by the head; null: the department default');
        });
        DB::statement('ALTER TABLE orphan_households ADD CONSTRAINT orphan_households_followup_interval CHECK (followup_interval_months IS NULL OR followup_interval_months BETWEEN 1 AND 24)');

        Schema::create('orphan_visit_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference_no', 20)->unique();
            $table->string('title', 200);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });
        DB::statement('ALTER TABLE orphan_visit_plans ADD CONSTRAINT orphan_visit_plans_period CHECK (ends_on >= starts_on AND ends_on - starts_on <= 92)');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS orphan_visit_plan_ref_seq START 1');

        Schema::create('orphan_followups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $table->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $table->date('due_on');
            $table->text('reason');
            $table->string('source', 12)->comment('manual | visit (recommended by an approved visit) | periodic (review interval)');
            $table->foreignId('source_visit_id')->nullable()->constrained('orphan_social_visits')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('open');
            $table->text('outcome')->nullable();
            $table->foreignId('completed_visit_id')->nullable()->constrained('orphan_social_visits')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->jsonb('escalated_to')->default('[]');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_on']);
            $table->index(['assigned_to', 'status']);
            $table->index(['household_id', 'status']);
        });
        DB::statement("ALTER TABLE orphan_followups ADD CONSTRAINT orphan_followups_values CHECK (source IN ('manual','visit','periodic')"
            ." AND ((status = 'open' AND closed_at IS NULL AND closed_by IS NULL AND outcome IS NULL)"
            ." OR (status IN ('completed','cancelled') AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND outcome IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX orphan_followups_one_open ON orphan_followups (household_id) WHERE status = 'open'");

        Schema::table('orphan_social_visits', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained('orphan_visit_plans')->restrictOnDelete();
            $table->foreignId('followup_id')->nullable()->constrained('orphan_followups')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->index(['plan_id', 'status']);
            $table->index(['researcher_id', 'status']);
        });
        DB::statement("ALTER TABLE orphan_social_visits ADD CONSTRAINT orphan_visits_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))");

        $now = now();
        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'orphans.phase2.installed', 'subject_type' => 'orphans', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => self::GUARDED, 'settings' => ['followup_interval_months' => null, 'followup_reminder_days' => 7,
                'followup_escalation_days' => 7], 'events' => array_keys(self::EVENTS)], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Rolled back only while no follow-up, plan, cancelled visit or case interval has been recorded. */
    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        if (DB::table('orphan_social_visits')->where('status', 'cancelled')->exists() || DB::table('orphan_households')->whereNotNull('followup_interval_months')->exists()) {
            throw new RuntimeException('سُجل إلغاء زيارة أو دورية متابعة لأسرة؛ لا يمكن التراجع.');
        }
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        DB::statement('ALTER TABLE orphan_social_visits DROP CONSTRAINT IF EXISTS orphan_visits_cancelled');
        Schema::table('orphan_social_visits', function (Blueprint $table): void {
            $table->dropIndex(['plan_id', 'status']);
            $table->dropIndex(['researcher_id', 'status']);
            $table->dropConstrainedForeignId('plan_id');
            $table->dropConstrainedForeignId('followup_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('orphan_followups');
        Schema::dropIfExists('orphan_visit_plans');
        DB::statement('DROP SEQUENCE IF EXISTS orphan_visit_plan_ref_seq');
        DB::statement('ALTER TABLE orphan_households DROP CONSTRAINT IF EXISTS orphan_households_followup_interval');
        Schema::table('orphan_households', fn (Blueprint $table) => $table->dropColumn('followup_interval_months'));
        DB::statement('ALTER TABLE orphans_module_settings DROP CONSTRAINT IF EXISTS orphans_settings_followup');
        Schema::table('orphans_module_settings', function (Blueprint $table): void {
            $table->dropColumn(['followup_interval_months', 'followup_reminder_days', 'followup_escalation_days']);
        });
    }
};
