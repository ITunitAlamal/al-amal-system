<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * «سجل النظام»: audit_logs stays the single source of truth and gains a canonical event shape.
 *
 * - Every writer (AuditService, AuditLog::create, direct inserts) passes through one BEFORE INSERT trigger that fills
 *   the event id, UTC-safe time, module, origin, actor snapshot, outcome, severity and sensitivity, using the request
 *   context the application puts in the session setting `aims.audit_ctx` (route, guard, correlation id).
 * - Module event tables (workflow decisions, finance, attendance, HR actions, portal, field sync, exports, sensitive
 *   access, vital status, property units) add a reference row in the SAME transaction: no copy of their data.
 * - Rows are append-only: UPDATE/DELETE/TRUNCATE are refused, except the one-time sealing of hash fields by
 *   `audit:seal` (seal_seq, prev_hash, row_hash). This is tamper-evident, not tamper-proof against the table owner.
 *
 * Additive only; existing rows are enriched once (derived columns), their original fields are untouched.
 */
return new class extends Migration
{
    private const BRIDGES = [
        // table => [action SQL, actor SQL, guardian SQL, subject type SQL, subject id SQL, extra metadata SQL, origin SQL]
        'workflow_events' => ["'workflow.task.' || NEW.action", 'NEW.actor_id', 'NULL', "'workflow_instances'", 'NEW.workflow_instance_id::text',
            "jsonb_build_object('from_step', NEW.from_step_id, 'to_step', NEW.to_step_id, 'assigned_to', NEW.assigned_to_id, 'has_comment', NEW.comment IS NOT NULL)", 'NULL'],
        'finance_audit_events' => ["'finance.' || NEW.entity_type || '.' || NEW.action", 'NEW.actor_id', 'NULL', 'NEW.entity_type', 'NEW.entity_id::text', "'{}'::jsonb", 'NULL'],
        'attendance_audit_events' => ["'attendance.' || NEW.entity_type || '.' || NEW.action", 'NEW.actor_id', 'NULL', 'NEW.entity_type', 'NEW.entity_id::text', "'{}'::jsonb", 'NULL'],
        'employee_hr_action_events' => ["'hr.action.' || NEW.event", 'NEW.actor_id', 'NULL', "'employee_hr_actions'", 'NEW.hr_action_id::text', "jsonb_build_object('version', NEW.version)", 'NULL'],
        'portal_application_events' => ["'portal.application.' || NEW.action", 'NEW.actor_user_id', 'NEW.actor_guardian_id', "'portal_applications'", 'NEW.application_id::text',
            "jsonb_build_object('child_claim', NEW.child_id)", "CASE WHEN NEW.actor_guardian_id IS NOT NULL THEN 'portal' END"],
        'field_events' => ["'field.event.' || NEW.kind", 'NEW.user_id', 'NULL', "'campaign_batches'", 'NEW.batch_id::text',
            "jsonb_build_object('event_uuid', NEW.event_uuid, 'device', NEW.device_id, 'status', NEW.status)", "'api'"],
        'orphan_export_log' => ["'orphans.export.' || NEW.report", 'NEW.user_id', 'NULL', "'orphan_export_log'", 'NEW.id::text',
            "jsonb_build_object('format', NEW.format, 'rows', NEW.rows, 'personal_data', NEW.personal_data)", 'NULL'],
        'orphan_access_log' => ["'orphans.access.' || NEW.action", 'NEW.user_id', 'NULL', 'NEW.subject_type', 'NEW.subject_id::text', "'{}'::jsonb", 'NULL'],
        'person_status_events' => ["'orphans.person.status_' || NEW.status", 'NEW.recorded_by', 'NULL', "'persons'", 'NEW.person_id::text', "jsonb_build_object('evidence_source', NEW.source)", 'NULL'],
        'aims_property_unit_status_events' => ["'rentals.unit.status_changed'", 'NEW.changed_by', 'NULL', "'aims_property_units'", 'NEW.unit_id::text',
            "jsonb_build_object('from', NEW.from_status, 'to', NEW.to_status)", 'NULL'],
    ];

    public function up(): void
    {
        Schema::table('audit_logs', function ($t) {
            $t->uuid('event_id')->nullable();
            $t->unsignedSmallInteger('schema_version')->nullable();
            $t->timestampTz('occurred_at')->nullable();
            $t->timestampTz('recorded_at')->nullable();
            $t->string('actor_type', 12)->nullable();
            $t->unsignedBigInteger('actor_guardian_id')->nullable();
            $t->string('actor_label', 150)->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('origin', 10)->nullable();
            $t->string('guard', 20)->nullable();
            $t->string('module', 30)->nullable();
            $t->string('submodule', 40)->nullable();
            $t->string('outcome', 10)->nullable();
            $t->string('reason_code', 60)->nullable();
            $t->string('severity', 10)->nullable();
            $t->string('sensitivity', 12)->nullable();
            $t->string('subject_ref', 120)->nullable();
            $t->string('correlation_id', 64)->nullable();
            $t->string('route_name', 120)->nullable();
            $t->string('request_method', 8)->nullable();
            $t->unsignedBigInteger('seal_seq')->nullable();
            $t->char('prev_hash', 64)->nullable();
            $t->char('row_hash', 64)->nullable();
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_outcome_check CHECK (outcome IN ('success', 'failure', 'denied'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_sensitivity_check CHECK (sensitivity IN ('normal', 'personal', 'restricted'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_severity_check CHECK (severity IN ('info', 'notice', 'warning', 'critical'))");

        // Taxonomy kept in SQL so that every writer, including direct inserts, is classified the same way.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION aims_audit_module(a text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
SELECT CASE
  WHEN p IN ('auth', 'access', 'user', 'role', 'audit', 'password', 'account') THEN 'security'
  WHEN p IN ('organization', 'department') THEN 'organization'
  WHEN p IN ('employee', 'employee_profile', 'employee_document', 'hr', 'job_title', 'job_category', 'job_grade', 'career_scale') THEN 'hr'
  WHEN p = 'attendance' THEN 'attendance'
  WHEN p LIKE 'leave%' THEN 'leaves'
  WHEN p IN ('workflow', 'delegation', 'approval_delegation') THEN 'workflow'
  WHEN p = 'correspondence' THEN 'correspondence'
  WHEN p = 'orphans' THEN 'orphans'
  WHEN p IN ('portal', 'guardian') THEN 'portal'
  WHEN p = 'field' THEN 'field'
  WHEN p IN ('finance', 'financial_settings') THEN 'finance'
  WHEN p IN ('rental', 'rentals', 'asset', 'building') THEN 'assets'
  WHEN p IN ('navigation', 'master_data', 'custom_field', 'dashboard', 'kpi', 'notification_setting', 'notification', 'geography') THEN 'settings'
  WHEN p IN ('task', 'todo', 'personal_task', 'form_draft') THEN 'tasks'
  ELSE 'other' END
FROM (SELECT split_part(a, '.', 1) AS p) s
$$;

CREATE OR REPLACE FUNCTION aims_audit_fill() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  ctx jsonb;
  u record;
BEGIN
  BEGIN
    ctx := NULLIF(current_setting('aims.audit_ctx', true), '')::jsonb;
  EXCEPTION WHEN others THEN
    ctx := NULL;
  END;
  NEW.event_id := COALESCE(NEW.event_id, gen_random_uuid());
  NEW.schema_version := COALESCE(NEW.schema_version, 1);
  NEW.created_at := COALESCE(NEW.created_at, now()::timestamp);
  NEW.occurred_at := COALESCE(NEW.occurred_at, NEW.created_at::timestamptz);
  NEW.recorded_at := clock_timestamp();
  NEW.module := COALESCE(NEW.module, aims_audit_module(NEW.action));
  NEW.submodule := COALESCE(NEW.submodule, NULLIF(split_part(NEW.action, '.', 2), ''));
  NEW.guard := COALESCE(NEW.guard, ctx->>'g');
  NEW.origin := COALESCE(NEW.origin, ctx->>'o', CASE WHEN NEW.actor_id IS NULL AND NEW.actor_guardian_id IS NULL THEN 'system' ELSE 'staff' END);
  NEW.correlation_id := COALESCE(NEW.correlation_id, LEFT(ctx->>'c', 64));
  NEW.route_name := COALESCE(NEW.route_name, LEFT(ctx->>'r', 120));
  NEW.request_method := COALESCE(NEW.request_method, LEFT(ctx->>'m', 8));
  IF NEW.actor_id IS NULL AND NEW.actor_guardian_id IS NULL AND ctx->>'g' = 'guardian' THEN
    NEW.actor_guardian_id := NULLIF(ctx->>'gid', '')::bigint;
  END IF;
  NEW.actor_type := COALESCE(NEW.actor_type, CASE WHEN NEW.actor_id IS NOT NULL THEN 'user' WHEN NEW.actor_guardian_id IS NOT NULL THEN 'guardian'
    WHEN NEW.origin = 'worker' THEN 'worker' ELSE 'system' END);
  IF NEW.actor_id IS NOT NULL AND (NEW.actor_label IS NULL OR NEW.department_id IS NULL) THEN
    SELECT name, department_id INTO u FROM users WHERE id = NEW.actor_id;
    NEW.actor_label := COALESCE(NEW.actor_label, LEFT(u.name, 150));
    NEW.department_id := COALESCE(NEW.department_id, u.department_id);
  ELSIF NEW.actor_guardian_id IS NOT NULL AND NEW.actor_label IS NULL THEN
    NEW.actor_label := 'وصي (حساب بوابة)';
  END IF;
  NEW.outcome := COALESCE(NEW.outcome, 'success');
  NEW.sensitivity := COALESCE(NEW.sensitivity, CASE
    WHEN NEW.action ~* '(health|medical|loss_circ|restricted|salary|pay_change|payroll|identity_doc)' THEN 'restricted'
    WHEN NEW.module IN ('orphans', 'portal', 'field', 'hr') THEN 'personal'
    ELSE 'normal' END);
  NEW.severity := COALESCE(NEW.severity, CASE
    WHEN NEW.outcome <> 'success' THEN 'warning'
    WHEN NEW.action ~* '(approv|reject|decid|reset|delete|revok|role|permission|grant|deny|cancel|commit|applied|password|retire|purge|export)' THEN 'notice'
    ELSE 'info' END);
  -- Bounded payloads: an oversized metadata document is replaced by its size (never stored whole).
  IF NEW.metadata IS NOT NULL AND octet_length(NEW.metadata::text) > 16000 THEN
    NEW.metadata := json_build_object('truncated', true, 'bytes', octet_length(NEW.metadata::text));
  END IF;
  NEW.seal_seq := NULL;
  NEW.prev_hash := NULL;
  NEW.row_hash := NULL;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION aims_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND OLD.row_hash IS NULL AND NEW.row_hash IS NOT NULL
     AND (to_jsonb(NEW) - ARRAY['seal_seq', 'prev_hash', 'row_hash']) = (to_jsonb(OLD) - ARRAY['seal_seq', 'prev_hash', 'row_hash']) THEN
    RETURN NEW; -- the one-time seal written by audit:seal
  END IF;
  RAISE EXCEPTION 'AUDIT_APPEND_ONLY: audit_logs rows cannot be changed or removed (%).', TG_OP USING ERRCODE = '42501';
END $$;
SQL);

        // Enrich existing rows once (before the append-only rule exists); original columns are not touched.
        DB::unprepared(<<<'SQL'
UPDATE audit_logs a SET
  event_id = gen_random_uuid(), schema_version = 1,
  occurred_at = COALESCE(a.created_at, a.updated_at)::timestamptz, recorded_at = COALESCE(a.created_at, a.updated_at)::timestamptz,
  module = aims_audit_module(a.action), submodule = NULLIF(split_part(a.action, '.', 2), ''),
  origin = CASE WHEN a.actor_id IS NULL THEN 'system' ELSE 'staff' END,
  actor_type = CASE WHEN a.actor_id IS NULL THEN 'system' ELSE 'user' END,
  actor_label = (SELECT LEFT(u.name, 150) FROM users u WHERE u.id = a.actor_id),
  department_id = (SELECT u.department_id FROM users u WHERE u.id = a.actor_id),
  outcome = 'success',
  sensitivity = CASE WHEN a.action ~* '(health|medical|loss_circ|restricted|salary|pay_change|payroll|identity_doc)' THEN 'restricted'
    WHEN aims_audit_module(a.action) IN ('orphans', 'portal', 'field', 'hr') THEN 'personal' ELSE 'normal' END,
  severity = CASE WHEN a.action ~* '(approv|reject|decid|reset|delete|revok|role|permission|grant|deny|cancel|commit|applied|password|retire|purge|export)' THEN 'notice' ELSE 'info' END
WHERE a.event_id IS NULL;
SQL);
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN event_id SET NOT NULL');

        DB::statement('CREATE UNIQUE INDEX audit_logs_event_id_unique ON audit_logs (event_id)');
        DB::statement('CREATE UNIQUE INDEX audit_logs_seal_seq_unique ON audit_logs (seal_seq) WHERE seal_seq IS NOT NULL');
        DB::statement('CREATE INDEX audit_logs_occurred_idx ON audit_logs (occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX audit_logs_module_idx ON audit_logs (module, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_action_idx ON audit_logs (action)');
        DB::statement('CREATE INDEX audit_logs_department_idx ON audit_logs (department_id, occurred_at DESC)');
        DB::statement("CREATE INDEX audit_logs_outcome_idx ON audit_logs (outcome, occurred_at DESC) WHERE outcome <> 'success'");
        DB::statement('CREATE INDEX audit_logs_correlation_idx ON audit_logs (correlation_id) WHERE correlation_id IS NOT NULL');
        DB::statement('CREATE INDEX audit_logs_unsealed_idx ON audit_logs (id) WHERE row_hash IS NULL');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_logs_fill BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION aims_audit_fill();
CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION aims_audit_append_only();
CREATE OR REPLACE FUNCTION aims_audit_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'AUDIT_APPEND_ONLY: audit_logs cannot be truncated.' USING ERRCODE = '42501'; END $$;
CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION aims_audit_no_truncate();
SQL);

        // Seal anchors: the last hash of each sealing run (also written to storage by audit:seal for off-database copies).
        Schema::create('audit_seals', function ($t) {
            $t->id();
            $t->unsignedBigInteger('from_seq');
            $t->unsignedBigInteger('to_seq');
            $t->unsignedInteger('rows');
            $t->char('last_hash', 64);
            $t->timestampTz('sealed_at')->useCurrent();
        });

        foreach (self::BRIDGES as $table => [$action, $actor, $guardian, $type, $id, $meta, $origin]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION aims_audit_bridge_{$table}() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
  INSERT INTO audit_logs (actor_id, actor_guardian_id, action, subject_type, subject_id, metadata, created_at, updated_at, origin)
  VALUES ({$actor}, {$guardian}, LEFT(COALESCE({$action}, TG_TABLE_NAME || '.event'), 255), {$type}, {$id},
    ({$meta} || jsonb_build_object('source', TG_TABLE_NAME, 'source_id', NEW.id))::json, now()::timestamp, now()::timestamp, {$origin});
  RETURN NEW;
END \$\$;
CREATE TRIGGER audit_bridge AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION aims_audit_bridge_{$table}();
SQL);
        }
        // Offline sync: a field event's review/conflict resolution is a decision of its own.
        if (Schema::hasTable('field_events')) {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION aims_audit_bridge_field_status() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status IS DISTINCT FROM OLD.status THEN
    INSERT INTO audit_logs (actor_id, action, subject_type, subject_id, metadata, created_at, updated_at, origin)
    VALUES (NEW.reviewed_by, LEFT('field.event.status_' || NEW.status, 255), 'campaign_batches', NEW.batch_id::text,
      json_build_object('source', 'field_events', 'source_id', NEW.id, 'event_uuid', NEW.event_uuid, 'from', OLD.status, 'to', NEW.status), now()::timestamp, now()::timestamp, NULL);
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER audit_bridge_status AFTER UPDATE OF status ON field_events FOR EACH ROW EXECUTE FUNCTION aims_audit_bridge_field_status();
SQL);
        }
    }

    public function down(): void
    {
        // Guarded: once anything was sealed the chain is evidence and is not dropped by a rollback.
        if (Schema::hasTable('audit_seals') && DB::table('audit_seals')->exists()) {
            throw new RuntimeException('سجل النظام مختوم؛ لا يُلغى هذا التحديث بالتراجع.');
        }
        foreach (array_keys(self::BRIDGES) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS audit_bridge ON {$table}; DROP FUNCTION IF EXISTS aims_audit_bridge_{$table}();");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS audit_bridge_status ON field_events; DROP FUNCTION IF EXISTS aims_audit_bridge_field_status();');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_fill ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;');
        DB::unprepared('DROP FUNCTION IF EXISTS aims_audit_fill(); DROP FUNCTION IF EXISTS aims_audit_append_only(); DROP FUNCTION IF EXISTS aims_audit_no_truncate(); DROP FUNCTION IF EXISTS aims_audit_module(text);');
        Schema::dropIfExists('audit_seals');
        foreach (['audit_logs_outcome_check', 'audit_logs_sensitivity_check', 'audit_logs_severity_check'] as $check) {
            DB::statement("ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS {$check}");
        }
        foreach (['audit_logs_event_id_unique', 'audit_logs_seal_seq_unique', 'audit_logs_occurred_idx', 'audit_logs_module_idx', 'audit_logs_actor_idx', 'audit_logs_action_idx',
            'audit_logs_department_idx', 'audit_logs_outcome_idx', 'audit_logs_correlation_idx', 'audit_logs_unsealed_idx'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
        Schema::table('audit_logs', function ($t) {
            $t->dropColumn(['event_id', 'schema_version', 'occurred_at', 'recorded_at', 'actor_type', 'actor_guardian_id', 'actor_label', 'department_id', 'origin', 'guard', 'module',
                'submodule', 'outcome', 'reason_code', 'severity', 'sensitivity', 'subject_ref', 'correlation_id', 'route_name', 'request_method', 'seal_seq', 'prev_hash', 'row_hash']);
        });
    }
};
