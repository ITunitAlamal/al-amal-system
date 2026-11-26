<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 6b — offline field delivery (Windows client in field_client/), on the campaign model.
 *
 * Devices prove an Ed25519 key and are approved by the head; sessions are short and bound to one user and one device.
 * A batch is a set of approved primary entries assigned to one distributor on one device; its manifest is signed by
 * the server. Events come back signed by the device; each event_uuid is stored once (idempotent), and a clean event
 * becomes a campaign delivery with idempotency_key = event_uuid, so a repeated sync never delivers twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_devices', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('name', 120);
            $t->string('public_key', 100);
            $t->string('key_fingerprint', 64)->unique();
            $t->string('platform', 40)->nullable();
            $t->string('app_version', 20)->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 300)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE field_devices ADD CONSTRAINT field_devices_status CHECK (status IN ('pending','approved','revoked','lost'))");

        Schema::create('field_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at');
        });

        Schema::create('campaign_batches', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 40)->unique();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->foreignId('distributor_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->string('status', 10)->default('assigned');
            $t->unsignedInteger('manifest_version')->default(0);
            $t->timestamp('offline_expires_at')->nullable();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE campaign_batches ADD CONSTRAINT campaign_batches_status CHECK (status IN ('assigned','downloaded','returned','revoked'))");

        Schema::create('campaign_batch_entries', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('batch_id')->constrained('campaign_batches')->restrictOnDelete();
            $t->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $t->string('unit_key', 40);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        // A unit is in at most one active batch: two devices can never carry the same beneficiary.
        DB::statement('CREATE UNIQUE INDEX campaign_batch_entries_one_active ON campaign_batch_entries (campaign_id, unit_key) WHERE is_active');

        Schema::create('campaign_batch_manifests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('campaign_batches')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('list_id')->constrained('campaign_lists')->restrictOnDelete();
            $t->text('body');
            $t->string('body_hash', 64);
            $t->string('signature', 120);
            $t->timestamp('issued_at');
            $t->timestamp('expires_at');
            $t->timestamp('created_at');
            $t->unique(['batch_id', 'version']);
        });

        Schema::create('field_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('event_uuid')->unique();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('campaign_batches')->restrictOnDelete();
            $t->foreignId('batch_entry_id')->nullable()->constrained('campaign_batch_entries')->restrictOnDelete();
            $t->string('kind', 15);
            $t->text('payload');
            $t->string('device_signature', 120);
            $t->string('event_hash', 64);
            $t->string('prev_event_hash', 64)->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->string('status', 15);
            $t->text('status_reason')->nullable();
            $t->string('signature_path', 300)->nullable();
            $t->foreignId('delivery_id')->nullable()->constrained('campaign_deliveries')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamp('received_at');
            $t->index(['batch_id', 'status']);
        });
        DB::statement("ALTER TABLE field_events ADD CONSTRAINT field_events_status CHECK (status IN ('confirmed','conflict_held','rejected'))");

        DB::statement('CREATE SEQUENCE campaign_batch_seq START 1');
        foreach ([['field_offline_max_hours', 72], ['field_session_hours', 12], ['field_lock_minutes', 5]] as [$key, $value]) {
            DB::table('orphan_settings')->insertOrIgnore(['key' => $key, 'value' => json_encode($value), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: field evidence is kept.');
    }
};
