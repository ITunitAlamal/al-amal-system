<?php

use App\Services\Configuration\NavigationRegistry;
use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The organization in the one departments tree: node types (office / department / functional unit), unit details and
 * retirement fields, with integrity rules (no cycles, no unit inside a unit, one live name per parent). Adds staff
 * assignments to units, a ledger of structural changes decided through workflow ORG_STRUCTURE_CHANGE (executive
 * director), and scoped expiring delegations limited to a policy list that stays disabled until the institution
 * enables it. Additive only: no department or reference is removed.
 */
return new class extends Migration
{
    private const SOURCE = '2026_11_19_000002_organization_units_and_delegations';

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('node_type', 20)->default('department');
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retired_reason')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
        });
        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_node_type_check CHECK (node_type IN ('office', 'department', 'unit'))");
        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_unit_parent_check CHECK (node_type <> 'unit' OR parent_id IS NOT NULL)");
        DB::table('departments')->where('code', 'EXEC')->update(['node_type' => 'office']);
        DB::statement('CREATE UNIQUE INDEX departments_live_name_per_parent ON departments (COALESCE(parent_id, 0), lower(btrim(name))) WHERE deleted_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION departments_structure_guard() RETURNS trigger AS $$
DECLARE
    ancestor bigint;
    steps integer := 0;
    parent_type text;
BEGIN
    IF NEW.parent_id IS NOT NULL THEN
        IF NEW.parent_id = NEW.id THEN
            RAISE EXCEPTION 'department % cannot be its own parent', NEW.id;
        END IF;
        ancestor := NEW.parent_id;
        WHILE ancestor IS NOT NULL LOOP
            IF ancestor = NEW.id THEN
                RAISE EXCEPTION 'department hierarchy cycle through %', NEW.id;
            END IF;
            steps := steps + 1;
            IF steps > 50 THEN
                RAISE EXCEPTION 'department hierarchy deeper than 50 levels';
            END IF;
            SELECT d.parent_id INTO ancestor FROM departments d WHERE d.id = ancestor;
        END LOOP;
        SELECT d.node_type INTO parent_type FROM departments d WHERE d.id = NEW.parent_id;
        IF parent_type = 'unit' THEN
            RAISE EXCEPTION 'nothing may be placed under the functional unit %', NEW.parent_id;
        END IF;
    END IF;
    IF NEW.node_type = 'unit' AND NEW.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM departments c WHERE c.parent_id = NEW.id AND c.deleted_at IS NULL AND c.id <> NEW.id) THEN
        RAISE EXCEPTION 'department % has live sub-units and cannot become a functional unit', NEW.id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::unprepared('CREATE TRIGGER departments_structure_guard BEFORE INSERT OR UPDATE OF parent_id, node_type ON departments FOR EACH ROW EXECUTE FUNCTION departments_structure_guard()');

        Schema::create('department_unit_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('departments');
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('role', 20)->default('member');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->timestampTz('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE department_unit_assignments ADD CONSTRAINT dua_role_check CHECK (role IN ('member', 'coordinator'))");
        DB::statement("ALTER TABLE department_unit_assignments ADD CONSTRAINT dua_status_check CHECK (status IN ('active', 'ended'))");
        DB::statement('ALTER TABLE department_unit_assignments ADD CONSTRAINT dua_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("CREATE UNIQUE INDEX dua_one_active_per_unit ON department_unit_assignments (unit_id, employee_id) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX dua_one_coordinator ON department_unit_assignments (unit_id) WHERE status = 'active' AND role = 'coordinator'");

        Schema::create('department_structure_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('change_type', 30);
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->foreignId('parent_id')->nullable()->constrained('departments');
            $table->string('proposed_name', 255)->nullable();
            $table->string('proposed_code', 50)->nullable();
            $table->jsonb('details')->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->text('rationale');
            $table->string('status', 20);
            $table->string('authority', 30)->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE department_structure_changes ADD CONSTRAINT dsc_type_check CHECK (change_type IN ('create_unit', 'convert_to_unit', 'rename', 'retire', 'reparent', 'reactivate'))");
        DB::statement("ALTER TABLE department_structure_changes ADD CONSTRAINT dsc_status_check CHECK (status IN ('suggested', 'in_review', 'returned', 'rejected', 'withdrawn', 'applied'))");

        Schema::create('delegable_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('permission_code', 100)->unique();
            $table->unsignedSmallInteger('max_days')->default(90);
            $table->boolean('requires_executive')->default(true);
            $table->boolean('is_active')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE delegable_permissions ADD CONSTRAINT delegable_permissions_code_fk FOREIGN KEY (permission_code) REFERENCES permissions (code)');
        DB::statement('ALTER TABLE delegable_permissions ADD CONSTRAINT delegable_permissions_days_check CHECK (max_days BETWEEN 1 AND 365)');

        Schema::create('scoped_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegator_user_id')->constrained('users');
            $table->foreignId('delegate_user_id')->constrained('users');
            $table->string('permission_code', 100);
            $table->foreignId('scope_department_id')->constrained('departments');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20)->default('active');
            $table->text('reason');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['delegate_user_id', 'status']);
        });
        DB::statement('ALTER TABLE scoped_delegations ADD CONSTRAINT scoped_delegations_code_fk FOREIGN KEY (permission_code) REFERENCES permissions (code)');
        DB::statement("ALTER TABLE scoped_delegations ADD CONSTRAINT scoped_delegations_status_check CHECK (status IN ('active', 'revoked'))");
        DB::statement('ALTER TABLE scoped_delegations ADD CONSTRAINT scoped_delegations_people_check CHECK (delegator_user_id <> delegate_user_id)');
        DB::statement('ALTER TABLE scoped_delegations ADD CONSTRAINT scoped_delegations_window_check CHECK (ends_at > starts_at)');

        $now = now();
        foreach ([
            ['kpi.view', 90, false, 'عرض مؤشرات أداء القسم أو الوحدة المفوَّض نطاقها فقط.'],
            ['rentals.view', 90, false, 'الاطلاع على ملف الأصول والإيجارات.'],
            ['rentals.manage', 30, true, 'إدارة ملف الأصول والإيجارات؛ لا يفوّضها إلا المدير التنفيذي.'],
        ] as [$code, $days, $executive, $note]) {
            if (DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('delegable_permissions')->insert(['permission_code' => $code, 'max_days' => $days, 'requires_executive' => $executive,
                    'is_active' => false, 'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $this->workflow();
        DB::table('notification_settings')->insertOrIgnore(['event_code' => 'org_structure_change_decided', 'label_ar' => 'قرار تعديل الهيكل التنظيمي',
            'description' => 'لمقدم مقترح تعديل الهيكل عند اعتماده أو رفضه أو إعادته.', 'in_app_enabled' => true, 'sms_enabled' => false,
            'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('notification_settings')->insertOrIgnore(['event_code' => 'scoped_delegation_changed', 'label_ar' => 'تفويض صلاحية مؤقتة',
            'description' => 'للموظف عند تفويض صلاحية مؤقتة إليه في نطاق قسم أو وحدة، وعند إلغائها.', 'in_app_enabled' => true, 'sms_enabled' => false,
            'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        if (DB::table('navigation_items')->where('code', 'departments')->value('label_ar') === 'الهيكل الإداري') {
            DB::table('navigation_items')->where('code', 'departments')->update(['label_ar' => 'الهيكل التنظيمي', 'updated_at' => $now]);
        }
        app(NavigationRegistry::class)->apply(null);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'organization.structure.installed', 'subject_type' => 'departments', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['node_types' => ['office', 'department', 'unit'], 'workflow' => 'ORG_STRUCTURE_CHANGE',
                'delegable_permissions' => 'disabled until enabled by the institution'], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function workflow(): void
    {
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => 'ORG_STRUCTURE_CHANGE', 'subject_type' => 'org_structure_change', 'name' => 'تعديل الهيكل التنظيمي',
            'description' => 'مقترح إنشاء وحدة وظيفية أو تحويل قسم إلى وحدة أو إعادة تسمية أو نقل أو أرشفة، يقرره المدير التنفيذي ويُطبَّق تلقائيًا عند الاعتماد.',
            'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => 'ORG', 'is_confidential' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $steps = [
            ['proposer', 'تقديم المقترح مع المبرر', 10, 'start', 'requester', null, null],
            ['ed_decision', 'قرار المدير التنفيذي', 20, 'decision', 'role', 'executive_director', ['department_scope' => 'any']],
            ['applied', 'اعتماد التعديل وتطبيقه على الهيكل', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض التعديل', 910, 'end', null, null, ['outcome' => 'rejected']],
        ];
        $ids = [];
        foreach ($steps as [$code, $name, $order, $stepType, $assignment, $role, $config]) {
            $ids[$code] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $code, 'name' => $name, 'sort_order' => $order, 'step_type' => $stepType,
                'assignment_type' => $assignment, 'assigned_role_code' => $role, 'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([
            ['proposer', 'ed_decision', 'submit', 'رفع المقترح إلى المدير التنفيذي', false],
            ['ed_decision', 'applied', 'approve', 'اعتماد التعديل وتطبيقه', false],
            ['ed_decision', 'proposer', 'return', 'إعادة للاستكمال (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض التعديل (اذكر السبب)', true],
        ] as [$from, $to, $action, $name, $comment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $action, 'name' => $name,
                'conditions' => null, 'requires_comment' => $comment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException('تعذر نشر مسار ORG_STRUCTURE_CHANGE: '.implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => 'ORG_STRUCTURE_CHANGE', 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $definition = DB::table('workflow_definitions')->where('code', 'ORG_STRUCTURE_CHANGE')->value('id');
        if ($definition) {
            $versions = DB::table('workflow_versions')->where('workflow_definition_id', $definition)->pluck('id');
            if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
                throw new RuntimeException('توجد معاملات تعديل هيكل مسجلة؛ لا يُتراجع عن الترحيل.');
            }
            DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
            DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
            DB::table('workflow_versions')->whereIn('id', $versions)->delete();
            DB::table('workflow_definitions')->where('id', $definition)->delete();
        }
        if (DB::table('departments')->where('node_type', 'unit')->exists() || DB::table('department_structure_changes')->exists()) {
            throw new RuntimeException('يوجد في الهيكل وحدات أو تعديلات مسجلة؛ لا يُتراجع عن الترحيل حتى لا يضيع التاريخ.');
        }
        Schema::dropIfExists('scoped_delegations');
        Schema::dropIfExists('delegable_permissions');
        Schema::dropIfExists('department_structure_changes');
        Schema::dropIfExists('department_unit_assignments');
        DB::unprepared('DROP TRIGGER IF EXISTS departments_structure_guard ON departments; DROP FUNCTION IF EXISTS departments_structure_guard();');
        DB::statement('DROP INDEX IF EXISTS departments_live_name_per_parent');
        DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS departments_unit_parent_check');
        DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS departments_node_type_check');
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retired_by');
            $table->dropColumn(['node_type', 'description', 'responsibilities', 'effective_from', 'retired_at', 'retired_reason']);
        });
        DB::table('notification_settings')->whereIn('event_code', ['org_structure_change_decided', 'scoped_delegation_changed'])->delete();
    }
};
