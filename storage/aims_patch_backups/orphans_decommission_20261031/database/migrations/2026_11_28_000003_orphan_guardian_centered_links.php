<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guardian-centred registry (owner decision 2026-10-30):
 * - every child keeps at least one live link to a legal guardian («الوصي الشرعي»), checked by the database at commit;
 *   the last live link cannot be removed without a replacement in the same transaction;
 * - a link starts «بانتظار التحقق» (pending) and is approved per child; at most ONE approved live guardian per child;
 * - staff may register a guardian without a portal login (password/phone optional, origin «staff»);
 * - name search indexes (keyed hashes of name parts and word prefixes, never readable names) for encrypted child names.
 *
 * Additive: the affected tables are empty at the time of writing. down() restores the previous definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Links: verification state, origin, evidence, creator and decision.
            DB::statement("ALTER TABLE orphan_guardianships
                ADD COLUMN status varchar(10) NOT NULL DEFAULT 'approved',
                ADD COLUMN origin varchar(12) NOT NULL DEFAULT 'application',
                ADD COLUMN evidence_note text NULL,
                ADD COLUMN created_by bigint NULL REFERENCES users(id) ON DELETE RESTRICT,
                ADD COLUMN decided_by bigint NULL REFERENCES users(id) ON DELETE RESTRICT,
                ADD COLUMN decided_at timestamp(0) NULL,
                ADD COLUMN decision_note text NULL,
                ALTER COLUMN approved_by DROP NOT NULL,
                ALTER COLUMN starts_on DROP NOT NULL");
            DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_source');
            DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (
                origin IN ('staff','import') OR guardian_application_id IS NOT NULL OR orphan_application_id IS NOT NULL OR orphan_change_request_id IS NOT NULL)");
            DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_state CHECK (
                status IN ('pending','approved','rejected') AND origin IN ('application','portal','staff','import','change')
                AND (status <> 'approved' OR (approved_by IS NOT NULL AND starts_on IS NOT NULL))
                AND (status <> 'rejected' OR (ended_reason IS NOT NULL AND decision_note IS NOT NULL)))");
            // One approved live guardian per child; pending claims may coexist and are decided one by one.
            DB::statement("CREATE UNIQUE INDEX orphan_guardianships_one_approved ON orphan_guardianships (orphan_child_id) WHERE status = 'approved' AND ended_reason IS NULL");
            DB::statement('CREATE INDEX orphan_guardianships_child_live ON orphan_guardianships (orphan_child_id) WHERE ended_reason IS NULL');
            DB::statement("ALTER TABLE orphan_guardianships ALTER COLUMN status SET DEFAULT 'pending'");

            // «لا يتيم بلا وصي»: checked at commit so a child and its first link (or a replacement) are written together.
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION orphan_child_requires_guardian() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE child bigint;
                BEGIN
                    IF TG_TABLE_NAME = 'orphan_children' THEN child := NEW.id; ELSE child := OLD.orphan_child_id; END IF;
                    IF EXISTS (SELECT 1 FROM orphan_children WHERE id = child)
                       AND NOT EXISTS (SELECT 1 FROM orphan_guardianships WHERE orphan_child_id = child AND ended_reason IS NULL AND status IN ('pending','approved')) THEN
                        RAISE EXCEPTION 'orphan_child_without_guardian' USING ERRCODE = '23514',
                            DETAIL = 'كل يتيم يجب أن يرتبط بوصي واحد على الأقل؛ لا يُحفظ يتيم بلا وصي ولا يُزال آخر وصي دون بديل.';
                    END IF;
                    RETURN NULL;
                END $$;
                CREATE CONSTRAINT TRIGGER orphan_children_guardian_required AFTER INSERT OR UPDATE ON orphan_children
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION orphan_child_requires_guardian();
                CREATE CONSTRAINT TRIGGER orphan_guardianships_keep_one AFTER UPDATE OR DELETE ON orphan_guardianships
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION orphan_child_requires_guardian();
            SQL);

            // Guardians: a staff-registered guardian has no portal login until one is activated.
            DB::statement("ALTER TABLE guardians
                ALTER COLUMN password DROP NOT NULL,
                ALTER COLUMN phone DROP NOT NULL,
                ADD COLUMN origin varchar(10) NOT NULL DEFAULT 'portal',
                ADD COLUMN name_parts text NULL,
                ADD COLUMN name_index text[] NOT NULL DEFAULT '{}'");
            DB::statement("ALTER TABLE guardians ADD CONSTRAINT guardians_origin CHECK (origin IN ('portal','staff') AND (origin = 'staff' OR (password IS NOT NULL AND phone IS NOT NULL)))");
            DB::statement('CREATE INDEX guardians_name_index ON guardians USING gin (name_index)');
            DB::statement('CREATE SEQUENCE IF NOT EXISTS orphan_guardian_ref_seq');

            // Children: keyed-hash search tokens (the names themselves stay encrypted).
            DB::statement("ALTER TABLE orphan_children ADD COLUMN name_index text[] NOT NULL DEFAULT '{}'");
            DB::statement('CREATE INDEX orphan_children_name_index ON orphan_children USING gin (name_index)');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            abort_if(DB::table('orphan_guardianships')->where('status', '<>', 'approved')->exists() || DB::table('guardians')->where('origin', 'staff')->exists(),
                409, 'Pending links or staff-registered guardians exist; not reverted.');
            DB::unprepared('DROP TRIGGER IF EXISTS orphan_children_guardian_required ON orphan_children; DROP TRIGGER IF EXISTS orphan_guardianships_keep_one ON orphan_guardianships;
                DROP FUNCTION IF EXISTS orphan_child_requires_guardian();');
            DB::statement('DROP INDEX IF EXISTS orphan_children_name_index');
            DB::statement('ALTER TABLE orphan_children DROP COLUMN IF EXISTS name_index');
            DB::statement('ALTER TABLE guardians DROP CONSTRAINT IF EXISTS guardians_origin');
            DB::statement('DROP INDEX IF EXISTS guardians_name_index');
            DB::statement('ALTER TABLE guardians DROP COLUMN IF EXISTS origin, DROP COLUMN IF EXISTS name_parts, DROP COLUMN IF EXISTS name_index, ALTER COLUMN password SET NOT NULL, ALTER COLUMN phone SET NOT NULL');
            DB::statement('DROP SEQUENCE IF EXISTS orphan_guardian_ref_seq');
            DB::statement('DROP INDEX IF EXISTS orphan_guardianships_one_approved');
            DB::statement('DROP INDEX IF EXISTS orphan_guardianships_child_live');
            DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_state');
            DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_source');
            DB::statement('ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (guardian_application_id IS NOT NULL OR orphan_application_id IS NOT NULL OR orphan_change_request_id IS NOT NULL)');
            DB::statement('ALTER TABLE orphan_guardianships DROP COLUMN status, DROP COLUMN origin, DROP COLUMN evidence_note, DROP COLUMN created_by, DROP COLUMN decided_by, DROP COLUMN decided_at, DROP COLUMN decision_note,
                ALTER COLUMN approved_by SET NOT NULL, ALTER COLUMN starts_on SET NOT NULL');
        });
    }
};
