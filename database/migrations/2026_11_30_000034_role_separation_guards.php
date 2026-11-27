<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role separation backstop (owner decisions 5 and 6, 2026-11-27), the same pairs as
 * App\Services\Organization\RoleSeparation::RULES: one person never holds, in overlapping appointment periods
 * (starts_on/ends_on; a missing start counts from today, so a new designation does not reach back over an
 * appointment that already ended; a missing end is open),
 * - board_secretary together with secretariat («أمين سر مجلس الإدارة» and «السكرتاريا» stay separate);
 * - finance_board_treasurer together with system_admin.
 * A BEFORE INSERT/UPDATE trigger on department_user_roles refuses a row that would break a rule; the screens check
 * first and give the Arabic reason, so the trigger only catches a path that forgot to. Department headship
 * (departments.head_user_id) grants none of these roles and needs no trigger.
 *
 * Existing rows are checked first: the migration stops, changing nothing, if any person already breaks a rule
 * (none does on 2026-11-27). No business row is changed. Safe to run twice.
 */
return new class extends Migration
{
    private const PAIRS = "(('board_secretary','secretariat'),('finance_board_treasurer','system_admin'))";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aims_assignment_period(starts date, ends date) RETURNS daterange
            LANGUAGE sql IMMUTABLE AS $$
                SELECT CASE WHEN starts IS NOT NULL AND ends IS NOT NULL AND starts > ends THEN 'empty'::daterange
                            ELSE daterange(starts, ends, '[]') END
            $$
            SQL);

        $violations = DB::select('SELECT a.user_id, ra.code AS role, rb.code AS other
            FROM department_user_roles a JOIN roles ra ON ra.id = a.role_id
            JOIN department_user_roles b ON b.user_id = a.user_id AND b.id <> a.id JOIN roles rb ON rb.id = b.role_id
            WHERE (ra.code, rb.code) IN '.self::PAIRS.'
              AND aims_assignment_period(coalesce(a.starts_on, current_date), a.ends_on) && aims_assignment_period(coalesce(b.starts_on, current_date), b.ends_on)
            ORDER BY a.user_id');
        if ($violations !== []) {
            $list = implode('; ', array_map(fn (object $row): string => "user {$row->user_id}: {$row->role} + {$row->other}", $violations));
            throw new RuntimeException("Role separation: existing assignments already break the owner's rules ({$list}). End one of the two assignments of each person through the screens, then run the migration again.");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aims_role_separation_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                new_code text;
                other_code text;
            BEGIN
                SELECT code INTO new_code FROM roles WHERE id = NEW.role_id;
                IF new_code IS NULL OR new_code NOT IN ('board_secretary', 'secretariat', 'finance_board_treasurer', 'system_admin') THEN
                    RETURN NEW;
                END IF;
                -- Serialises concurrent assignments of the same person.
                PERFORM pg_advisory_xact_lock(hashtext('aims_role_separation'), NEW.user_id::int);
                SELECT r.code INTO other_code
                FROM department_user_roles a JOIN roles r ON r.id = a.role_id
                WHERE a.user_id = NEW.user_id AND a.id IS DISTINCT FROM NEW.id
                  AND aims_assignment_period(a.starts_on, a.ends_on) && aims_assignment_period(coalesce(NEW.starts_on, current_date), NEW.ends_on)
                  AND ((new_code = 'board_secretary' AND r.code = 'secretariat')
                    OR (new_code = 'secretariat' AND r.code = 'board_secretary')
                    OR (new_code = 'finance_board_treasurer' AND r.code = 'system_admin')
                    OR (new_code = 'system_admin' AND r.code = 'finance_board_treasurer'))
                LIMIT 1;
                IF other_code IS NOT NULL THEN
                    RAISE EXCEPTION 'role separation: user % cannot hold % together with %', NEW.user_id, new_code, other_code
                        USING ERRCODE = 'check_violation',
                              HINT = 'Owner decisions 2026-11-27: board secretary and Secretariat stay separate; the Board treasurer is never the system administrator.';
                END IF;
                RETURN NEW;
            END
            $$;
            DROP TRIGGER IF EXISTS department_user_roles_role_separation ON department_user_roles;
            CREATE TRIGGER department_user_roles_role_separation
                BEFORE INSERT OR UPDATE OF user_id, role_id, starts_on, ends_on ON department_user_roles
                FOR EACH ROW EXECUTE FUNCTION aims_role_separation_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS department_user_roles_role_separation ON department_user_roles;
            DROP FUNCTION IF EXISTS aims_role_separation_guard();
            DROP FUNCTION IF EXISTS aims_assignment_period(date, date);
            SQL);
    }
};
