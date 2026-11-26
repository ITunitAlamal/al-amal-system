<?php

use App\Services\Orphans\OrphansWorkflowInstaller as Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsors, sponsorships (approved by the executive director, versioned on amendment), their schedule of dues,
 * collections and payouts recorded by Accounts from its own vouchers (AIMS keeps references, not the books), the
 * child's savings ledger fed only by posted collections, one-off contributions (pledge vs collected), and assistance
 * requests whose execution belongs to Accounts/the warehouse and whose delivery needs proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Installer::masterData('sponsorships', 'الكفالات', [
            'sponsorship_frequencies' => ['دورية الكفالة', [['monthly', 'شهرية', ['months' => 1]], ['quarterly', 'ربع سنوية', ['months' => 3]],
                ['half_yearly', 'نصف سنوية', ['months' => 6]], ['yearly', 'سنوية', ['months' => 12]]]],
            'sponsorship_purposes' => ['غرض الكفالة', [['expenses', 'مصاريف', ['expenses' => true]], ['savings', 'ادخار', ['savings' => true]], ['split', 'مصاريف وادخار', ['expenses' => true, 'savings' => true]]]],
            'sponsorship_statuses' => ['انتظام الكفالة', [['ongoing', 'مستمرة'], ['paused', 'متوقفة'], ['irregular', 'غير منتظمة']]],
        ]);

        Schema::create('sponsors', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('donor_no', 20)->unique();
            $t->string('kind', 12);
            $t->string('name', 200);
            $t->string('country', 100)->nullable();
            $t->text('phone')->nullable();
            $t->string('email', 150)->nullable();
            $t->text('address')->nullable();
            $t->text('preferences')->nullable();
            $t->boolean('confidential')->default(false);
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE sponsors ADD CONSTRAINT sponsors_values CHECK (kind IN ('person','organization') AND status IN ('active','inactive'))");

        Schema::create('sponsorships', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('sponsorship_no', 20)->unique();
            $t->foreignId('sponsor_id')->constrained('sponsors')->restrictOnDelete();
            $t->string('beneficiary_type', 10);
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->foreignId('frequency_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('purpose_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->decimal('expense_amount', 12, 2);
            $t->decimal('savings_amount', 12, 2);
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('status', 20)->default('draft');
            $t->foreignId('regularity_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('status_reason')->nullable();
            $t->text('conditions')->nullable();
            $t->string('source', 200)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('supersedes_id')->nullable()->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE sponsorships ADD CONSTRAINT sponsorships_values CHECK (beneficiary_type IN ('child','household') AND (beneficiary_type = 'household' OR orphan_child_id IS NOT NULL)
            AND amount > 0 AND expense_amount >= 0 AND savings_amount >= 0 AND expense_amount + savings_amount = amount AND (ends_on IS NULL OR ends_on >= starts_on)
            AND status IN ('draft','pending_approval','active','paused','ended','rejected','cancelled','superseded') AND (status NOT IN ('active','paused','ended','superseded') OR approved_by IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX sponsorships_no_duplicate ON sponsorships (sponsor_id, household_id, COALESCE(orphan_child_id, 0)) WHERE status IN ('pending_approval','active','paused')");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sponsor_ref_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sponsorship_ref_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS contribution_ref_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS assistance_ref_seq START 1');

        Schema::create('sponsorship_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $t->date('period_start');
            $t->date('due_on');
            $t->decimal('expected_amount', 12, 2);
            $t->decimal('expense_part', 12, 2);
            $t->decimal('savings_part', 12, 2);
            $t->string('status', 10)->default('expected');
            $t->timestamps();
            $t->unique(['sponsorship_id', 'period_start']);
        });
        DB::statement("ALTER TABLE sponsorship_schedules ADD CONSTRAINT sponsorship_schedules_values CHECK (status IN ('expected','partial','collected','waived','cancelled') AND expense_part + savings_part = expected_amount)");

        Schema::create('sponsorship_collections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('schedule_id')->constrained('sponsorship_schedules')->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->string('accounting_reference', 120);
            $t->date('posted_on');
            $t->string('external_system', 120)->nullable();
            $t->string('request_key', 64)->unique();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE sponsorship_collections ADD CONSTRAINT sponsorship_collections_amount CHECK (amount > 0)');
        DB::statement('CREATE UNIQUE INDEX sponsorship_collections_voucher ON sponsorship_collections (accounting_reference, schedule_id) WHERE reversed_at IS NULL');

        Schema::create('orphan_savings_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('sponsorship_id')->nullable()->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('collection_id')->nullable()->constrained('sponsorship_collections')->restrictOnDelete();
            $t->string('kind', 10);
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->string('accounting_reference', 120);
            $t->text('reason')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        DB::statement("ALTER TABLE orphan_savings_entries ADD CONSTRAINT orphan_savings_values CHECK (kind IN ('credit','debit','reversal') AND amount > 0)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orphan_savings_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'the savings ledger is append-only; correct with a reversal entry';
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER orphan_savings_append_only BEFORE UPDATE OR DELETE ON orphan_savings_entries FOR EACH ROW EXECUTE FUNCTION orphan_savings_append_only();
            SQL);

        Schema::create('sponsorship_disbursements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $t->foreignId('schedule_id')->nullable()->constrained('sponsorship_schedules')->restrictOnDelete();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('payment_instruction_id')->constrained('orphan_payment_instructions')->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->string('accounting_reference', 120);
            $t->date('executed_on');
            $t->string('status', 10)->default('executed');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE sponsorship_disbursements ADD CONSTRAINT sponsorship_disbursements_values CHECK (amount > 0 AND status IN ('executed','reversed'))");
        DB::statement("CREATE UNIQUE INDEX sponsorship_disbursements_voucher ON sponsorship_disbursements (accounting_reference, sponsorship_id) WHERE status = 'executed'");

        Schema::create('orphan_contributions', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->foreignId('sponsor_id')->nullable()->constrained('sponsors')->restrictOnDelete();
            $t->string('donor_name', 200)->nullable();
            $t->text('donor_contact')->nullable();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);
            $t->string('earmark_type', 10)->default('general');
            $t->unsignedBigInteger('earmark_id')->nullable();
            $t->string('status', 10)->default('pledged');
            $t->string('accounting_reference', 120)->nullable();
            $t->date('posted_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_contributions ADD CONSTRAINT orphan_contributions_values CHECK (amount > 0 AND earmark_type IN ('general','child','household','campaign')
            AND status IN ('pledged','collected','cancelled','refunded') AND (status NOT IN ('collected','refunded') OR (accounting_reference IS NOT NULL AND collected_by IS NOT NULL))
            AND (sponsor_id IS NOT NULL OR donor_name IS NOT NULL) AND (earmark_type = 'general' OR earmark_id IS NOT NULL))");

        Schema::create('orphan_assistance_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('assistance_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('description');
            $t->decimal('quantity', 12, 2)->nullable();
            $t->decimal('estimated_value', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('funding_source', 200)->nullable();
            $t->jsonb('evidence_document_ids')->nullable();
            $t->string('status', 12)->default('in_review');
            $t->string('execution_reference', 120)->nullable();
            $t->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->text('delivery_proof')->nullable();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('delivered_at')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_assistance_requests ADD CONSTRAINT orphan_assistance_values CHECK (status IN ('in_review','returned','approved','executed','delivered','rejected')
            AND (status NOT IN ('executed','delivered') OR (execution_reference IS NOT NULL AND executed_by IS NOT NULL)) AND (status <> 'delivered' OR delivery_proof IS NOT NULL))");

        $ed = ['department_scope' => 'any'];
        Installer::install('SPONSORSHIP', 'sponsorship', 'اعتماد كفالة', 'إعداد الكفالة في قسم الكفالات، توصية رئيس القسم، ثم قرار المدير التنفيذي؛ التحصيل والصرف لدى الحسابات.', 'SPN', [
            ['proposer', 'إعداد الكفالة', 10, 'start', 'requester', null, null],
            ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
            ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
            ['approved', 'اعتماد الكفالة وتفعيلها', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الكفالة', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['proposer', 'head_recommendation', 'submit', 'رفع لرئيس القسم', false],
            ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
            ['head_recommendation', 'proposer', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
            ['ed_decision', 'approved', 'approve', 'اعتماد الكفالة', false],
            ['ed_decision', 'head_recommendation', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض (اذكر السبب)', true],
        ]);
        Installer::install('ORPHAN_ASSISTANCE', 'orphan_assistance', 'مساعدة لأسرة/يتيم', 'طلب القسم، توصية الرئيس، قرار المدير التنفيذي، تنفيذ الحسابات/المخازن، ثم إثبات التسليم؛ الاعتماد وحده ليس تسليمًا.', 'AST', [
            ['requester', 'طلب المساعدة ومبرراته', 10, 'start', 'requester', null, null],
            ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
            ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
            ['execution', 'التنفيذ لدى الحسابات/المخازن', 40, 'task', 'role', 'finance_head', $ed],
            ['delivery', 'إثبات التسليم للمستفيد', 50, 'task', 'actor_of_step', null, ['step_code' => 'requester']],
            ['closed', 'إغلاق بعد التسليم', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'head_recommendation', 'submit', 'رفع لرئيس القسم', false],
            ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
            ['head_recommendation', 'requester', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
            ['ed_decision', 'execution', 'approve', 'اعتماد وإحالة للتنفيذ', false],
            ['ed_decision', 'head_recommendation', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض (اذكر السبب)', true],
            ['execution', 'delivery', 'execute', 'تم التنفيذ (مع مرجع المستند)', true],
            ['delivery', 'closed', 'deliver', 'تأكيد التسليم بإثبات', true],
        ]);
        Installer::notification('sponsorship_update', 'الكفالات', 'لمعد الكفالة عند اعتمادها أو رفضها أو إعادتها.');
        Installer::notification('assistance_update', 'المساعدات', 'لمقدم طلب المساعدة عند القرار والتنفيذ والتسليم.');
    }

    public function down(): void
    {
        foreach (['sponsors', 'sponsorships', 'orphan_contributions', 'orphan_assistance_requests'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("يوجد سجلات في {$table}؛ لا يُتراجع عن الترحيل.");
            }
        }
        Installer::uninstall(['SPONSORSHIP', 'ORPHAN_ASSISTANCE']);
        Schema::dropIfExists('orphan_assistance_requests');
        Schema::dropIfExists('orphan_contributions');
        Schema::dropIfExists('sponsorship_disbursements');
        DB::unprepared('DROP TRIGGER IF EXISTS orphan_savings_append_only ON orphan_savings_entries; DROP FUNCTION IF EXISTS orphan_savings_append_only();');
        Schema::dropIfExists('orphan_savings_entries');
        Schema::dropIfExists('sponsorship_collections');
        Schema::dropIfExists('sponsorship_schedules');
        Schema::dropIfExists('sponsorships');
        Schema::dropIfExists('sponsors');
        foreach (['sponsor_ref_seq', 'sponsorship_ref_seq', 'contribution_ref_seq', 'assistance_ref_seq'] as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }
        DB::table('notification_settings')->whereIn('event_code', ['sponsorship_update', 'assistance_update'])->delete();
    }
};
