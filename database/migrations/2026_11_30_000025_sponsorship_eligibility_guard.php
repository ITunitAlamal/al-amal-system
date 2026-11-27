<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sponsorship eligibility gate in the database (TRANSFORM §12): a sponsorship may hold files not yet approved
 * while it is a draft, but it cannot ENTER «active» or «partial», and no beneficiary line can be added to an active
 * or partial sponsorship, while any of its live orphan lines is not an officially approved file (orphans.status
 * «active»). Household lines keep their own rule (none defined). Existing rows are not checked or changed: only a
 * new move or a new line is refused. Aid::assertFundable gives the same answer first, with a readable message.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sponsorships_fundable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    sid bigint;
    st text;
    blocked text;
BEGIN
    IF TG_TABLE_NAME = 'sponsorships' THEN
        sid := NEW.id;
        st := NEW.status;
    ELSE
        sid := NEW.sponsorship_id;
        SELECT status INTO st FROM sponsorships WHERE id = sid;
    END IF;
    IF st IS NULL OR st NOT IN ('active', 'partial') THEN
        RETURN NULL;
    END IF;
    SELECT string_agg(o.reference_no, ', ' ORDER BY o.reference_no) INTO blocked
      FROM sponsorship_beneficiaries b JOIN orphans o ON o.id = b.orphan_id
     WHERE b.sponsorship_id = sid AND b.ends_on IS NULL AND o.status <> 'active';
    IF blocked IS NOT NULL THEN
        RAISE EXCEPTION 'SPONSORSHIP_NOT_FUNDABLE: sponsorship % cannot be active while these files are not approved: %', sid, blocked USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END $$;

DROP TRIGGER IF EXISTS sponsorships_fundable_on_insert ON sponsorships;
CREATE CONSTRAINT TRIGGER sponsorships_fundable_on_insert AFTER INSERT ON sponsorships
    FOR EACH ROW WHEN (NEW.status IN ('active', 'partial')) EXECUTE FUNCTION sponsorships_fundable_guard();

DROP TRIGGER IF EXISTS sponsorships_fundable_on_status ON sponsorships;
CREATE CONSTRAINT TRIGGER sponsorships_fundable_on_status AFTER UPDATE OF status ON sponsorships
    FOR EACH ROW WHEN (NEW.status IN ('active', 'partial') AND OLD.status IS DISTINCT FROM NEW.status) EXECUTE FUNCTION sponsorships_fundable_guard();

DROP TRIGGER IF EXISTS sponsorship_beneficiaries_fundable ON sponsorship_beneficiaries;
CREATE CONSTRAINT TRIGGER sponsorship_beneficiaries_fundable AFTER INSERT OR UPDATE OF orphan_id, sponsorship_id, ends_on ON sponsorship_beneficiaries
    FOR EACH ROW WHEN (NEW.ends_on IS NULL) EXECUTE FUNCTION sponsorships_fundable_guard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS sponsorship_beneficiaries_fundable ON sponsorship_beneficiaries;
DROP TRIGGER IF EXISTS sponsorships_fundable_on_status ON sponsorships;
DROP TRIGGER IF EXISTS sponsorships_fundable_on_insert ON sponsorships;
DROP FUNCTION IF EXISTS sponsorships_fundable_guard();
SQL);
    }
};
