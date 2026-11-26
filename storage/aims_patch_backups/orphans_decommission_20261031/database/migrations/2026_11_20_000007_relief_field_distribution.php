<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field distribution: registered devices (each with its own signing key), short online sessions, batches assigned by
 * the head of Sponsorships & Orphans only (database-enforced), signed manifests valid offline for at most 168 hours,
 * and the delivery events uploaded by devices (idempotent by event UUID, never counted before server validation).
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
            $t->text('public_key');
            $t->char('key_fingerprint', 64)->unique();
            $t->string('platform', 40)->nullable();
            $t->string('app_version', 20)->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->text('revoke_reason')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE field_devices ADD CONSTRAINT field_devices_values CHECK (status IN ('pending','approved','revoked','lost') AND (approved_by IS NULL OR approved_by <> user_id))");

        Schema::create('field_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at');
        });

        Schema::create('relief_batches', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 24)->unique();
            $t->foreignId('campaign_id')->constrained('relief_campaigns')->restrictOnDelete();
            $t->foreignId('roster_id')->constrained('relief_rosters')->restrictOnDelete();
            $t->foreignId('area_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('distributor_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->string('status', 12)->default('assigned');
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('assigned_at');
            $t->unsignedInteger('manifest_version')->default(0);
            $t->char('manifest_hash', 64)->nullable();
            $t->timestamp('manifest_issued_at')->nullable();
            $t->timestamp('offline_expires_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->text('revoke_reason')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE relief_batches ADD CONSTRAINT relief_batches_values CHECK (status IN ('assigned','downloaded','returned','closed','revoked')
            AND distributor_user_id <> assigned_by AND (offline_expires_at IS NULL OR offline_expires_at <= manifest_issued_at + interval '168 hours'))");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS relief_batch_ref_seq START 1');
        // Only the head of Sponsorships & Orphans (appointed, or acting within valid appointment dates) assigns
        // beneficiaries to field staff; this authority never includes approving exceptions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relief_batches_head_guard() RETURNS trigger AS $$
            DECLARE dept_id bigint;
            BEGIN
                SELECT id INTO dept_id FROM departments WHERE code = 'ORPHANS' AND deleted_at IS NULL;
                IF NOT (
                    EXISTS (SELECT 1 FROM departments d JOIN department_user_roles a ON a.user_id = d.head_user_id AND a.department_id = d.id
                            JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = d.head_user_id
                            WHERE d.id = dept_id AND d.head_user_id = NEW.assigned_by AND r.code = 'department_head' AND u.is_active)
                    OR EXISTS (SELECT 1 FROM department_user_roles a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
                            WHERE a.user_id = NEW.assigned_by AND a.department_id = dept_id AND r.code = 'acting_department_head' AND u.is_active
                              AND a.starts_on IS NOT NULL AND a.ends_on IS NOT NULL AND a.starts_on <= CURRENT_DATE AND a.ends_on >= CURRENT_DATE)) THEN
                    RAISE EXCEPTION 'only the head of Sponsorships and Orphans assigns field batches';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_batches_head_guard BEFORE INSERT OR UPDATE OF distributor_user_id, device_id, assigned_by ON relief_batches FOR EACH ROW EXECUTE FUNCTION relief_batches_head_guard();
            SQL);

        Schema::table('relief_allocations', function (Blueprint $t): void {
            $t->foreignId('batch_id')->nullable()->constrained('relief_batches')->restrictOnDelete();
        });

        Schema::create('relief_delivery_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('event_uuid')->unique();
            $t->foreignId('device_id')->constrained('field_devices')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('batch_id')->constrained('relief_batches')->restrictOnDelete();
            $t->foreignId('allocation_id')->constrained('relief_allocations')->restrictOnDelete();
            $t->unsignedInteger('manifest_version');
            $t->string('kind', 14);
            $t->jsonb('items')->nullable();
            $t->jsonb('receiver')->nullable();
            $t->foreignId('failure_reason_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->char('signature_hash', 64)->nullable();
            $t->string('signature_path', 200)->nullable();
            $t->timestamp('occurred_at');
            $t->jsonb('device_clock')->nullable();
            $t->char('prev_event_hash', 64)->nullable();
            $t->char('event_hash', 64);
            $t->text('device_signature');
            $t->timestamp('received_at');
            $t->string('status', 30);
            $t->text('status_reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->index(['allocation_id', 'status']);
            $t->index(['batch_id', 'status']);
        });
        DB::statement("ALTER TABLE relief_delivery_events ADD CONSTRAINT relief_events_values CHECK (kind IN ('delivered','partial','not_delivered','referral')
            AND status IN ('uploaded_pending_validation','confirmed','conflict_held','rejected')
            AND (status <> 'confirmed' OR kind IN ('not_delivered','referral') OR (signature_hash IS NOT NULL AND signature_path IS NOT NULL)))");
        // Device evidence is append-only: the uploaded payload, its hashes and signature never change after receipt.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION relief_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'delivery evidence is never deleted';
                END IF;
                IF NEW.event_uuid IS DISTINCT FROM OLD.event_uuid OR NEW.items IS DISTINCT FROM OLD.items OR NEW.receiver IS DISTINCT FROM OLD.receiver
                    OR NEW.signature_hash IS DISTINCT FROM OLD.signature_hash OR NEW.event_hash IS DISTINCT FROM OLD.event_hash OR NEW.device_signature IS DISTINCT FROM OLD.device_signature
                    OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at OR NEW.allocation_id IS DISTINCT FROM OLD.allocation_id OR NEW.kind IS DISTINCT FROM OLD.kind THEN
                    RAISE EXCEPTION 'delivery evidence is append-only';
                END IF;
                IF OLD.status IN ('confirmed', 'rejected') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'a confirmed or rejected delivery event is final';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER relief_events_append_only BEFORE UPDATE OR DELETE ON relief_delivery_events FOR EACH ROW EXECUTE FUNCTION relief_events_append_only();
            SQL);
    }

    public function down(): void
    {
        foreach (['field_devices', 'relief_batches', 'relief_delivery_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("يوجد سجلات في {$table}؛ لا يُتراجع عن الترحيل.");
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS relief_events_append_only ON relief_delivery_events; DROP FUNCTION IF EXISTS relief_events_append_only();');
        Schema::dropIfExists('relief_delivery_events');
        Schema::table('relief_allocations', fn (Blueprint $t) => $t->dropConstrainedForeignId('batch_id'));
        DB::unprepared('DROP TRIGGER IF EXISTS relief_batches_head_guard ON relief_batches; DROP FUNCTION IF EXISTS relief_batches_head_guard();');
        Schema::dropIfExists('relief_batches');
        DB::statement('DROP SEQUENCE IF EXISTS relief_batch_ref_seq');
        Schema::dropIfExists('field_access_tokens');
        Schema::dropIfExists('field_devices');
    }
};
