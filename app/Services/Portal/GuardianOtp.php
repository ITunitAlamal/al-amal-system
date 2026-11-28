<?php

namespace App\Services\Portal;

use App\Models\Guardian;
use App\Services\HotSmsService;
use App\Services\Orphans\ArabicText;
use App\Support\SafeErrorMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time codes for phone verification and password reset, sent through the institute's real SMS provider
 * (HotSMS) only when it is enabled. The provider does not confirm delivery, so a phone is marked verified only
 * when its holder enters the code. Codes are stored as HMAC hashes, expire, and allow a limited number of tries.
 */
final class GuardianOtp
{
    public function __construct(private readonly HotSmsService $sms) {}

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
