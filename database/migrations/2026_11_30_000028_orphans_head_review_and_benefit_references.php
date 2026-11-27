<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orphans and sponsorships, wave 2 (no business row is changed):
 *  - ORP-08: a campaign list reaches the executive director only after the head's review. Entering
 *    «pending_executive» needs recommended_by/recommended_at, and approval needs recommended_by (mirrors
 *    Campaigns::submit/decide). Lists already pending without a recommendation stay pending; they can be rejected
 *    or withdrawn by the head and referred again, not approved. Only the guard's function body changes.
 *    campaign_lists.review_requested_at/by keep the preparer's request for review on the draft, so it stays in the
 *    head's work queue (a muted notification cannot lose it).
 *  - CC-04: benefit_records.fulfillment_kind types the execution reference (minute, Stores issue, Accounts
 *    document). Existing rows keep NULL (legacy, untyped). Adding a column fires no row trigger, so the
 *    append-only guard of benefit_records is untouched.
 *  - ORP-16: orphan_appeals.assigned_by records who assigned an appeal, so only a head's assignment (or the head's
 *    escalation to the executive director) carries the right to decide it.
 *  - Two in-app notification events (head review of a list, overdue referred sponsorship dues).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(self::guard(true));

        // The preparer's «رفع لرئيس القسم»: kept on the draft so it stays in the head's work queue until referred.
        DB::statement('ALTER TABLE campaign_lists ADD COLUMN IF NOT EXISTS review_requested_at timestamp(0) without time zone');
        DB::statement('ALTER TABLE campaign_lists ADD COLUMN IF NOT EXISTS review_requested_by bigint');
        if (! DB::table('pg_constraint')->where('conname', 'campaign_lists_review_requested_by_foreign')->exists()) {
            DB::statement('ALTER TABLE campaign_lists ADD CONSTRAINT campaign_lists_review_requested_by_foreign FOREIGN KEY (review_requested_by) REFERENCES users (id) ON DELETE RESTRICT');
        }

        DB::statement('ALTER TABLE benefit_records ADD COLUMN IF NOT EXISTS fulfillment_kind varchar(16)');
        if (! DB::table('pg_constraint')->where('conname', 'benefit_records_fulfillment_kind')->exists()) {
            DB::statement("ALTER TABLE benefit_records ADD CONSTRAINT benefit_records_fulfillment_kind CHECK (fulfillment_kind IS NULL OR fulfillment_kind IN ('handover','stock_movement','finance_document'))");
        }

        DB::statement('ALTER TABLE orphan_appeals ADD COLUMN IF NOT EXISTS assigned_by bigint');
        if (! DB::table('pg_constraint')->where('conname', 'orphan_appeals_assigned_by_foreign')->exists()) {
            DB::statement('ALTER TABLE orphan_appeals ADD CONSTRAINT orphan_appeals_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE RESTRICT');
        }

        foreach ([['orphans_campaign_review', 'كشف حملة بانتظار مراجعة رئيس القسم', 'إشعار رئيس قسم الأيتام عندما يرفع معدّ الحملة كشفًا لمراجعته قبل رفعه للمدير التنفيذي.'],
            ['orphans_sponsorship_overdue', 'استحقاقات كفالة متأخرة أو جداول متوقفة', 'إشعار يومي لرئيس قسم الأيتام بعدد استحقاقات الكفالات المحالة للحسابات التي تجاوزت موعدها دون تحصيل كامل، وبالكفالات النشطة التي توقف تمديد جدولها لأن ملف مستفيد فيها لم يعد معتمدًا.']] as [$code, $label, $description]) {
            if (! DB::table('notification_settings')->where('event_code', $code)->exists()) {
                DB::table('notification_settings')->insert(['event_code' => $code, 'label_ar' => $label, 'description' => $description, 'in_app_enabled' => true, 'sms_enabled' => false,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Restores the 2026_11_30_000026 guard. The new columns are kept once they hold records.
        DB::unprepared(self::guard(false));
        if (DB::table('benefit_records')->whereNotNull('fulfillment_kind')->exists() || DB::table('orphan_appeals')->whereNotNull('assigned_by')->exists()) {
            throw new RuntimeException('Typed benefit references or appeal assignments are records; the guard was restored, the columns are kept.');
        }
        DB::statement('ALTER TABLE campaign_lists DROP CONSTRAINT IF EXISTS campaign_lists_review_requested_by_foreign');
        DB::statement('ALTER TABLE campaign_lists DROP COLUMN IF EXISTS review_requested_by');
        DB::statement('ALTER TABLE campaign_lists DROP COLUMN IF EXISTS review_requested_at');
        DB::statement('ALTER TABLE benefit_records DROP CONSTRAINT IF EXISTS benefit_records_fulfillment_kind');
        DB::statement('ALTER TABLE benefit_records DROP COLUMN IF EXISTS fulfillment_kind');
        DB::statement('ALTER TABLE orphan_appeals DROP CONSTRAINT IF EXISTS orphan_appeals_assigned_by_foreign');
        DB::statement('ALTER TABLE orphan_appeals DROP COLUMN IF EXISTS assigned_by');
    }

    /** campaign_lists_guard: the 000026 body, plus (when $headReview) the head's recommendation before the director. */
    private static function guard(bool $headReview): string
    {
        $review = $headReview ? <<<'SQL'
    IF NEW.status = 'pending_executive' AND OLD.status IS DISTINCT FROM 'pending_executive' AND (NEW.recommended_by IS NULL OR NEW.recommended_at IS NULL) THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_REVIEW: a list reaches the executive director only after the head reviews and recommends it' USING ERRCODE = '23514';
    END IF;
    IF NEW.status = 'approved' AND NEW.recommended_by IS NULL THEN
        RAISE EXCEPTION 'CAMPAIGN_LIST_REVIEW: a list without the head''s recommendation cannot be approved' USING ERRCODE = '23514';
    END IF;
SQL : '';

        return <<<SQL
CREATE OR REPLACE FUNCTION campaign_lists_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
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
{$review}
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
END \$\$;
SQL;
    }
};
