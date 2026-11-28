<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IN-09. An idempotency key is bound to what it recorded. campaign_deliveries (idempotency_key),
 * sponsorship_receipt_allocations and aims_rental_receipt_allocations (request_key) get request_hash: the SHA-256
 * of the request's effect-defining data, written with the row. A retry with the same key and the same data returns
 * the first result; the same key with other data is refused. Rows recorded before this migration keep a null hash
 * and are compared on their stored columns.
 *
 * Nullable columns without a default: PostgreSQL adds them without rewriting the tables, and the immutability and
 * guard triggers of these tables are not fired. No existing row is changed.
 *
 * Idempotent: each column is added only when missing.
 */
return new class extends Migration
{
    private const TABLES = ['campaign_deliveries', 'sponsorship_receipt_allocations', 'aims_rental_receipt_allocations'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'request_hash')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->char('request_hash', 64)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // The hashes are part of the record of what each key recorded; they are kept.
    }
};
