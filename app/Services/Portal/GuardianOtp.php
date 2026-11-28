<?php

namespace App\Services\Portal;

use App\Models\Guardian;
use App\Services\AuditService;
use App\Services\HotSmsService;
use App\Services\Orphans\ArabicText;
use App\Support\SafeErrorMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes for phone verification and password reset, sent through the institute's real SMS provider
 * (HotSMS) only when it is enabled. The provider does not confirm delivery, so a phone is marked verified only
 * when its holder enters the code. Codes are stored as HMAC hashes, expire, and allow a limited number of tries.
 */
final class GuardianOtp
{
    public function __construct(private readonly HotSmsService $sms, private readonly AuditService $audit) {}

    public static function available(): bool
    {
        return (bool) config('services.hotsms.enabled');
    }

    /** Sends a new code; returns false when SMS is unavailable or sending failed (the caller shows the manual path). */
    public function send(Guardian $account, string $purpose): bool
    {
        if (! self::available() || blank($account->phone)) {
            return false;
        }
        // One active code per purpose; at most 5 codes per hour per account.
        if (DB::table('guardian_otps')->where('guardian_id', $account->id)->where('created_at', '>', now()->subHour())->count() >= 5) {
            return false;
        }
        $code = (string) random_int(100000, 999999);
        DB::table('guardian_otps')->where('guardian_id', $account->id)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        DB::table('guardian_otps')->insert(['guardian_id' => $account->id, 'purpose' => $purpose, 'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes((int) config('portal.otp_minutes', 10)), 'created_at' => now()]);
        $text = $purpose === 'password_reset' ? "رمز استعادة كلمة المرور في بوابة معهد الأمل: {$code}. لا تشاركه مع أحد." : "رمز تأكيد رقم جوالك في بوابة معهد الأمل: {$code}. لا تشاركه مع أحد.";
        try {
            $this->sms->send($account->phone, $text);
        } catch (\Throwable $e) {
            // No code or phone number in the log (a gateway error can quote the request URL with both).
            Log::warning('portal.otp.send_failed', ['account' => $account->id, 'purpose' => $purpose, 'error' => SafeErrorMessage::from($e)]);

            return false;
        }

        return true;
    }

    /**
     * A Palestinian mobile number reduced to its 9 national digits (5XXXXXXXX), from the forms the staff import already
     * accepts (05…, 970…/972… with + or 00); null for anything else. All written forms of one number share this key.
     */
    public static function mobileKey(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', ArabicText::digits($phone));
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            preg_match('/^97[02]5\d{8}$/D', $digits) === 1 => substr($digits, 3),
            preg_match('/^05\d{8}$/D', $digits) === 1 => substr($digits, 1),
            default => null,
        };
    }

    /**
     * Public sign-up (AC-11): a code for a phone that has no account yet. Nothing is stored here; the caller keeps the
     * hash in the session. Every check runs before any account lookup, so no outcome says whether an ID or a phone is
     * taken: 'off' (SMS disabled), 'phone' (not a Palestinian mobile), 'limited' (a sending cap reached), 'failed' (the
     * provider refused or timed out) or 'sent' with the code's hash. Caps: 5 codes an hour per number, and the owner's
     * optional caps per IP an hour and for the whole portal a day (portal.signup_sms_*, off while empty). Each send is
     * recorded in «سجل النظام» without the number or the code.
     *
     * @return array{status: 'off'|'phone'|'limited'|'failed'|'sent', hash: ?string}
     */
    public function signupCode(string $phone, ?string $ip): array
    {
        if (! self::available()) {
            return ['status' => 'off', 'hash' => null];
        }
        $number = self::mobileKey($phone);
        if ($number === null) {
            return ['status' => 'phone', 'hash' => null];
        }
        $caps = ['phone' => ['guardian-signup-code:'.hash('sha256', $number), 5, 3600]];
        if (($perIp = (int) config('portal.signup_sms_per_ip_hour')) > 0 && filled($ip)) {
            $caps['ip'] = ['guardian-signup-code-ip:'.hash('sha256', (string) $ip), $perIp, 3600];
        }
        if (($perDay = (int) config('portal.signup_sms_per_day')) > 0) {
            $caps['portal'] = ['guardian-signup-code-all', $perDay, 86400];
        }
        foreach ($caps as $name => [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Log::notice('portal.signup.sms_limited', ['cap' => $name]);

                return ['status' => 'limited', 'hash' => null];
            }
        }
        foreach ($caps as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
        $code = (string) random_int(100000, 999999);
        try {
            $this->sms->send($phone, "رمز تأكيد رقم جوالك في بوابة معهد الأمل: {$code}. لا تشاركه مع أحد.");
        } catch (\Throwable $e) {
            Log::warning('portal.otp.send_failed', ['account' => null, 'purpose' => 'signup', 'error' => SafeErrorMessage::from($e)]);
            $this->recordSignupSend($ip, false);

            return ['status' => 'failed', 'hash' => null];
        }
        $this->recordSignupSend($ip, true);

        return ['status' => 'sent', 'hash' => $this->hash($code)];
    }

    /** One audit row per sign-up SMS, without the number or the code (abuse shows as volume per IP). */
    private function recordSignupSend(?string $ip, bool $sent): void
    {
        $this->audit->event('portal.signup.code_sent', ['actor_id' => null, 'outcome' => $sent ? 'success' : 'failure',
            'reason_code' => $sent ? null : 'provider_error', 'ip' => $ip]);
    }

    public function matchesSignupCode(string $hash, string $code): bool
    {
        return hash_equals($hash, $this->hash(preg_replace('/\D/', '', ArabicText::digits($code)) ?? ''));
    }

    /** Checks and consumes a code. */
    public function verify(Guardian $account, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($account, $purpose, $code): bool {
            $row = DB::table('guardian_otps')->where('guardian_id', $account->id)->where('purpose', $purpose)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->orderByDesc('id')->lockForUpdate()->first();
            if (! $row || $row->attempts >= (int) config('portal.otp_attempts', 5)) {
                return false;
            }
            if (! hash_equals($row->code_hash, $this->hash(preg_replace('/\D/', '', ArabicText::digits($code)) ?? ''))) {
                DB::table('guardian_otps')->where('id', $row->id)->increment('attempts');

                return false;
            }
            DB::table('guardian_otps')->where('id', $row->id)->update(['consumed_at' => now()]);

            return true;
        });
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
