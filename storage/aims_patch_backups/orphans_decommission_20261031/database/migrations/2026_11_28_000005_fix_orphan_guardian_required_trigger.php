<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix of 2026_11_28_000003: PL/pgSQL resolves every record field of one expression, so a single CASE over NEW/OLD
 * failed on orphan_children. The rule is unchanged: every child keeps at least one live guardian link at commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orphan_child_requires_guardian() RETURNS trigger LANGUAGE plpgsql AS $$
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
        SQL);
    }

    public function down(): void
    {
        // The corrected function is kept: the earlier form could not run.
    }
};
