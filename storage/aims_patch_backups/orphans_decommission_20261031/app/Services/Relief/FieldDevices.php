<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field devices and online sessions. A distributor signs in online with their own employee credentials from a device
 * whose key they prove to hold; an unknown device is registered as pending until the head of Sponsorships & Orphans
 * (or the system admin, as a technical control) approves it. Sessions are short and tied to one user and one device;
 * revoking or reporting a device lost ends its sessions at the next network contact.
 */
final class FieldDevices
{
    public const STATUS_LABELS = ['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'revoked' => 'ملغى', 'lost' => 'مفقود'];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    /**
     * @param  array{employee_number: string, password: string, public_key: string, device_name: string, platform?: ?string, app_version?: ?string, signed_at: string, proof: string}  $data
     * @return array{status: string, token?: string, expires_at?: string, device?: string, user?: string}
     */
    public function signIn(array $data, ?string $ip): array
    {
        $user = User::where('employee_number', trim($data['employee_number']))->where('is_active', true)->first();
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
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
        if (! $this->isFieldStaff($user)) {
            abort(403, 'لا يوجد لك تكليف موزع ميداني نافذ في حملة قائمة.');
        }
        $fingerprint = hash('sha256', (string) base64_decode($data['public_key'], true));

        return DB::transaction(function () use ($user, $data, $fingerprint, $ip): array {
            $device = DB::table('field_devices')->where('key_fingerprint', $fingerprint)->lockForUpdate()->first();
            if (! $device) {
                $id = DB::table('field_devices')->insertGetId(['public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'name' => mb_substr($data['device_name'], 0, 120),
                    'public_key' => $data['public_key'], 'key_fingerprint' => $fingerprint, 'platform' => $data['platform'] ?? null, 'app_version' => $data['app_version'] ?? null,
                    'status' => 'pending', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->recordRow('relief.device.registered', 'field_devices', $id, null, ['user' => $user->id, 'name' => $data['device_name']], $user->id, $ip);

                return ['status' => 'pending', 'device' => DB::table('field_devices')->where('id', $id)->value('public_id')];
            }
            if ((int) $device->user_id !== (int) $user->id) {
                abort(403, 'هذا الجهاز مسجل لموظف آخر.');
            }
            if ($device->status !== 'approved') {
                return ['status' => $device->status, 'device' => $device->public_id];
            }
            $hours = (int) DB::table('orphans_module_settings')->where('id', 1)->value('field_session_hours') ?: 12;
            $token = Str::random(48);
            DB::table('field_access_tokens')->insert(['device_id' => $device->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours($hours), 'created_at' => now()]);
            DB::table('field_devices')->where('id', $device->id)->update(['last_seen_at' => now(), 'app_version' => $data['app_version'] ?? $device->app_version, 'updated_at' => now()]);
            $this->audit->recordRow('relief.device.session', 'field_devices', (int) $device->id, null, ['user' => $user->id], $user->id, $ip);

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
        abort_unless($this->access->isHead($actor) || $actor->isSystemAdmin(), 403, 'اعتماد أجهزة التوزيع لرئيس قسم الكفالات أو مدير النظام.');
        DB::transaction(function () use ($actor, $deviceId): void {
            $device = DB::table('field_devices')->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($device, 404);
            abort_if((int) $device->user_id === (int) $actor->id, 403, 'لا يعتمد المستخدم جهازه.');
            if ($device->status !== 'pending') {
                throw ValidationException::withMessages(['device' => 'الجهاز ليس بانتظار الاعتماد؛ الجهاز الملغى يُسجّل من جديد.']);
            }
            DB::table('field_devices')->where('id', $deviceId)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('relief.device.approved', 'field_devices', $deviceId, ['status' => 'pending'], ['status' => 'approved'], $actor->id, request()?->ip());
        });
    }

    /** Revoke or report lost: sessions end now; held events from it are kept for review, never dropped. */
    public function revoke(User $actor, int $deviceId, bool $lost, string $reason): void
    {
        abort_unless($this->access->isHead($actor) || $actor->isSystemAdmin(), 403);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب الإلغاء.']);
        }
        DB::transaction(function () use ($actor, $deviceId, $lost, $reason): void {
            $device = DB::table('field_devices')->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($device && in_array($device->status, ['pending', 'approved'], true), 404);
            DB::table('field_devices')->where('id', $deviceId)->update(['status' => $lost ? 'lost' : 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(),
                'revoke_reason' => $reason, 'updated_at' => now()]);
            DB::table('field_access_tokens')->where('device_id', $deviceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->audit->recordRow($lost ? 'relief.device.lost' : 'relief.device.revoked', 'field_devices', $deviceId, ['status' => $device->status], ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    public function isFieldStaff(User $user): bool
    {
        $today = today(config('orphans.timezone'))->toDateString();

        return DB::table('relief_campaign_members as m')->join('relief_campaigns as c', 'c.id', '=', 'm.campaign_id')
            ->where('m.user_id', $user->id)->where('m.role', 'field_distributor')->whereNull('m.revoked_at')
            ->where('m.starts_on', '<=', $today)->where('m.ends_on', '>=', $today)->whereIn('c.status', ['active', 'suspended', 'closing'])->exists();
    }
}
