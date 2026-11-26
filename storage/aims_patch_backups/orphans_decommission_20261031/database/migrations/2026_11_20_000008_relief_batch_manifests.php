<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every manifest issued for a batch, with its own offline expiry (at most 168 hours after issue). Events recorded
 * offline are judged against the manifest version they were recorded under, not the latest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relief_batch_manifests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('relief_batches')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->char('manifest_hash', 64);
            $t->unsignedInteger('allocations');
            $t->timestamp('issued_at');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['batch_id', 'version']);
        });
        DB::statement("ALTER TABLE relief_batch_manifests ADD CONSTRAINT relief_manifests_offline_cap CHECK (expires_at > issued_at AND expires_at <= issued_at + interval '168 hours')");
    }

    public function down(): void
    {
        if (DB::table('relief_batch_manifests')->exists()) {
            throw new RuntimeException('توجد بيانات تنزيل مسجلة؛ لا يُتراجع عن الترحيل.');
        }
        Schema::dropIfExists('relief_batch_manifests');
    }
};
