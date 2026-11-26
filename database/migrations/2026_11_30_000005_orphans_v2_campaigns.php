<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 6 — «الحملات الإغاثية»: rule versions, nomination runs with explained candidates, versioned beneficiary
 * lists, change requests, per-campaign access, duplicate alerts, field deliveries and reconciliation.
 *
 * HARD RULE enforced in the database as well as in the service: a list becomes «approved» only from
 * «pending_executive», only with a decider who holds the executive_director role, and an approved/superseded/rejected
 * version never changes again (except approved → superseded when the executive director approves its successor).
 * Entries change only while their list is a draft. Rule versions and runs are immutable. Deliveries are append-only.
 * A database superuser can still bypass this; the application and its role cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->string('title', 300);
            $t->text('description')->nullable();
            $t->string('donor_name', 300)->nullable();
            $t->foreignId('sponsor_id')->nullable()->constrained('sponsors')->restrictOnDelete();
            $t->foreignId('aid_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('beneficiary_unit', 10)->default('orphan');
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('status', 20)->default('draft');
            $t->foreignId('warehouse_id')->nullable()->constrained('finance_warehouses')->restrictOnDelete();
            $t->foreignId('restricted_fund_id')->nullable()->constrained('finance_restricted_funds')->restrictOnDelete();
            $t->foreignId('manager_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->jsonb('targeting')->nullable();
            $t->unsignedTinyInteger('wizard_step')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->text('close_note')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_unit CHECK (beneficiary_unit IN ('orphan','household','guardian'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status CHECK (status IN ('draft','list_draft','pending_executive','approved','distributing','reconciling','closed','cancelled'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)');

        Schema::create('campaign_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('stock_item_id')->nullable()->constrained('finance_stock_items')->restrictOnDelete();
            $t->string('label', 200);
            $t->string('variant', 60)->nullable();
            $t->decimal('per_unit_quantity', 12, 3)->default(1);
            $t->decimal('total_quantity', 12, 3);
            $t->jsonb('criteria')->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE campaign_items ADD CONSTRAINT campaign_items_quantities CHECK (per_unit_quantity > 0 AND total_quantity >= 0)');

        Schema::create('campaign_quotas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('governorate_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('locality_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->timestamps();
        });

        Schema::create('campaign_rule_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('mode', 10);
            $t->jsonb('gates');
            $t->jsonb('criteria');
            $t->string('missing_policy', 10)->default('review');
            $t->string('tie_break', 20)->nullable();
            $t->unsignedInteger('reserve_count')->default(0);
            $t->string('rules_hash', 64);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['campaign_id', 'version']);
        });
        DB::statement("ALTER TABLE campaign_rule_versions ADD CONSTRAINT campaign_rule_versions_mode CHECK (mode IN ('general','scored','hybrid'))");
        DB::statement("ALTER TABLE campaign_rule_versions ADD CONSTRAINT campaign_rule_versions_missing CHECK (missing_policy IN ('review','neutral'))");

        Schema::create('campaign_runs', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('rule_version_id')->constrained('campaign_rule_versions')->restrictOnDelete();
            $t->string('input_hash', 64);
            $t->jsonb('counts');
            $t->jsonb('capacity');
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });

        Schema::create('campaign_candidates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->constrained('campaign_runs')->restrictOnDelete();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->string('outcome', 25);
            $t->unsignedInteger('rank')->nullable();
            $t->decimal('score', 7, 3)->nullable();
            $t->jsonb('contributions')->nullable();
            $t->jsonb('reasons')->nullable();
            $t->jsonb('missing')->nullable();
            $t->jsonb('snapshot');
            $t->foreignId('governorate_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->unsignedSmallInteger('age')->nullable();
            $t->foreignId('item_id')->nullable()->constrained('campaign_items')->restrictOnDelete();
            $t->jsonb('duplicate_flags')->nullable();
            $t->unique(['run_id', 'unit_key']);
            $t->index(['run_id', 'outcome', 'rank']);
        });
        DB::statement('ALTER TABLE campaign_candidates ADD CONSTRAINT campaign_candidates_unit CHECK (num_nonnulls(orphan_id, household_id, guardian_id) = 1)');
        DB::statement("ALTER TABLE campaign_candidates ADD CONSTRAINT campaign_candidates_outcome CHECK (outcome IN ('primary','reserve','review','ineligible','insufficient_priority'))");

        Schema::create('campaign_lists', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 20)->default('draft');
            $t->string('source', 15)->default('run');
            $t->foreignId('run_id')->nullable()->constrained('campaign_runs')->restrictOnDelete();
            $t->foreignId('base_list_id')->nullable()->constrained('campaign_lists')->restrictOnDelete();
            $t->string('content_hash', 64)->nullable();
            $t->unsignedInteger('entry_count')->default(0);
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('recommended_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('recommended_at')->nullable();
            $t->text('recommendation_note')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'version']);
        });
        DB::statement("ALTER TABLE campaign_lists ADD CONSTRAINT campaign_lists_status CHECK (status IN ('draft','pending_executive','approved','superseded','rejected'))");
        DB::statement("ALTER TABLE campaign_lists ADD CONSTRAINT campaign_lists_source CHECK (source IN ('run','change_request','import'))");
        DB::statement("CREATE UNIQUE INDEX campaign_lists_one_approved ON campaign_lists (campaign_id) WHERE status = 'approved'");
        DB::statement("CREATE UNIQUE INDEX campaign_lists_one_open ON campaign_lists (campaign_id) WHERE status IN ('draft','pending_executive')");

        Schema::create('campaign_list_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('list_id')->constrained('campaign_lists')->restrictOnDelete();
            $t->string('kind', 10)->default('primary');
            $t->unsignedInteger('position');
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->foreignId('recipient_guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->foreignId('item_id')->nullable()->constrained('campaign_items')->restrictOnDelete();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('score', 7, 3)->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('governorate_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('candidate_id')->nullable()->constrained('campaign_candidates')->restrictOnDelete();
            $t->timestamps();
            $t->index(['list_id', 'kind', 'position']);
        });
        DB::statement('ALTER TABLE campaign_list_entries ADD CONSTRAINT campaign_list_entries_unit CHECK (num_nonnulls(orphan_id, household_id, guardian_id) = 1)');
        DB::statement("ALTER TABLE campaign_list_entries ADD CONSTRAINT campaign_list_entries_kind CHECK (kind IN ('primary','reserve'))");
        DB::statement('ALTER TABLE campaign_list_entries ADD CONSTRAINT campaign_list_entries_quantity CHECK (quantity > 0)');
        // No beneficiary twice for the same item in one version (primary and reserve included).
        DB::statement('CREATE UNIQUE INDEX campaign_list_entries_unique ON campaign_list_entries (list_id, unit_key, COALESCE(item_id, 0))');

        Schema::create('campaign_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('base_list_id')->constrained('campaign_lists')->restrictOnDelete();
            $t->foreignId('proposed_list_id')->unique()->constrained('campaign_lists')->restrictOnDelete();
            $t->foreignId('appeal_id')->nullable()->constrained('orphan_appeals')->restrictOnDelete();
            $t->text('reason');
            $t->jsonb('diff')->nullable();
            $t->string('status', 12)->default('draft');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE campaign_change_requests ADD CONSTRAINT campaign_change_requests_status CHECK (status IN ('draft','pending','approved','rejected','withdrawn'))");

        Schema::create('campaign_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role', 12);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE campaign_members ADD CONSTRAINT campaign_members_role CHECK (role IN ('viewer','staff','distributor'))");
        DB::statement('CREATE UNIQUE INDEX campaign_members_live ON campaign_members (campaign_id, user_id) WHERE revoked_at IS NULL');

        Schema::create('campaign_duplicate_alerts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('run_id')->nullable()->constrained('campaign_runs')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->string('kind', 20);
            $t->foreignId('other_campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('benefit_record_id')->nullable()->constrained('benefit_records')->restrictOnDelete();
            $t->string('detail', 500);
            $t->string('status', 20)->default('open');
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('created_at');
            $t->index(['campaign_id', 'status']);
        });
        DB::statement("ALTER TABLE campaign_duplicate_alerts ADD CONSTRAINT campaign_duplicate_alerts_status CHECK (status IN ('open','exception_granted','resolved'))");

        Schema::create('campaign_stock_issues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('item_id')->constrained('campaign_items')->restrictOnDelete();
            $t->string('kind', 10);
            $t->decimal('quantity', 12, 3);
            $t->foreignId('stock_movement_id')->unique()->constrained('finance_stock_movements')->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        DB::statement("ALTER TABLE campaign_stock_issues ADD CONSTRAINT campaign_stock_issues_kind CHECK (kind IN ('issue','return'))");

        Schema::create('campaign_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('list_entry_id')->constrained('campaign_list_entries')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->uuid('idempotency_key')->unique();
            $t->string('kind', 12);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->string('recipient_name', 300)->nullable();
            $t->string('recipient_relation', 12)->nullable();
            $t->text('proxy_note')->nullable();
            $t->boolean('identity_checked')->default(false);
            $t->foreignId('failure_reason_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->timestamp('occurred_at');
            $t->foreignId('corrects_id')->nullable()->unique()->constrained('campaign_deliveries')->restrictOnDelete();
            $t->string('signature_path', 300)->nullable();
            $t->timestamp('created_at');
            $t->index(['campaign_id', 'unit_key']);
            $t->index('list_entry_id');
        });
        DB::statement("ALTER TABLE campaign_deliveries ADD CONSTRAINT campaign_deliveries_kind CHECK (kind IN ('delivered','undelivered','returned','correction'))");
        DB::statement("ALTER TABLE campaign_deliveries ADD CONSTRAINT campaign_deliveries_correction CHECK ((kind = 'correction') = (corrects_id IS NOT NULL))");
        DB::statement("ALTER TABLE campaign_deliveries ADD CONSTRAINT campaign_deliveries_delivered CHECK (kind <> 'delivered' OR (quantity > 0 AND recipient_name IS NOT NULL AND recipient_relation IS NOT NULL))");

        Schema::create('campaign_reconciliations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->jsonb('summary');
            $t->text('note')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });

        Schema::table('orphan_appeals', function (Blueprint $t): void {
            $t->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();
        });
        Schema::table('benefit_records', function (Blueprint $t): void {
            $t->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();
            $t->foreign('campaign_delivery_id')->references('id')->on('campaign_deliveries')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE FUNCTION campaign_immutable_row() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'CAMPAIGN_IMMUTABLE: % rows are never edited or deleted', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER campaign_rule_versions_immutable BEFORE UPDATE OR DELETE ON campaign_rule_versions FOR EACH ROW EXECUTE FUNCTION campaign_immutable_row();
CREATE TRIGGER campaign_runs_immutable BEFORE UPDATE OR DELETE ON campaign_runs FOR EACH ROW EXECUTE FUNCTION campaign_immutable_row();
CREATE TRIGGER campaign_candidates_immutable BEFORE UPDATE OR DELETE ON campaign_candidates FOR EACH ROW EXECUTE FUNCTION campaign_immutable_row();
CREATE TRIGGER campaign_deliveries_immutable BEFORE UPDATE OR DELETE ON campaign_deliveries FOR EACH ROW EXECUTE FUNCTION campaign_immutable_row();
CREATE TRIGGER campaign_stock_issues_immutable BEFORE UPDATE OR DELETE ON campaign_stock_issues FOR EACH ROW EXECUTE FUNCTION campaign_immutable_row();

CREATE FUNCTION campaign_lists_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: only a draft list version can be discarded' USING ERRCODE = '23514'; END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status NOT IN ('draft') THEN RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: a list version starts as a draft' USING ERRCODE = '23514'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status IN ('superseded','rejected') THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: version % is closed', OLD.version USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'approved' THEN
        IF NEW.status = 'superseded' AND NEW.campaign_id = OLD.campaign_id AND NEW.version = OLD.version AND NEW.content_hash IS NOT DISTINCT FROM OLD.content_hash
           AND NEW.decided_by IS NOT DISTINCT FROM OLD.decided_by AND NEW.entry_count = OLD.entry_count THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: approved version % is immutable', OLD.version USING ERRCODE = '23514';
    END IF;
    IF NEW.status = 'approved' THEN
        IF OLD.status <> 'pending_executive' THEN
            RAISE EXCEPTION 'CAMPAIGN_LIST_APPROVAL: only a version awaiting the executive director can be approved' USING ERRCODE = '23514';
        END IF;
        IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL OR NOT EXISTS (
            SELECT 1 FROM department_user_roles a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
            WHERE a.user_id = NEW.decided_by AND r.code = 'executive_director' AND u.is_active
              AND (a.starts_on IS NULL OR a.starts_on <= CURRENT_DATE) AND (a.ends_on IS NULL OR a.ends_on >= CURRENT_DATE)) THEN
            RAISE EXCEPTION 'CAMPAIGN_LIST_APPROVAL: only the executive director approves a beneficiary list' USING ERRCODE = '42501';
        END IF;
    END IF;
    IF OLD.status = 'pending_executive' AND NEW.status NOT IN ('approved','rejected','draft','pending_executive') THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: invalid transition' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER campaign_lists_guard BEFORE INSERT OR UPDATE OR DELETE ON campaign_lists FOR EACH ROW EXECUTE FUNCTION campaign_lists_guard();

CREATE FUNCTION campaign_list_entries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s text;
BEGIN
    SELECT status INTO s FROM campaign_lists WHERE id = COALESCE(NEW.list_id, OLD.list_id);
    IF s IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: entries change only while the version is a draft' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.list_id <> OLD.list_id THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: entries cannot move between versions' USING ERRCODE = '23514';
    END IF;
    RETURN COALESCE(NEW, OLD);
END $$;
CREATE TRIGGER campaign_list_entries_guard BEFORE INSERT OR UPDATE OR DELETE ON campaign_list_entries FOR EACH ROW EXECUTE FUNCTION campaign_list_entries_guard();
SQL);

        DB::statement('CREATE SEQUENCE campaign_seq START 1');
        foreach ([['orphans_campaign_decision', 'قرار مطلوب على كشف حملة', 'إشعار المدير التنفيذي بكشف أو تعديل كشف بانتظار اعتماده.'],
            ['orphans_campaign_update', 'تحديث على كشف حملة', 'إشعار معدّي الحملة بقرار المدير التنفيذي.']] as [$code, $label, $description]) {
            if (! DB::table('notification_settings')->where('event_code', $code)->exists()) {
                DB::table('notification_settings')->insert(['event_code' => $code, 'label_ar' => $label, 'description' => $description, 'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: campaign lists, approvals and deliveries are records.');
    }
};
