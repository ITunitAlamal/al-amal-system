<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 5, group W5C (no existing business row is changed):
 *  - ACC-15: cancellation of an approved purchase request / issued order / undelivered remainder (cancelled_by,
 *    cancelled_at, cancellation_reason on finance_purchase_requests and finance_purchase_orders), and rejected
 *    quantities with their reason on goods-receipt lines (rejected_qty, rejection_reason).
 *  - ACC-16: finance_stock_movements and finance_purchase_receipt_items are append-only at the database level:
 *    UPDATE, DELETE and TRUNCATE raise an error, as the payroll and audit-log guards do.
 *  - ACC-22: aims_rental_receipt_vouchers keeps one row per external receipt voucher (reference, amount, currency,
 *    date) so the allocations of one voucher never exceed its amount; the reference is unique (trimmed, any case).
 *  - ORP-19: the head's review of a completed social visit (reviewed_by, reviewed_at, review_outcome, review_note on
 *    social_assessments; never the person who completed it), and the researcher assignment and the head's
 *    recommendation on household_case_studies (assigned_to, assigned_by, review_recommendation).
 *  - In-app notification events finance_purchase_pending and finance_purchase_decided (ACC-12).
 * Safe to run twice.
 */
return new class extends Migration
{
    private const EVENTS = [
        'finance_purchase_pending' => ['طلب شراء بانتظار قرارك', 'لمن يقرر المرحلة التالية من طلب الشراء: مراجعة رئيس الحسابات أو اعتماد المدير التنفيذي أو موافقة أمين الصندوق.'],
        'finance_purchase_decided' => ['قرار على طلب شراء', 'لمن أعدّ طلب الشراء أو طلبه: الاعتماد أو الرفض أو الإعادة أو الإلغاء.'],
    ];

    public function up(): void
    {
        foreach (['finance_purchase_requests', 'finance_purchase_orders'] as $table) {
            if (! Schema::hasColumn($table, 'cancelled_by')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                    $t->timestamp('cancelled_at')->nullable();
                    $t->text('cancellation_reason')->nullable();
                });
            }
        }
        DB::statement('ALTER TABLE finance_purchase_requests DROP CONSTRAINT IF EXISTS finance_purchase_requests_cancel_check');
        DB::statement("ALTER TABLE finance_purchase_requests ADD CONSTRAINT finance_purchase_requests_cancel_check
            CHECK (state <> 'cancelled' OR (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) >= 10)) NOT VALID");

        if (! Schema::hasColumn('finance_purchase_receipt_items', 'rejected_qty')) {
            Schema::table('finance_purchase_receipt_items', function (Blueprint $t): void {
                $t->decimal('rejected_qty', 18, 3)->default(0);
                $t->string('rejection_reason', 500)->nullable();
            });
        }
        // NOT VALID: checked for every new line; legacy lines are left as recorded.
        DB::statement('ALTER TABLE finance_purchase_receipt_items DROP CONSTRAINT IF EXISTS finance_purchase_receipt_items_qty_check');
        DB::statement("ALTER TABLE finance_purchase_receipt_items ADD CONSTRAINT finance_purchase_receipt_items_qty_check
            CHECK (quantity >= 0 AND rejected_qty >= 0 AND (quantity > 0 OR rejected_qty > 0) AND (rejected_qty = 0 OR length(btrim(coalesce(rejection_reason, ''))) > 0)) NOT VALID");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION finance_stock_ledger_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'السجل % لا يُعدَّل ولا يُحذف؛ يُصحَّح بحركة عكسية موثقة.', TG_TABLE_NAME
        USING ERRCODE = 'insufficient_privilege';
END;
$$;
DROP TRIGGER IF EXISTS finance_stock_movements_append_only ON finance_stock_movements;
CREATE TRIGGER finance_stock_movements_append_only BEFORE UPDATE OR DELETE ON finance_stock_movements FOR EACH ROW EXECUTE FUNCTION finance_stock_ledger_append_only();
DROP TRIGGER IF EXISTS finance_stock_movements_no_truncate ON finance_stock_movements;
CREATE TRIGGER finance_stock_movements_no_truncate BEFORE TRUNCATE ON finance_stock_movements FOR EACH STATEMENT EXECUTE FUNCTION finance_stock_ledger_append_only();
DROP TRIGGER IF EXISTS finance_purchase_receipt_items_append_only ON finance_purchase_receipt_items;
CREATE TRIGGER finance_purchase_receipt_items_append_only BEFORE UPDATE OR DELETE ON finance_purchase_receipt_items FOR EACH ROW EXECUTE FUNCTION finance_stock_ledger_append_only();
DROP TRIGGER IF EXISTS finance_purchase_receipt_items_no_truncate ON finance_purchase_receipt_items;
CREATE TRIGGER finance_purchase_receipt_items_no_truncate BEFORE TRUNCATE ON finance_purchase_receipt_items FOR EACH STATEMENT EXECUTE FUNCTION finance_stock_ledger_append_only();
SQL);

        if (! Schema::hasTable('aims_rental_receipt_vouchers')) {
            Schema::create('aims_rental_receipt_vouchers', function (Blueprint $t): void {
                $t->id();
                $t->string('reference', 150);
                $t->decimal('amount', 18, 2);
                $t->string('currency_code', 10);
                $t->date('posted_on');
                $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE aims_rental_receipt_vouchers ADD CONSTRAINT aims_rental_receipt_vouchers_amount_check CHECK (amount > 0)');
            DB::statement('CREATE UNIQUE INDEX aims_rental_receipt_vouchers_reference_unique ON aims_rental_receipt_vouchers (lower(btrim(reference)))');
        }

        if (! Schema::hasColumn('social_assessments', 'review_outcome')) {
            Schema::table('social_assessments', function (Blueprint $t): void {
                $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('reviewed_at')->nullable();
                $t->string('review_outcome', 10)->nullable();
                $t->text('review_note')->nullable();
            });
        }
        DB::statement('ALTER TABLE social_assessments DROP CONSTRAINT IF EXISTS social_assessments_review_check');
        DB::statement("ALTER TABLE social_assessments ADD CONSTRAINT social_assessments_review_check CHECK (review_outcome IS NULL OR (
            review_outcome IN ('approved', 'rejected') AND status = 'completed' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL
            AND reviewed_by IS DISTINCT FROM completed_by AND (review_outcome = 'approved' OR length(btrim(coalesce(review_note, ''))) > 0)))");

        if (! Schema::hasColumn('household_case_studies', 'assigned_to')) {
            Schema::table('household_case_studies', function (Blueprint $t): void {
                $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('review_recommendation', 10)->nullable();
            });
        }
        DB::statement('ALTER TABLE household_case_studies DROP CONSTRAINT IF EXISTS household_case_studies_recommendation_check');
        DB::statement("ALTER TABLE household_case_studies ADD CONSTRAINT household_case_studies_recommendation_check
            CHECK (review_recommendation IS NULL OR (review_recommendation IN ('accept', 'reject', 'defer') AND status = 'reviewed'))");

        $now = now();
        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Cancellations, rejected deliveries, voucher totals and reviews are records of decisions: refused once any exist.
        $used = [
            'finance_purchase_requests' => fn () => Schema::hasColumn('finance_purchase_requests', 'cancelled_by') && DB::table('finance_purchase_requests')->whereNotNull('cancelled_by')->exists(),
            'finance_purchase_orders' => fn () => Schema::hasColumn('finance_purchase_orders', 'cancelled_by') && DB::table('finance_purchase_orders')->whereNotNull('cancelled_by')->exists(),
            'finance_purchase_receipt_items' => fn () => Schema::hasColumn('finance_purchase_receipt_items', 'rejected_qty') && DB::table('finance_purchase_receipt_items')->where('rejected_qty', '>', 0)->exists(),
            'aims_rental_receipt_vouchers' => fn () => Schema::hasTable('aims_rental_receipt_vouchers') && DB::table('aims_rental_receipt_vouchers')->exists(),
            'social_assessments' => fn () => Schema::hasColumn('social_assessments', 'review_outcome') && DB::table('social_assessments')->whereNotNull('review_outcome')->exists(),
            'household_case_studies' => fn () => Schema::hasColumn('household_case_studies', 'assigned_to') && DB::table('household_case_studies')->where(fn ($q) => $q->whereNotNull('assigned_to')->orWhereNotNull('review_recommendation'))->exists(),
        ];
        foreach ($used as $table => $check) {
            if ($check()) {
                throw new RuntimeException("{$table} holds records of this migration; it cannot be rolled back.");
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS finance_stock_movements_append_only ON finance_stock_movements;
            DROP TRIGGER IF EXISTS finance_stock_movements_no_truncate ON finance_stock_movements;
            DROP TRIGGER IF EXISTS finance_purchase_receipt_items_append_only ON finance_purchase_receipt_items;
            DROP TRIGGER IF EXISTS finance_purchase_receipt_items_no_truncate ON finance_purchase_receipt_items;
            DROP FUNCTION IF EXISTS finance_stock_ledger_append_only()');
        DB::statement('ALTER TABLE finance_purchase_requests DROP CONSTRAINT IF EXISTS finance_purchase_requests_cancel_check');
        DB::statement('ALTER TABLE finance_purchase_receipt_items DROP CONSTRAINT IF EXISTS finance_purchase_receipt_items_qty_check');
        DB::statement('ALTER TABLE social_assessments DROP CONSTRAINT IF EXISTS social_assessments_review_check');
        DB::statement('ALTER TABLE household_case_studies DROP CONSTRAINT IF EXISTS household_case_studies_recommendation_check');
        foreach (['finance_purchase_requests', 'finance_purchase_orders'] as $table) {
            if (Schema::hasColumn($table, 'cancelled_by')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('cancelled_by'));
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['cancelled_at', 'cancellation_reason']));
            }
        }
        if (Schema::hasColumn('finance_purchase_receipt_items', 'rejected_qty')) {
            Schema::table('finance_purchase_receipt_items', fn (Blueprint $t) => $t->dropColumn(['rejected_qty', 'rejection_reason']));
        }
        Schema::dropIfExists('aims_rental_receipt_vouchers');
        if (Schema::hasColumn('social_assessments', 'review_outcome')) {
            Schema::table('social_assessments', fn (Blueprint $t) => $t->dropConstrainedForeignId('reviewed_by'));
            Schema::table('social_assessments', fn (Blueprint $t) => $t->dropColumn(['reviewed_at', 'review_outcome', 'review_note']));
        }
        if (Schema::hasColumn('household_case_studies', 'assigned_to')) {
            Schema::table('household_case_studies', fn (Blueprint $t) => $t->dropConstrainedForeignId('assigned_to'));
            Schema::table('household_case_studies', fn (Blueprint $t) => $t->dropConstrainedForeignId('assigned_by'));
            Schema::table('household_case_studies', fn (Blueprint $t) => $t->dropColumn('review_recommendation'));
        }
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
    }
};
