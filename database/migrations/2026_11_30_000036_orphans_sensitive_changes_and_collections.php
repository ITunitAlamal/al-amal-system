<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 4, group W4B (no existing business row is changed):
 *  - ORP-18: orphan_sensitive_changes keeps a staff change to an approved file (name, identity, birth date, sex,
 *    life status, parent link, orphanhood class) with its old and new values (encrypted payload) until the head of
 *    the department decides it. One pending request per subject (partial unique index); the decider is never the
 *    author (CHECK); a decided request is immutable and no request is deleted (trigger).
 *  - ACC-09: sponsorship_receipt_allocations records a receipt made in the external accounting software against a
 *    referred sponsorship due (reference, date, amount). Same reference counts once per due while not reversed; the
 *    total recorded never exceeds the due; rows are reversed, never deleted or rewritten (trigger).
 *    Permission finance.sponsorships.record is created only; it is attached to no role (the owner decides the role,
 *    or the ED a grant).
 *  - In-app notification event orphans_sensitive_change.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS orphan_sensitive_change_seq');
        if (! Schema::hasTable('orphan_sensitive_changes')) {
            Schema::create('orphan_sensitive_changes', function (Blueprint $t): void {
                $t->id();
                $t->ulid('public_id')->unique();
                $t->string('reference_no', 30)->unique();
                $t->string('kind', 16);
                $t->string('subject_key', 80);
                $t->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
                $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
                $t->text('payload');
                $t->jsonb('fields')->nullable();
                $t->string('status', 10)->default('pending');
                $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'id']);
            });
        }
        foreach ([
            ['orphan_sensitive_changes_kind', "kind IN ('person','vital_status','parent','orphan_class')"],
            ['orphan_sensitive_changes_status', "status IN ('pending','approved','rejected')"],
            ['orphan_sensitive_changes_subject', 'num_nonnulls(person_id, orphan_id) >= 1'],
            ['orphan_sensitive_changes_decision', "(status = 'pending' AND decided_by IS NULL AND decided_at IS NULL) OR (status <> 'pending' AND decided_by IS NOT NULL AND decided_at IS NOT NULL)"],
            ['orphan_sensitive_changes_not_author', 'decided_by IS NULL OR decided_by <> requested_by'],
        ] as [$name, $check]) {
            if (! DB::table('pg_constraint')->where('conname', $name)->exists()) {
                DB::statement("ALTER TABLE orphan_sensitive_changes ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS orphan_sensitive_changes_one_pending ON orphan_sensitive_changes (subject_key) WHERE status = 'pending'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION orphan_sensitive_changes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SENSITIVE_CHANGE_LOCKED: a sensitive change request is a record and is never deleted' USING ERRCODE = '23514';
    END IF;
    IF OLD.status <> 'pending' THEN
        RAISE EXCEPTION 'SENSITIVE_CHANGE_LOCKED: request % is decided', OLD.reference_no USING ERRCODE = '23514';
    END IF;
    IF NEW.payload IS DISTINCT FROM OLD.payload OR NEW.requested_by <> OLD.requested_by OR NEW.subject_key <> OLD.subject_key OR NEW.kind <> OLD.kind
       OR NEW.person_id IS DISTINCT FROM OLD.person_id OR NEW.orphan_id IS DISTINCT FROM OLD.orphan_id THEN
        RAISE EXCEPTION 'SENSITIVE_CHANGE_LOCKED: the proposal of request % cannot be rewritten', OLD.reference_no USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS orphan_sensitive_changes_guard ON orphan_sensitive_changes;
CREATE TRIGGER orphan_sensitive_changes_guard BEFORE UPDATE OR DELETE ON orphan_sensitive_changes FOR EACH ROW EXECUTE FUNCTION orphan_sensitive_changes_guard();
SQL);

        if (! Schema::hasTable('sponsorship_receipt_allocations')) {
            Schema::create('sponsorship_receipt_allocations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('due_id')->constrained('sponsorship_dues')->restrictOnDelete();
                $t->string('accounting_reference', 150);
                $t->decimal('amount', 14, 2);
                $t->string('currency', 3);
                $t->date('posted_on');
                $t->string('external_system', 120)->nullable();
                $t->text('note')->nullable();
                $t->uuid('request_key')->nullable()->unique();
                $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $t->timestamp('reversed_at')->nullable();
                $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->text('reversal_reason')->nullable();
                $t->string('reversal_reference', 150)->nullable();
                $t->timestamps();
                $t->index('due_id');
            });
        }
        foreach ([
            ['sponsorship_receipt_allocations_amount', 'amount > 0'],
            ['sponsorship_receipt_allocations_reversal', '(reversed_at IS NULL AND reversed_by IS NULL) OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL)'],
        ] as [$name, $check]) {
            if (! DB::table('pg_constraint')->where('conname', $name)->exists()) {
                DB::statement("ALTER TABLE sponsorship_receipt_allocations ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sponsorship_receipt_allocations_reference ON sponsorship_receipt_allocations (due_id, lower(btrim(accounting_reference))) WHERE reversed_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sponsorship_receipt_allocations_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    d record;
    taken numeric;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SPONSORSHIP_RECEIPT_LOCKED: a recorded collection is reversed, never deleted' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.reversed_at IS NOT NULL OR NEW.reversed_at IS NULL OR NEW.due_id <> OLD.due_id OR NEW.amount <> OLD.amount OR NEW.currency <> OLD.currency
           OR NEW.accounting_reference <> OLD.accounting_reference OR NEW.posted_on <> OLD.posted_on OR NEW.recorded_by <> OLD.recorded_by
           OR NEW.request_key IS DISTINCT FROM OLD.request_key THEN
            RAISE EXCEPTION 'SPONSORSHIP_RECEIPT_LOCKED: only a reversal may change a recorded collection' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    SELECT x.amount, x.currency, x.status INTO d FROM sponsorship_dues x WHERE x.id = NEW.due_id FOR UPDATE;
    IF d.status IS DISTINCT FROM 'referred' THEN
        RAISE EXCEPTION 'SPONSORSHIP_RECEIPT_DUE: only a due referred to Accounts can be collected' USING ERRCODE = '23514';
    END IF;
    IF NEW.currency <> d.currency THEN
        RAISE EXCEPTION 'SPONSORSHIP_RECEIPT_CURRENCY: the receipt is in the currency of the due' USING ERRCODE = '23514';
    END IF;
    SELECT COALESCE(SUM(amount), 0) INTO taken FROM sponsorship_receipt_allocations WHERE due_id = NEW.due_id AND reversed_at IS NULL;
    IF taken + NEW.amount > d.amount THEN
        RAISE EXCEPTION 'SPONSORSHIP_RECEIPT_EXCEEDS: recorded receipts would exceed the due amount' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS sponsorship_receipt_allocations_guard ON sponsorship_receipt_allocations;
CREATE TRIGGER sponsorship_receipt_allocations_guard BEFORE INSERT OR UPDATE OR DELETE ON sponsorship_receipt_allocations FOR EACH ROW EXECUTE FUNCTION sponsorship_receipt_allocations_guard();
SQL);

        // The permission only: no role carries it and nobody holds it until the owner attaches it to a role or the
        // ED decides a grant in /finance/staff (a SENSITIVE finance permission).
        DB::table('permissions')->insertOrIgnore(['code' => 'finance.sponsorships.record', 'name' => 'تسجيل قبض الكفالات من البرنامج المحاسبي', 'created_at' => now(), 'updated_at' => now()]);

        if (! DB::table('notification_settings')->where('event_code', 'orphans_sensitive_change')->exists()) {
            DB::table('notification_settings')->insert(['event_code' => 'orphans_sensitive_change', 'label_ar' => 'تعديل حساس على ملف معتمد',
                'description' => 'إشعار رئيس قسم الأيتام بطلب تعديل حساس (الاسم، الهوية، الميلاد، الحالة، الأبوان، التصنيف) على ملف معتمد، وإشعار صاحب الطلب بالقرار.',
                'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('orphan_sensitive_changes') && DB::table('orphan_sensitive_changes')->exists())
            || (Schema::hasTable('sponsorship_receipt_allocations') && DB::table('sponsorship_receipt_allocations')->exists())) {
            throw new RuntimeException('Sensitive change requests and recorded sponsor receipts are records; they are kept.');
        }
        Schema::dropIfExists('sponsorship_receipt_allocations');
        Schema::dropIfExists('orphan_sensitive_changes');
        DB::statement('DROP FUNCTION IF EXISTS sponsorship_receipt_allocations_guard()');
        DB::statement('DROP FUNCTION IF EXISTS orphan_sensitive_changes_guard()');
        DB::statement('DROP SEQUENCE IF EXISTS orphan_sensitive_change_seq');
        // The permission and the notification event stay (role grants may refer to them).
    }
};
