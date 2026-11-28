<?php

namespace App\Services\Orphans\Field;

use App\Models\User;
use App\Services\Audit\AuthAudit;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field devices and online sessions. A distributor signs in online with their own credentials from a device whose
 * key they prove to hold; an unknown device waits for the head's approval. Sessions are short and bound to one user
 * and one device; revoking a device ends its sessions at the next contact.
 */
final class FieldDevices
{
    public const STATUS_LABELS = ['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'revoked' => 'ملغى', 'lost' => 'مفقود'];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit, private readonly AuthAudit $authAudit) {}

    public static function setting(string $key, int $default): int
    {
        return (int) (json_decode((string) DB::table('orphan_settings')->where('key', $key)->value('value'), true) ?? $default);
    }

    /** @return array{status: string, token?: string, expires_at?: string, device?: string, user?: string} */
    public function signIn(array $data, ?string $ip): array
    {
        $user = User::where('employee_number', trim($data['employee_number']))->where('is_active', true)->first();
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            // Same staff credentials as the sign-in page, so the same failed-login record in «سجل النظام».
            $this->authAudit->staffLoginFailed(request(), (string) $data['employee_number'], 'field');
            throw ValidationException::withMessages(['employee_number' => 'الرقم الوظيفي أو كلمة المرور غير صحيحة، أو الحساب غير مفعّل.']);
        }
        if ($user->must_change_password) {
            throw ValidationException::withMessages(['password' => 'غيّر كلمة المرور من النظام عبر المتصفح أولًا.']);
        }
        $signedAt = strtotime($data['signed_at']);
        if (! $signedAt || abs(time() - $signedAt) > 300
            || ! FieldKeys::verifyDevice('aims-field-login|'.trim($data['employee_number']).'|'.$data['signed_at'], $data['proof'], $data['public_key'])) {
            throw ValidationException::withMessages(['device' => 'تعذر إثبات مفتاح الجهاز أو أن ساعة الجهاز غير مضبوطة.']);
        }
        abort_unless($this->isFieldStaff($user), 403, 'لا يوجد لك تكليف تسليم نافذ في حملة معتمدة.');
        $fingerprint = hash('sha256', (string) base64_decode($data['public_key'], true));

        return DB::transaction(function () use ($user, $data, $fingerprint, $ip): array {
            $device = DB::table('field_devices')->where('key_fingerprint', $fingerprint)->lockForUpdate()->first();
            if (! $device) {
                $id = DB::table('field_devices')->insertGetId(['public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'name' => mb_substr($data['device_name'], 0, 120),
                    'public_key' => $data['public_key'], 'key_fingerprint' => $fingerprint, 'platform' => $data['platform'] ?? null, 'app_version' => $data['app_version'] ?? null,
                    'status' => 'pending', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->recordRow('orphans.field.device_registered', 'field_devices', $id, null, ['user' => $user->id, 'name' => $data['device_name']], $user->id, $ip);

                return ['status' => 'pending', 'device' => DB::table('field_devices')->where('id', $id)->value('public_id')];
            }
            abort_if((int) $device->user_id !== (int) $user->id, 403, 'هذا الجهاز مسجل لموظف آخر.');
            if ($device->status !== 'approved') {
                return ['status' => $device->status, 'device' => $device->public_id];
            }
            $hours = self::setting('field_session_hours', 12);
            $token = Str::random(48);
            DB::table('field_access_tokens')->insert(['device_id' => $device->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($hours), 'created_at' => now()]);
            DB::table('field_devices')->where('id', $device->id)->update(['last_seen_at' => now(), 'app_version' => $data['app_version'] ?? $device->app_version, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.field.session', 'field_devices', (int) $device->id, null, ['user' => $user->id], $user->id, $ip);

            return ['status' => 'approved', 'token' => $token, 'expires_at' => now()->addHours($hours)->toIso8601String(), 'device' => $device->public_id, 'user' => $user->name];
        });
    }

    /** @return array{user: User, device: object, token: object}|null */
    public function authenticate(?string $bearer, ?string $fingerprint): ?array
    {
        if (! $bearer || ! $fingerprint) {
            return null;
        }
        $token = DB::table('field_access_tokens')->where('token_hash', hash('sha256', $bearer))->first();
        if (! $token || $token->revoked_at !== null || now()->greaterThan($token->expires_at)) {
            return null;
        }
        $device = DB::table('field_devices')->where('id', $token->device_id)->first();
        $user = User::find($token->user_id);
        if (! $device || $device->status !== 'approved' || ! hash_equals($device->key_fingerprint, $fingerprint) || ! $user || ! $user->is_active || ! $this->isFieldStaff($user)) {
            return null;
        }
        DB::table('field_access_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        DB::table('field_devices')->where('id', $device->id)->update(['last_seen_at' => now()]);

        return ['user' => $user, 'device' => $device, 'token' => $token];
    }

    public function signOut(object $token): void
    {
        DB::table('field_access_tokens')->where('id', $token->id)->update(['revoked_at' => now()]);
    }

    public function approve(User $actor, int $deviceId): void
    {
        abort_unless($this->access->isHead($actor) || $actor->isSystemAdmin(), 403, 'اعتماد أجهزة التوزيع لرئيس القسم أو مدير النظام.');
        DB::transaction(function () use ($actor, $deviceId): void {
            $device = DB::table('field_devices')->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($device, 404);
            abort_if((int) $device->user_id === (int) $actor->id, 403, 'لا يعتمد المستخدم جهازه.');
            abort_unless($device->status === 'pending', 409, 'الجهاز ليس بانتظار الاعتماد؛ الجهاز الملغى يُسجّل من جديد.');
            DB::table('field_devices')->where('id', $deviceId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.field.device_approved', 'field_devices', $deviceId, ['status' => 'pending'], ['status' => 'approved'], $actor->id, request()?->ip());
        });
    }

    /** Revoke or report lost: sessions end now; events already received from it are kept for review. */
    public function revoke(User $actor, int $deviceId, bool $lost, string $reason): void
    {
        abort_unless($this->access->isHead($actor) || $actor->isSystemAdmin(), 403);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب الإلغاء.']);
        }
        DB::transaction(function () use ($actor, $deviceId, $lost, $reason): void {
            $device = DB::table('field_devices')->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($device && in_array($device->status, ['pending', 'approved'], true), 404);
            DB::table('field_devices')->where('id', $deviceId)->update(['status' => $lost ? 'lost' : 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now()]);
            DB::table('field_access_tokens')->where('device_id', $deviceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->audit->recordRow($lost ? 'orphans.field.device_lost' : 'orphans.field.device_revoked', 'field_devices', $deviceId, ['status' => $device->status], ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /** A valid «distributor» assignment on a campaign whose list is approved and open for delivery (gate of the field API). */
    public function isFieldStaff(User $user): bool
    {
        return $this->distributorAssignments((int) $user->id)->join('campaigns as c', 'c.id', '=', 'm.campaign_id')->whereIn('c.status', ['approved', 'distributing'])->exists();
    }

    /**
     * A valid «distributor» assignment in THIS campaign (not withdrawn, within its dates). Required for each batch
     * manifest and each synced event, whatever other access the user holds: the field client acts only on assignments.
     */
    public function isDistributorOf(User|int $user, int $campaignId): bool
    {
        return $this->distributorAssignments($user instanceof User ? (int) $user->id : $user)->where('m.campaign_id', $campaignId)->exists();
    }

    private function distributorAssignments(int $userId): Builder
    {
        $today = today()->toDateString();

        return DB::table('campaign_members as m')->where('m.user_id', $userId)->where('m.role', 'distributor')->whereNull('m.revoked_at')
            ->where(fn ($q) => $q->whereNull('m.starts_on')->orWhere('m.starts_on', '<=', $today))->where(fn ($q) => $q->whereNull('m.ends_on')->orWhere('m.ends_on', '>=', $today));
    }
}
