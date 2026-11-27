<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No supplier invoice or external payment voucher counted twice (TRANSFORM §6/§10; قسم الحسابات §3 «عدم تكرار
 * المعاملة أو صرفها مرتين»):
 * - finance_purchase_invoices.external_voucher_amount: the amount of the voucher issued in the external accounting
 *   software. Existing rows keep NULL (the amount was not recorded then); nothing is back-filled.
 * - A trigger refuses an invoice number already recorded for the same supplier through any order, and an external
 *   voucher number already recorded on another invoice (compared trimmed and case-insensitive). Existing rows are not
 *   checked or changed: only a new invoice, a changed invoice number or a newly recorded voucher is refused. An
 *   advisory lock per number serializes concurrent entries. The controllers give the same answer first, with a
 *   readable message.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE finance_purchase_invoices ADD COLUMN IF NOT EXISTS external_voucher_amount numeric(18,2);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'finance_purchase_invoices_voucher_amount_positive') THEN
        ALTER TABLE finance_purchase_invoices ADD CONSTRAINT finance_purchase_invoices_voucher_amount_positive
            CHECK (external_voucher_amount IS NULL OR external_voucher_amount > 0);
    END IF;
END $$;

CREATE OR REPLACE FUNCTION finance_purchase_invoices_unique_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    supplier bigint;
    other bigint;
BEGIN
    IF TG_OP = 'INSERT' OR NEW.invoice_no IS DISTINCT FROM OLD.invoice_no OR NEW.order_id IS DISTINCT FROM OLD.order_id THEN
        SELECT supplier_id INTO supplier FROM finance_purchase_orders WHERE id = NEW.order_id;
        PERFORM pg_advisory_xact_lock(hashtext('finance_supplier_invoice:' || supplier || ':' || lower(btrim(NEW.invoice_no))));
        SELECT i.id INTO other FROM finance_purchase_invoices i JOIN finance_purchase_orders o ON o.id = i.order_id
         WHERE o.supplier_id = supplier AND lower(btrim(i.invoice_no)) = lower(btrim(NEW.invoice_no)) AND i.id <> NEW.id
         LIMIT 1;
        IF other IS NOT NULL THEN
            RAISE EXCEPTION 'FINANCE_DUPLICATE_SUPPLIER_INVOICE: invoice "%" of supplier % is already recorded (invoice %)', NEW.invoice_no, supplier, other
                USING ERRCODE = '23505';
        END IF;
    END IF;
    IF NEW.external_voucher_no IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.external_voucher_no IS DISTINCT FROM OLD.external_voucher_no) THEN
        PERFORM pg_advisory_xact_lock(hashtext('finance_external_voucher:' || lower(btrim(NEW.external_voucher_no))));
        SELECT id INTO other FROM finance_purchase_invoices
         WHERE lower(btrim(external_voucher_no)) = lower(btrim(NEW.external_voucher_no)) AND id <> NEW.id
         LIMIT 1;
        IF other IS NOT NULL THEN
            RAISE EXCEPTION 'FINANCE_DUPLICATE_EXTERNAL_VOUCHER: voucher "%" is already recorded on invoice %', NEW.external_voucher_no, other
                USING ERRCODE = '23505';
        END IF;
    END IF;
    RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS finance_purchase_invoices_unique_guard ON finance_purchase_invoices;
CREATE TRIGGER finance_purchase_invoices_unique_guard BEFORE INSERT OR UPDATE OF invoice_no, order_id, external_voucher_no ON finance_purchase_invoices
    FOR EACH ROW EXECUTE FUNCTION finance_purchase_invoices_unique_guard();
SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('finance_purchase_invoices', 'external_voucher_amount')
            && DB::table('finance_purchase_invoices')->whereNotNull('external_voucher_amount')->exists()) {
            throw new RuntimeException('سُجّلت قيم سندات صرف على فواتير الموردين؛ لا يُحذف العمود دون ترحيلها.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS finance_purchase_invoices_unique_guard ON finance_purchase_invoices;
DROP FUNCTION IF EXISTS finance_purchase_invoices_unique_guard();
ALTER TABLE finance_purchase_invoices DROP CONSTRAINT IF EXISTS finance_purchase_invoices_voucher_amount_positive;
ALTER TABLE finance_purchase_invoices DROP COLUMN IF EXISTS external_voucher_amount;
SQL);
    }
};
