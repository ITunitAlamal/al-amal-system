<?php

use App\Services\Orphans\OrphansWorkflowInstaller as Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relief campaigns, owned by the Sponsorships & Orphans department (never a department of their own): lifecycle with
 * the executive director's approval, items, named-campaign members (PR and field roles, time-bound), roster revisions
 * frozen once submitted, executive-only beneficiary exceptions, and allocations that cannot double-benefit a unit.
 * The database itself enforces the ownership, the roster freeze and the executive-only exception approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Installer::masterData('relief', 'الحملات الإغاثية', [
            'relief_campaign_types' => ['نوع الحملة الإغاثية', []],
            'relief_delivery_methods' => ['طريقة التسليم', []],
            'relief_exception_categories' => ['فئة الاستثناء من قواعد الحملة', [
                ['not_eligible', 'غير مستوفٍ لشروط الأهلية'], ['outside_ranking', 'خارج الترتيب المعتمد'], ['above_cap', 'يتجاوز سقف الكشف'],
                ['after_roster_approval', 'إضافة بعد اعتماد الكشف'], ['substitution', 'استبدال مستفيد'], ['other', 'انحراف آخر عن القواعد'],
            ]],
            'relief_failure_reasons' => ['أسباب عدم التسليم', [
                ['refused', 'رفض الاستلام'], ['unavailable', 'المستفيد غير متاح'], ['damaged', 'المساعدة تالفة'], ['identity_mismatch', 'عدم تطابق الهوية'],
                ['no_signature', 'تعذر التوقيع'], ['unknown_proxy', 'مستلم غير مفوض'],
            ]],
        ]);

        Schema::create('relief_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->text('objective')->nullable();
            $t->foreignId('type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('delivery_method_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('unit_of_benefit', 10);
            $t->jsonb('eligibility')->nullable();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->date('registration_deadline')->nullable();
            $t->decimal('budget_amount', 14, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('funding_source', 300)->nullable();
            $t->foreignId('finance_fund_id')->nullable()->constrained('finance_restricted_funds')->restrictOnDelete();
            $t->unsignedInteger('roster_cap')->nullable();
            $t->string('priority', 10)->default('normal');
            $t->foreignId('manager_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('owner_department_id')->constrained('departments')->restrictOnDelete();
            $t->boolean('publication_consent')->default(false);
            $t->string('status', 12)->default('draft');
            $t->text('status_reason')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('closure_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_campaigns ADD CONSTRAINT relief_campaigns_values CHECK (unit_of_benefit IN ('child','household')
            AND priority IN ('normal','high','urgent') AND status IN ('draft','in_review','approved','active','suspended','closing','closed','rejected','cancelled')
            AND ends_on >= starts_on AND (budget_amount IS NULL OR budget_amount >= 0) AND (roster_cap IS NULL OR roster_cap > 0))");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS relief_campaign_ref_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS relief_exception_ref_seq START 1');
        // Campaigns belong to the Sponsorships & Orphans department and to no other node.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relief_campaigns_owner_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.owner_department_id IS DISTINCT FROM (SELECT id FROM departments WHERE code = 'ORPHANS' AND deleted_at IS NULL) THEN
                    RAISE EXCEPTION 'relief campaigns are owned by the Sponsorships and Orphans department';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_campaigns_owner_guard BEFORE INSERT OR UPDATE OF owner_department_id ON relief_campaigns FOR EACH ROW EXECUTE FUNCTION relief_campaigns_owner_guard();
            SQL);
        DB::statement('ALTER TABLE scoring_rulesets ADD CONSTRAINT scoring_rulesets_campaign_fk FOREIGN KEY (campaign_id) REFERENCES relief_campaigns(id) ON DELETE RESTRICT');

        Schema::create('relief_campaign_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->string('label', 200);
            $t->string('kind', 10);
            $t->foreignId('stock_item_id')->nullable()->constrained('finance_stock_items')->restrictOnDelete();
            $t->string('unit', 30);
            $t->decimal('quantity_per_unit', 12, 2);
            $t->decimal('unit_value', 12, 2)->nullable();
            $t->decimal('planned_total', 14, 2);
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_campaign_items ADD CONSTRAINT relief_items_values CHECK (kind IN ('in_kind','cash','voucher','service') AND quantity_per_unit > 0 AND planned_total >= quantity_per_unit AND (unit_value IS NULL OR unit_value >= 0))");

        Schema::create('relief_campaign_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role', 20);
            $t->boolean('may_view_pseudonymous')->default(false);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->text('reason');
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('revoke_reason')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_campaign_members ADD CONSTRAINT relief_members_values CHECK (role IN ('coordinator','outreach','media','field_distributor','reviewer')
            AND ends_on >= starts_on AND ends_on <= starts_on + 366 AND granted_by <> user_id)");
        DB::statement('CREATE UNIQUE INDEX relief_members_one_open ON relief_campaign_members (campaign_id, user_id, role) WHERE revoked_at IS NULL');

        Schema::create('relief_rosters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('status', 20)->default('draft');
            $t->foreignId('ruleset_id')->constrained('scoring_rulesets')->restrictOnDelete();
            $t->unsignedInteger('candidate_count')->default(0);
            $t->unsignedInteger('selected_count')->default(0);
            $t->unsignedInteger('waitlist_count')->default(0);
            $t->unsignedInteger('excluded_count')->default(0);
            $t->unsignedInteger('capacity')->nullable();
            $t->char('snapshot_hash', 64)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['campaign_id', 'revision']);
        });
        DB::statement("ALTER TABLE relief_rosters ADD CONSTRAINT relief_rosters_values CHECK (status IN ('draft','pending_approval','approved','superseded','rejected')
            AND (status NOT IN ('approved','superseded') OR (approved_by IS NOT NULL AND snapshot_hash IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX relief_rosters_one_approved ON relief_rosters (campaign_id) WHERE status = 'approved'");

        Schema::create('relief_exceptions', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->foreignId('base_roster_id')->nullable()->constrained('relief_rosters')->restrictOnDelete();
            $t->string('unit_type', 10);
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->foreignId('category_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->text('rule_violated');
            $t->text('explanation');
            $t->text('staff_recommendation');
            $t->text('head_recommendation')->nullable();
            $t->jsonb('evidence_document_ids')->nullable();
            $t->jsonb('proposed_items');
            $t->decimal('budget_impact', 14, 2)->nullable();
            $t->text('stock_impact')->nullable();
            $t->string('status', 12)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->date('expires_on')->nullable();
            $t->foreignId('applied_roster_id')->nullable()->constrained('relief_rosters')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_exceptions ADD CONSTRAINT relief_exceptions_values CHECK (unit_type IN ('child','household')
            AND status IN ('pending','approved','rejected','withdrawn','expired','applied') AND (status NOT IN ('approved','applied','rejected') OR (decided_by IS NOT NULL AND decision_reason IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX relief_exceptions_one_open ON relief_exceptions (campaign_id, unit_key) WHERE status IN ('pending','approved')");
        // Only an active holder of the executive director role decides an exception, never on their own request; an
        // approved exception cannot be re-decided. This holds whatever the application layer does (forged calls included).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relief_exceptions_executive_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.status <> 'pending' THEN
                    RAISE EXCEPTION 'an exception starts pending';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.status IN ('approved', 'rejected') AND OLD.status IS DISTINCT FROM NEW.status THEN
                    IF OLD.status <> 'pending' THEN
                        RAISE EXCEPTION 'exception % is no longer pending', OLD.id;
                    END IF;
                    IF NEW.decided_by IS NULL OR NEW.decided_by = NEW.requested_by OR NOT EXISTS (
                        SELECT 1 FROM department_user_roles a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
                        WHERE a.user_id = NEW.decided_by AND r.code = 'executive_director' AND u.is_active
                          AND (a.starts_on IS NULL OR a.starts_on <= CURRENT_DATE) AND (a.ends_on IS NULL OR a.ends_on >= CURRENT_DATE)) THEN
                        RAISE EXCEPTION 'only the executive director decides a campaign exception';
                    END IF;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status IN ('approved', 'applied', 'rejected', 'expired', 'withdrawn') AND (NEW.decided_by IS DISTINCT FROM OLD.decided_by
                    OR NEW.unit_key IS DISTINCT FROM OLD.unit_key OR NEW.proposed_items IS DISTINCT FROM OLD.proposed_items OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason) THEN
                    RAISE EXCEPTION 'a decided exception cannot be altered';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status IN ('applied', 'rejected', 'expired', 'withdrawn') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'exception % is closed', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status = 'approved' AND NEW.status NOT IN ('approved', 'applied', 'expired') THEN
                    RAISE EXCEPTION 'an approved exception can only be applied or expire';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_exceptions_executive_guard BEFORE INSERT OR UPDATE ON relief_exceptions FOR EACH ROW EXECUTE FUNCTION relief_exceptions_executive_guard();
            SQL);

        Schema::create('relief_roster_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('roster_id')->constrained('relief_rosters')->restrictOnDelete();
            $t->string('unit_type', 10);
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->string('pseudonym', 16);
            $t->foreignId('area_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('eligibility', 12);
            $t->jsonb('eligibility_reasons')->nullable();
            $t->decimal('score_total', 10, 2)->nullable();
            $t->jsonb('score_components')->nullable();
            $t->jsonb('inputs')->nullable();
            $t->unsignedInteger('rank_overall')->nullable();
            $t->unsignedInteger('rank_area')->nullable();
            $t->string('decision', 10);
            $t->text('reason')->nullable();
            $t->foreignId('exception_id')->nullable()->constrained('relief_exceptions')->restrictOnDelete();
            $t->char('entry_hash', 64);
            $t->timestamps();
            $t->unique(['roster_id', 'unit_key']);
        });
        DB::statement("ALTER TABLE relief_roster_entries ADD CONSTRAINT relief_entries_values CHECK (unit_type IN ('child','household') AND eligibility IN ('eligible','ineligible','incomplete')
            AND decision IN ('selected','waitlist','excluded') AND (decision <> 'selected' OR eligibility = 'eligible' OR exception_id IS NOT NULL))");
        // Entries are written only while their roster is a draft; afterwards the snapshot is immutable. A selected entry
        // resting on an exception requires that exception to be approved by the executive director.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relief_entries_freeze() RETURNS trigger AS $$
            DECLARE roster_status text;
            BEGIN
                SELECT status INTO roster_status FROM relief_rosters WHERE id = COALESCE(NEW.roster_id, OLD.roster_id);
                IF roster_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'roster entries are frozen once the roster leaves draft';
                END IF;
                IF TG_OP <> 'DELETE' AND NEW.exception_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM relief_exceptions e WHERE e.id = NEW.exception_id AND e.status = 'approved' AND e.unit_key = NEW.unit_key) THEN
                    RAISE EXCEPTION 'an exception entry needs an approved executive exception for the same unit';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_entries_freeze BEFORE INSERT OR UPDATE OR DELETE ON relief_roster_entries FOR EACH ROW EXECUTE FUNCTION relief_entries_freeze();
            CREATE OR REPLACE FUNCTION relief_rosters_freeze() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('approved', 'superseded') AND (NEW.snapshot_hash IS DISTINCT FROM OLD.snapshot_hash OR NEW.approved_by IS DISTINCT FROM OLD.approved_by
                    OR NEW.ruleset_id IS DISTINCT FROM OLD.ruleset_id) THEN
                    RAISE EXCEPTION 'an approved roster is frozen';
                END IF;
                IF OLD.status = 'superseded' AND NEW.status <> 'superseded' THEN
                    RAISE EXCEPTION 'a superseded roster stays superseded';
                END IF;
                IF NEW.status = 'approved' AND OLD.status <> 'approved' AND NOT EXISTS (
                    SELECT 1 FROM department_user_roles a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
                    WHERE a.user_id = NEW.approved_by AND r.code = 'executive_director' AND u.is_active) THEN
                    RAISE EXCEPTION 'only the executive director approves a campaign roster';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_rosters_freeze BEFORE UPDATE ON relief_rosters FOR EACH ROW EXECUTE FUNCTION relief_rosters_freeze();
            SQL);

        Schema::create('relief_allocations', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->foreignId('roster_id')->constrained('relief_rosters')->restrictOnDelete();
            $t->foreignId('roster_entry_id')->constrained('relief_roster_entries')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->jsonb('items');
            $t->string('status', 20)->default('reserved');
            $t->text('status_reason')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_allocations ADD CONSTRAINT relief_allocations_status CHECK (status IN ('reserved','assigned','delivered','partially_delivered','not_delivered','conflict','cancelled','returned'))");
        DB::statement("CREATE UNIQUE INDEX relief_allocations_one_per_unit ON relief_allocations (campaign_id, unit_key) WHERE status <> 'cancelled'");

        Schema::create('relief_reconciliations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('reference', 120);
            $t->unsignedInteger('confirmed_units');
            $t->jsonb('quantities');
            $t->string('outcome', 12);
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_reconciliations ADD CONSTRAINT relief_reconciliations_values CHECK (kind IN ('stock','finance') AND outcome IN ('reconciled','discrepancy'))");

        $ed = ['department_scope' => 'any'];
        Installer::install('RELIEF_CAMPAIGN', 'relief_campaign', 'اعتماد حملة إغاثية',
            'إعداد الحملة في قسم الكفالات، توصية رئيس القسم، ثم قرار المدير التنفيذي؛ لا تنشط حملة من مسودة موظف.', 'CMP', [
                ['proposer', 'إعداد الحملة', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
                ['approved', 'اعتماد الحملة', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الحملة', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], $this->decisionTransitions('proposer', 'approved'));
        Installer::install('RELIEF_ROSTER', 'relief_roster', 'اعتماد كشف مستفيدي حملة',
            'كشف مجمّد بقواعد أولوية معتمدة وبصمته؛ يوصي رئيس القسم ويعتمده المدير التنفيذي، ولا تُصرف مخصصات قبل اعتماده.', 'RST', [
                ['preparer', 'إعداد الكشف ومراجعته', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
                ['approved', 'اعتماد الكشف', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الكشف', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], $this->decisionTransitions('preparer', 'approved'));
        Installer::install('RELIEF_EXCEPTION', 'relief_exception', 'استثناء مستفيد من قواعد حملة',
            'كل انحراف عن قواعد الحملة يقرره المدير التنفيذي شخصيًا وحصريًا؛ لا تفويض لهذا القرار.', 'EXC', [
                ['requester', 'طلب الاستثناء ومبرراته', 10, 'start', 'requester', null, null],
                ['head_review', 'رأي رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي (حصري)', 30, 'decision', 'role', 'executive_director', $ed],
                ['approved', 'اعتماد الاستثناء', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الاستثناء', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['requester', 'head_review', 'submit', 'رفع الطلب لرئيس القسم', false],
                ['head_review', 'ed_decision', 'recommend', 'رفع الرأي للمدير التنفيذي', true],
                ['head_review', 'requester', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
                ['ed_decision', 'approved', 'approve', 'اعتماد الاستثناء (اذكر المبرر)', true],
                ['ed_decision', 'head_review', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
                ['ed_decision', 'rejected', 'reject', 'رفض الاستثناء (اذكر السبب)', true],
            ]);
        Installer::install('RELIEF_CLOSURE', 'relief_campaign_closure', 'إغلاق حملة إغاثية',
            'إغلاق الحملة بعد إثبات التسليم وتسوية المخزون والمال وعدم بقاء تعارضات معلقة؛ القرار للمدير التنفيذي.', 'CLS', [
                ['manager', 'طلب الإغلاق مع الأدلة', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
                ['closed', 'إغلاق الحملة', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الإغلاق', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], $this->decisionTransitions('manager', 'closed'));
        Installer::notification('relief_campaign_update', 'الحملات الإغاثية', 'لمدير الحملة ورئيس القسم عند قرارات الحملة وكشفها وإغلاقها.');
        Installer::notification('relief_exception_decided', 'قرار استثناء حملة', 'لمقدم طلب الاستثناء عند قرار المدير التنفيذي.');
        Installer::notification('relief_member_changed', 'تكليف في حملة', 'للموظف عند تكليفه بدور محدد المدة في حملة أو إنهائه.');
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}> */
    private function decisionTransitions(string $start, string $done): array
    {
        return [
            [$start, 'head_recommendation', 'submit', 'رفع لرئيس القسم', false],
            ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
            ['head_recommendation', $start, 'return', 'إعادة للاستكمال (اذكر السبب)', true],
            ['ed_decision', $done, 'approve', 'اعتماد', false],
            ['ed_decision', 'head_recommendation', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض (اذكر السبب)', true],
        ];
    }

    public function down(): void
    {
        foreach (['relief_campaigns', 'relief_exceptions', 'relief_allocations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("يوجد سجلات في {$table}؛ لا يُتراجع عن الترحيل.");
            }
        }
        Installer::uninstall(['RELIEF_CAMPAIGN', 'RELIEF_ROSTER', 'RELIEF_EXCEPTION', 'RELIEF_CLOSURE']);
        DB::statement('ALTER TABLE scoring_rulesets DROP CONSTRAINT IF EXISTS scoring_rulesets_campaign_fk');
        Schema::dropIfExists('relief_reconciliations');
        Schema::dropIfExists('relief_allocations');
        DB::unprepared('DROP TRIGGER IF EXISTS relief_entries_freeze ON relief_roster_entries; DROP FUNCTION IF EXISTS relief_entries_freeze();');
        Schema::dropIfExists('relief_roster_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS relief_exceptions_executive_guard ON relief_exceptions; DROP FUNCTION IF EXISTS relief_exceptions_executive_guard();');
        Schema::dropIfExists('relief_exceptions');
        DB::unprepared('DROP TRIGGER IF EXISTS relief_rosters_freeze ON relief_rosters; DROP FUNCTION IF EXISTS relief_rosters_freeze();');
        Schema::dropIfExists('relief_rosters');
        Schema::dropIfExists('relief_campaign_members');
        Schema::dropIfExists('relief_campaign_items');
        DB::unprepared('DROP TRIGGER IF EXISTS relief_campaigns_owner_guard ON relief_campaigns; DROP FUNCTION IF EXISTS relief_campaigns_owner_guard();');
        Schema::dropIfExists('relief_campaigns');
        DB::statement('DROP SEQUENCE IF EXISTS relief_campaign_ref_seq');
        DB::statement('DROP SEQUENCE IF EXISTS relief_exception_ref_seq');
        DB::table('notification_settings')->whereIn('event_code', ['relief_campaign_update', 'relief_exception_decided', 'relief_member_changed'])->delete();
    }
};
