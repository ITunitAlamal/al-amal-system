<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campaign beneficiary lists and their amendments are decided (approved OR rejected) only by the original, officially
 * designated executive director (TRANSFORM §3.4, §13): the user in departments(code EXEC).head_user_id, active, who
 * holds an effective executive_director assignment in EXEC. A temporary or acting holder of the role elsewhere, a
 * delegate, the system admin or the department head no longer passes. Mirrors OrphanAccess::isDesignatedExecutive.
 * Only the function body changes (the trigger stays); down() restores the body of 2026_11_30_000005. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION campaign_lists_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
    IF NEW.status IN ('approved', 'rejected') THEN
        IF OLD.status <> 'pending_executive' THEN
            RAISE EXCEPTION 'CAMPAIGN_LIST_APPROVAL: only a version awaiting the executive director can be decided' USING ERRCODE = '23514';
        END IF;
        IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL OR NOT EXISTS (
            SELECT 1 FROM departments d
              JOIN users u ON u.id = d.head_user_id
              JOIN department_user_roles a ON a.user_id = d.head_user_id AND a.department_id = d.id
              JOIN roles r ON r.id = a.role_id
             WHERE d.code = 'EXEC' AND d.deleted_at IS NULL AND d.head_user_id = NEW.decided_by AND u.is_active AND r.code = 'executive_director'
               AND (a.starts_on IS NULL OR a.starts_on <= CURRENT_DATE) AND (a.ends_on IS NULL OR a.ends_on >= CURRENT_DATE)) THEN
            RAISE EXCEPTION 'CAMPAIGN_LIST_APPROVAL: only the officially designated executive director decides a beneficiary list' USING ERRCODE = '42501';
        END IF;
    END IF;
    IF OLD.status = 'pending_executive' AND NEW.status NOT IN ('approved','rejected','draft','pending_executive') THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_LOCKED: invalid transition' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION campaign_lists_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
SQL);
    }
};
