<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Engineering 01, DB-01: one person is never the father of one orphan and the mother of another.
 *
 * A constraint trigger on orphan_parent_links refuses a link whose person is already linked under the other relation.
 * It checks NEW links only: an insert, or an update that changes person_id or relation. The existing rows that break
 * the rule (254 persons on 2026-11-28) stay as recorded until the Orphans department head corrects them through
 * «التعديلات الحساسة» (read-only list: /orphans/reviews/parent-links); an update that leaves the person and relation as
 * they are (verification, notes) is never refused.
 *
 * The trigger is DEFERRABLE INITIALLY IMMEDIATE: every path is checked at the end of its statement, except the one
 * correction that needs both links changed together. Registry::linkParent, when it swaps the father and mother of a
 * file and its siblings with the same pair, runs SET CONSTRAINTS orphan_parent_links_one_relation DEFERRED for the
 * swap and IMMEDIATE again before its savepoint ends, so the rule is checked once on the final rows, inside the
 * transaction (the function re-reads the row, which may have changed again since the event). A transaction-scoped advisory
 * lock per person closes the race of two concurrent links. No business row is changed. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION orphan_parent_links_one_relation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    cur_person bigint;
    cur_relation text;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.person_id IS NOT DISTINCT FROM OLD.person_id AND NEW.relation IS NOT DISTINCT FROM OLD.relation THEN
        RETURN NULL;
    END IF;
    SELECT l.person_id, l.relation INTO cur_person, cur_relation FROM orphan_parent_links l WHERE l.id = NEW.id;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    PERFORM pg_advisory_xact_lock(42043, cur_person::int);
    IF EXISTS (SELECT 1 FROM orphan_parent_links l WHERE l.person_id = cur_person AND l.relation <> cur_relation AND l.id <> NEW.id) THEN
        RAISE EXCEPTION 'هذا الشخص مربوط في السجل بصفة %؛ لا يُربط أبًا وأمًّا معًا. راجع رقم الهوية.',
            CASE cur_relation WHEN 'father' THEN 'أم' ELSE 'أب' END
            USING ERRCODE = 'check_violation', CONSTRAINT = 'orphan_parent_links_one_relation';
    END IF;
    RETURN NULL;
END;
$$;
DROP TRIGGER IF EXISTS orphan_parent_links_one_relation ON orphan_parent_links;
CREATE CONSTRAINT TRIGGER orphan_parent_links_one_relation AFTER INSERT OR UPDATE OF person_id, relation ON orphan_parent_links
    DEFERRABLE INITIALLY IMMEDIATE
    FOR EACH ROW EXECUTE FUNCTION orphan_parent_links_one_relation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS orphan_parent_links_one_relation ON orphan_parent_links;
DROP FUNCTION IF EXISTS orphan_parent_links_one_relation();
SQL);
    }
};
