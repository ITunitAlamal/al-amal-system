<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of «AIMS_Orphans_Sponsorships_Requirements_2026-10-29» (identity and files), additive only:
 *
 * - Q32: emergency flag on a family file, alerting the department head only; no workflow, no payment effect.
 * - Q33: manual referral note with a responsible staff member; no referral engine.
 * - Q29: document expiry alert window (alerts only; an expired document no longer counts as accepted).
 * - Q50: retention policy per data category, recorded by the executive director; nothing is deleted automatically.
 * - Q05: dynamic guardian fields reuse custom_field_definitions (entity «orphan_guardian»), no new table.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_23_000001 (المرحلة 1: الطوارئ والإحالات وانتهاء الوثائق وسياسة الاحتفاظ)';

    private const GUARDED = ['orphan_emergency_flags', 'orphan_referrals'];

    /** Data categories of the department; the period stays undecided until the executive director records it. */
    private const CATEGORIES = [
        'child_file' => 10, 'guardian_identity' => 20, 'documents' => 30, 'health' => 40, 'social_visits' => 50,
        'sponsorship_records' => 60, 'relief_delivery' => 70, 'portal_requests' => 80, 'access_logs' => 90,
    ];

    private const EVENTS = [
        'orphans_emergency_flagged' => ['حالة طوارئ في ملف أسرة', 'لرئيس قسم الكفالات عند وسم ملف أسرة بحالة طوارئ أو رفع الوسم. تنبيه فقط، دون مسار موافقات.'],
        'orphans_referral_assigned' => ['إحالة مسندة إليك', 'للموظف المسؤول عن متابعة إحالة يدوية لأسرة أو طفل.'],
        'orphans_document_expiring' => ['مستند ينتهي قريبًا أو انتهى', 'لرئيس القسم ومن يملك التحقق من المستندات قبل انتهاء مستند في ملف أسرة وعند انتهائه.'],
    ];

    public function up(): void
    {
        Schema::table('orphans_module_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('document_expiry_alert_days')->default(30)->comment('days before expiry to alert; alerts only');
        });
        DB::statement('ALTER TABLE orphans_module_settings ADD CONSTRAINT orphans_settings_expiry_alert CHECK (document_expiry_alert_days BETWEEN 1 AND 365)');

        Schema::create('orphan_emergency_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $table->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('raised_at');
            $table->jsonb('alerted_user_ids')->default('[]')->comment('heads alerted when raised; empty when no head was appointed');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cleared_at')->nullable();
            $table->text('clear_note')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'raised_at']);
        });
        DB::statement('ALTER TABLE orphan_emergency_flags ADD CONSTRAINT orphan_emergency_cleared CHECK ((cleared_at IS NULL AND cleared_by IS NULL AND clear_note IS NULL) OR (cleared_at IS NOT NULL AND cleared_by IS NOT NULL AND clear_note IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX orphan_emergency_one_open ON orphan_emergency_flags (household_id) WHERE cleared_at IS NULL');

        Schema::create('orphan_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $table->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $table->string('referred_to', 200)->comment('the external party, as written by staff');
            $table->text('reason');
            $table->date('referred_on');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->text('outcome')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['household_id', 'status']);
            $table->index(['responsible_user_id', 'status']);
        });
        DB::statement("ALTER TABLE orphan_referrals ADD CONSTRAINT orphan_referrals_status CHECK ((status = 'open' AND closed_at IS NULL AND closed_by IS NULL AND outcome IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND outcome IS NOT NULL))");

        Schema::create('orphan_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('data_category', 40)->unique();
            $table->unsignedSmallInteger('retention_months')->nullable()->comment('null: not decided yet');
            $table->string('starts_from', 20)->default('file_closed');
            $table->string('action_after', 20)->default('review')->comment('recorded intention only; nothing runs automatically');
            $table->text('legal_basis')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE orphan_retention_policies ADD CONSTRAINT orphan_retention_values CHECK ((retention_months IS NULL OR retention_months BETWEEN 1 AND 1200)'
            ." AND starts_from IN ('file_closed','record_created','last_activity') AND action_after IN ('review','anonymize','archive'))");
        $now = now();
        foreach (self::CATEGORIES as $code => $order) {
            DB::table('orphan_retention_policies')->insert(['data_category' => $code, 'retention_months' => null, 'starts_from' => 'file_closed', 'action_after' => 'review',
                'legal_basis' => null, 'sort_order' => $order, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'orphans.phase1.installed', 'subject_type' => 'orphans', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => [...self::GUARDED, 'orphan_retention_policies'], 'settings' => ['document_expiry_alert_days' => 30],
                'events' => array_keys(self::EVENTS)], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Rolled back only while no flag or referral exists and no retention period has been recorded. */
    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        if (Schema::hasTable('orphan_retention_policies') && DB::table('orphan_retention_policies')->whereNotNull('updated_by')->exists()) {
            throw new RuntimeException('سُجلت سياسة احتفاظ معتمدة؛ لا يمكن التراجع.');
        }
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        Schema::dropIfExists('orphan_retention_policies');
        Schema::dropIfExists('orphan_referrals');
        Schema::dropIfExists('orphan_emergency_flags');
        DB::statement('ALTER TABLE orphans_module_settings DROP CONSTRAINT IF EXISTS orphans_settings_expiry_alert');
        Schema::table('orphans_module_settings', function (Blueprint $table): void {
            $table->dropColumn('document_expiry_alert_days');
        });
    }
};
