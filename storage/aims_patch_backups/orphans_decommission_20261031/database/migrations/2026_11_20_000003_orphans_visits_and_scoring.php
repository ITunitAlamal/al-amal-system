<?php

use App\Services\Orphans\OrphansWorkflowInstaller as Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Social visits and the two separate scoring systems: the general social score shown in each child's file and the
 * per-campaign priority score. A ruleset's factors and weights are inactive until the executive director approves
 * them; every computed score keeps its ruleset version, inputs and per-factor points, and an override needs a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orphan_households', function (Blueprint $t): void {
            $t->foreignId('guardian_authority_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
        });

        Schema::create('orphan_social_visits', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('visit_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('reason');
            $t->date('scheduled_on');
            $t->date('visited_on')->nullable();
            $t->foreignId('researcher_id')->constrained('users')->restrictOnDelete();
            $t->text('interviewed')->nullable();
            $t->text('social_findings')->nullable();
            $t->text('economic_findings')->nullable();
            $t->text('housing_findings')->nullable();
            $t->jsonb('needs')->nullable();
            $t->text('recommendations')->nullable();
            $t->jsonb('action_items')->nullable();
            $t->date('next_visit_on')->nullable();
            $t->string('status', 12)->default('planned');
            $t->timestamp('attested_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'scheduled_on']);
        });
        DB::statement("ALTER TABLE orphan_social_visits ADD CONSTRAINT orphan_visits_status CHECK (status IN ('planned','submitted','returned','reviewed','cancelled'))");
        DB::statement('ALTER TABLE orphan_social_visits ADD CONSTRAINT orphan_visits_reviewer CHECK (reviewed_by IS NULL OR reviewed_by <> researcher_id)');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS orphan_visit_ref_seq START 1');

        Schema::create('scoring_rulesets', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('kind', 10);
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedInteger('version');
            $t->string('name', 200);
            $t->jsonb('factors');
            $t->jsonb('tie_breakers')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('draft');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->string('factors_hash', 64)->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE scoring_rulesets ADD CONSTRAINT scoring_rulesets_values CHECK (kind IN ('social','campaign') AND status IN ('draft','pending_approval','approved','rejected','retired')
            AND (kind = 'campaign' OR campaign_id IS NULL) AND (status <> 'approved' OR (approved_by IS NOT NULL AND factors_hash IS NOT NULL)))");
        DB::statement('CREATE UNIQUE INDEX scoring_rulesets_version ON scoring_rulesets (kind, COALESCE(campaign_id, 0), version)');
        DB::statement("CREATE UNIQUE INDEX scoring_rulesets_one_social_active ON scoring_rulesets (kind) WHERE kind = 'social' AND status = 'approved'");
        // An approved ruleset is frozen: its factors and hash cannot change afterwards (scores keep pointing at it).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION scoring_rulesets_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('approved', 'retired') AND (NEW.factors IS DISTINCT FROM OLD.factors OR NEW.factors_hash IS DISTINCT FROM OLD.factors_hash
                    OR NEW.tie_breakers IS DISTINCT FROM OLD.tie_breakers OR NEW.approved_by IS DISTINCT FROM OLD.approved_by) THEN
                    RAISE EXCEPTION 'approved scoring ruleset % is frozen', OLD.id;
                END IF;
                IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
                    RAISE EXCEPTION 'retired scoring ruleset % cannot be reactivated', OLD.id;
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER scoring_rulesets_freeze BEFORE UPDATE ON scoring_rulesets FOR EACH ROW EXECUTE FUNCTION scoring_rulesets_freeze();
            SQL);

        Schema::create('orphan_social_scores', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('ruleset_id')->constrained('scoring_rulesets')->restrictOnDelete();
            $t->decimal('total', 8, 2);
            $t->jsonb('components');
            $t->string('inputs_hash', 64);
            $t->decimal('override_total', 8, 2)->nullable();
            $t->text('override_reason')->nullable();
            $t->foreignId('override_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('override_at')->nullable();
            $t->boolean('is_current')->default(true);
            $t->foreignId('computed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('computed_at');
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX orphan_social_scores_current ON orphan_social_scores (orphan_child_id) WHERE is_current');
        DB::statement('ALTER TABLE orphan_social_scores ADD CONSTRAINT orphan_social_override CHECK (override_total IS NULL OR (override_reason IS NOT NULL AND override_by IS NOT NULL))');

        Installer::install('ORPHAN_SOCIAL_VISIT', 'orphan_social_visit', 'زيارة اجتماعية',
            'تقرير الباحث الميداني بعد الزيارة ومراجعة رئيس قسم الكفالات؛ الباحث لا يعتمد تقريره بنفسه.', 'VIS', [
                ['researcher', 'تقرير الباحث وإقراره', 10, 'start', 'requester', null, null],
                ['head_review', 'مراجعة رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['reviewed', 'اعتماد التقرير', 900, 'end', null, null, ['outcome' => 'completed']],
            ], [
                ['researcher', 'head_review', 'submit', 'رفع التقرير لرئيس القسم', false],
                ['head_review', 'reviewed', 'approve', 'اعتماد التقرير', false],
                ['head_review', 'researcher', 'return', 'إعادة للباحث (اذكر السبب)', true],
            ]);
        Installer::install('SCORING_RULESET', 'scoring_ruleset', 'اعتماد قواعد التقييم والأولوية',
            'عوامل التقييم الاجتماعي العام أو أولوية حملة إغاثية وأوزانها؛ لا تُفعّل إلا بقرار المدير التنفيذي، والإصدار المعتمد لا يُعدّل.', 'SCR', [
                ['proposer', 'إعداد القواعد ومبرراتها', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', ['department_scope' => 'any']],
                ['approved', 'اعتماد القواعد', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض القواعد', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['proposer', 'head_recommendation', 'submit', 'رفع القواعد لرئيس القسم', false],
                ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
                ['head_recommendation', 'proposer', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
                ['ed_decision', 'approved', 'approve', 'اعتماد القواعد وتفعيلها', false],
                ['ed_decision', 'proposer', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
                ['ed_decision', 'rejected', 'reject', 'رفض القواعد (اذكر السبب)', true],
            ]);
        Installer::notification('orphans_visit_update', 'زيارة اجتماعية', 'للباحث ورئيس القسم عند رفع تقرير زيارة أو مراجعته.');
        Installer::notification('scoring_ruleset_decided', 'قرار قواعد التقييم', 'لمعد قواعد التقييم عند اعتمادها أو رفضها أو إعادتها.');
    }

    public function down(): void
    {
        foreach (['orphan_social_visits', 'scoring_rulesets', 'orphan_social_scores'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("يوجد سجلات في {$table}؛ لا يُتراجع عن الترحيل.");
            }
        }
        Installer::uninstall(['ORPHAN_SOCIAL_VISIT', 'SCORING_RULESET']);
        Schema::dropIfExists('orphan_social_scores');
        DB::unprepared('DROP TRIGGER IF EXISTS scoring_rulesets_freeze ON scoring_rulesets; DROP FUNCTION IF EXISTS scoring_rulesets_freeze();');
        Schema::dropIfExists('scoring_rulesets');
        Schema::dropIfExists('orphan_social_visits');
        DB::statement('DROP SEQUENCE IF EXISTS orphan_visit_ref_seq');
        Schema::table('orphan_households', fn (Blueprint $t) => $t->dropConstrainedForeignId('guardian_authority_value_id'));
        DB::table('notification_settings')->whereIn('event_code', ['orphans_visit_update', 'scoring_ruleset_decided'])->delete();
    }
};
