<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of «AIMS_Orphans_Sponsorships_Requirements_2026-10-29» (sponsors and sponsorships), additive only:
 *
 * - Q35: agreement templates (wording approved by the executive director, frozen once active) and sponsorship
 *   agreements (one sponsor, one or more sponsorship lines) with a signed snapshot and a renewal alert.
 * - Q11: group agreements (several orphans) and pooled funds (an internal sponsor record fed by earmarked donations).
 * - Q14: a waiting list per sponsorship program, ranked by the current social score, with a recorded reason whenever
 *   someone is nominated ahead of others.
 * - Q36: a manual log of contacts with sponsors (no automated messages).
 * - Q38: the assessed monthly need of a family, compared with the commitments (never with money received).
 *
 * Each sponsorship line keeps its own approval, dues and Accounts' records; an agreement only groups lines.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_25_000001 (المرحلة 3: الاتفاقيات والصناديق وقائمة الانتظار والاتصال والاحتياج)';

    private const GUARDED = ['sponsorship_agreement_templates', 'sponsorship_funds', 'sponsorship_agreements', 'sponsorship_waitlist', 'sponsor_contacts', 'orphan_need_assessments'];

    private const EVENTS = [
        'sponsorship_agreement_renewal' => ['تجديد اتفاقية كفالة', 'لرئيس قسم الكفالات وموظفي الكفالات قبل انتهاء اتفاقية موقعة وعند انتهائها. تنبيه فقط؛ لا تُغلق أي كفالة آليًا.'],
        'sponsor_contact_followup' => ['متابعة اتصال بكافل', 'لمن سجّل اتصالًا بكافل عند حلول موعد المتابعة المحدد.'],
    ];

    private const CONTRIBUTIONS_CHECK = "amount > 0 AND earmark_type IN (%s)
            AND status IN ('pledged','collected','cancelled','refunded') AND (status NOT IN ('collected','refunded') OR (accounting_reference IS NOT NULL AND collected_by IS NOT NULL))
            AND (sponsor_id IS NOT NULL OR donor_name IS NOT NULL) AND (earmark_type = 'general' OR earmark_id IS NOT NULL)";

    public function up(): void
    {
        Schema::create('sponsorship_agreement_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 40);
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->string('kind', 12);
            $table->text('body');
            $table->string('status', 12)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
        });
        DB::statement("ALTER TABLE sponsorship_agreement_templates ADD CONSTRAINT sponsorship_templates_values CHECK (kind IN ('individual','group','family','fund')
            AND status IN ('draft','active','retired') AND (status = 'draft' OR (activated_by IS NOT NULL AND activated_at IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX sponsorship_templates_one_active ON sponsorship_agreement_templates (code) WHERE status = 'active'");
        // Approved wording never changes: agreements signed under it keep their own snapshot as well.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sponsorship_templates_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'draft' AND (NEW.body IS DISTINCT FROM OLD.body OR NEW.title IS DISTINCT FROM OLD.title OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.version IS DISTINCT FROM OLD.version) THEN
                    RAISE EXCEPTION 'approved agreement template % is frozen', OLD.id;
                END IF;
                IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
                    RAISE EXCEPTION 'retired agreement template % cannot be reactivated', OLD.id;
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER sponsorship_templates_freeze BEFORE UPDATE ON sponsorship_agreement_templates FOR EACH ROW EXECUTE FUNCTION sponsorship_templates_freeze();
            SQL);

        Schema::create('sponsorship_funds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('fund_no', 20)->unique();
            $table->string('name', 200);
            $table->char('currency', 3);
            $table->text('description')->nullable();
            $table->foreignId('sponsor_id')->unique()->constrained('sponsors')->restrictOnDelete()->comment('internal sponsor record that holds the fund\'s sponsorships');
            $table->string('status', 10)->default('active');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE sponsorship_funds ADD CONSTRAINT sponsorship_funds_values CHECK ((status = 'active' AND closed_at IS NULL)
            OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND close_reason IS NOT NULL))");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sponsorship_fund_ref_seq START 1');

        Schema::create('sponsorship_agreements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('agreement_no', 20)->unique();
            $table->foreignId('sponsor_id')->constrained('sponsors')->restrictOnDelete();
            $table->string('kind', 12);
            $table->foreignId('fund_id')->nullable()->constrained('sponsorship_funds')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('sponsorship_agreement_templates')->restrictOnDelete();
            $table->foreignId('renews_id')->nullable()->constrained('sponsorship_agreements')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('renewal_alert_days')->default(30);
            $table->string('status', 12)->default('draft');
            $table->date('signed_on')->nullable();
            $table->foreignId('signed_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rendered_body')->nullable()->comment('the text as signed (snapshot)');
            $table->string('rendered_hash', 64)->nullable();
            $table->text('notes')->nullable();
            $table->text('status_reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'ends_on']);
        });
        DB::statement("ALTER TABLE sponsorship_agreements ADD CONSTRAINT sponsorship_agreements_values CHECK (kind IN ('individual','group','family','fund')
            AND status IN ('draft','signed','renewed','ended','cancelled') AND (ends_on IS NULL OR ends_on >= starts_on) AND renewal_alert_days BETWEEN 1 AND 180
            AND ((kind = 'fund') = (fund_id IS NOT NULL))
            AND (status IN ('draft','cancelled') OR (signed_on IS NOT NULL AND signed_recorded_by IS NOT NULL AND rendered_body IS NOT NULL AND rendered_hash IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX sponsorship_agreements_one_renewal ON sponsorship_agreements (renews_id) WHERE renews_id IS NOT NULL AND status IN ('draft','signed')");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sponsorship_agreement_ref_seq START 1');

        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->foreignId('agreement_id')->nullable()->constrained('sponsorship_agreements')->restrictOnDelete();
            $table->index('agreement_id');
        });

        Schema::create('sponsorship_waitlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $table->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $table->string('beneficiary_type', 10);
            $table->foreignId('program_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete()->comment('sponsorship type; null = general');
            $table->text('reason');
            $table->string('status', 12)->default('waiting');
            $table->foreignId('sponsorship_id')->nullable()->constrained('sponsorships')->restrictOnDelete();
            $table->foreignId('nominated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('nominated_at')->nullable();
            $table->text('skip_reason')->nullable()->comment('why this beneficiary was nominated ahead of higher-ranked ones');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'program_value_id']);
        });
        DB::statement("ALTER TABLE sponsorship_waitlist ADD CONSTRAINT sponsorship_waitlist_values CHECK (beneficiary_type IN ('child','household')
            AND ((beneficiary_type = 'child') = (orphan_child_id IS NOT NULL)) AND status IN ('waiting','nominated','sponsored','removed')
            AND ((status IN ('nominated','sponsored')) = (sponsorship_id IS NOT NULL))
            AND (status <> 'removed' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL AND close_reason IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX sponsorship_waitlist_one_open ON sponsorship_waitlist (household_id, COALESCE(orphan_child_id, 0), COALESCE(program_value_id, 0)) WHERE status IN ('waiting','nominated')");

        Schema::create('sponsor_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sponsor_id')->constrained('sponsors')->restrictOnDelete();
            $table->date('contacted_on');
            $table->string('channel', 12);
            $table->string('direction', 10);
            $table->string('subject', 200);
            $table->text('summary');
            $table->text('outcome')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->timestampTz('follow_up_done_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['sponsor_id', 'contacted_on']);
        });
        DB::statement("ALTER TABLE sponsor_contacts ADD CONSTRAINT sponsor_contacts_values CHECK (channel IN ('phone','whatsapp','email','visit','meeting','letter','other')
            AND direction IN ('outgoing','incoming') AND (follow_up_done_at IS NULL OR follow_up_on IS NOT NULL))");

        Schema::create('orphan_need_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $table->decimal('monthly_amount', 12, 2);
            $table->char('currency', 3);
            $table->text('basis');
            $table->date('assessed_on');
            $table->boolean('is_current')->default(true);
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE orphan_need_assessments ADD CONSTRAINT orphan_need_amount CHECK (monthly_amount > 0)');
        DB::statement('CREATE UNIQUE INDEX orphan_need_one_current ON orphan_need_assessments (household_id) WHERE is_current');

        // Donations may be earmarked for a pooled fund (the constraint is widened, nothing else changes).
        DB::statement('ALTER TABLE orphan_contributions DROP CONSTRAINT orphan_contributions_values');
        DB::statement('ALTER TABLE orphan_contributions ADD CONSTRAINT orphan_contributions_values CHECK ('.sprintf(self::CONTRIBUTIONS_CHECK, "'general','child','household','campaign','fund'").')');

        $now = now();
        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'orphans.phase3.installed', 'subject_type' => 'orphans', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => self::GUARDED, 'columns' => ['sponsorships.agreement_id'], 'contribution_earmarks' => ['fund'],
                'events' => array_keys(self::EVENTS)], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Rolled back only while none of the new records exists. */
    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        if (DB::table('orphan_contributions')->where('earmark_type', 'fund')->exists()) {
            throw new RuntimeException('توجد تبرعات مخصصة لصندوق؛ لا يمكن التراجع.');
        }
        DB::statement('ALTER TABLE orphan_contributions DROP CONSTRAINT orphan_contributions_values');
        DB::statement('ALTER TABLE orphan_contributions ADD CONSTRAINT orphan_contributions_values CHECK ('.sprintf(self::CONTRIBUTIONS_CHECK, "'general','child','household','campaign'").')');
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->dropIndex(['agreement_id']);
            $table->dropConstrainedForeignId('agreement_id');
        });
        Schema::dropIfExists('orphan_need_assessments');
        Schema::dropIfExists('sponsor_contacts');
        Schema::dropIfExists('sponsorship_waitlist');
        Schema::dropIfExists('sponsorship_agreements');
        Schema::dropIfExists('sponsorship_funds');
        DB::unprepared('DROP TRIGGER IF EXISTS sponsorship_templates_freeze ON sponsorship_agreement_templates; DROP FUNCTION IF EXISTS sponsorship_templates_freeze();');
        Schema::dropIfExists('sponsorship_agreement_templates');
        DB::statement('DROP SEQUENCE IF EXISTS sponsorship_fund_ref_seq');
        DB::statement('DROP SEQUENCE IF EXISTS sponsorship_agreement_ref_seq');
    }
};
