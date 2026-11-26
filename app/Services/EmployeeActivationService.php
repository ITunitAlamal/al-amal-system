<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmployeeActivationService
{
    private const OTP_LIFETIME_MINUTES = 5;

    private const RESEND_SECONDS = 60;

    private const MAX_DAILY_SENDS = 5;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly HotSmsService $sms) {}

    /**
     * Called only after checking the onboarding actor's authorization.
     * A successful HTTP request to the provider does not prove SMS delivery.
     */
    public function sendCode(User $user): array
    {
        if (! config('services.hotsms.enabled', false)) {
            throw new RuntimeException('خدمة SMS غير مفعلة بعد.');
        }

        // Generate uniformly including codes beginning with zero.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $record = DB::transaction(function () use ($user, $code): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($lockedUser->is_active) {
                throw ValidationException::withMessages(['account' => 'الحساب مفعّل بالفعل.']);
            }

            $employee = DB::table('employees')
                ->where('user_id', $lockedUser->id)
                ->whereNull('deleted_at')
                ->first(['phone']);

            $phone = trim((string) ($employee->phone ?? ''));
            if (! preg_match('/^\+?[0-9]{8,15}$/D', $phone)) {
                throw ValidationException::withMessages([
                    'phone' => 'يجب إدخال رقم الجوال الأساسي بصيغة دولية صحيحة قبل الإرسال.',
                ]);
            }

            $latest = DB::table('employee_activation_codes')
                ->where('user_id', $lockedUser->id)
                ->orderByDesc('id')
                ->first(['created_at']);

            if ($latest && Carbon::parse($latest->created_at)->greaterThan(now()->subSeconds(self::RESEND_SECONDS))) {
                throw ValidationException::withMessages(['code' => 'يرجى الانتظار 60 ثانية قبل طلب رمز جديد.']);
            }

            $dailyCount = DB::table('employee_activation_codes')
                ->where('user_id', $lockedUser->id)
                ->where('created_at', '>=', now()->subDay())
                ->count();
            if ($dailyCount >= self::MAX_DAILY_SENDS) {
                throw ValidationException::withMessages(['code' => 'تم تجاوز الحد المسموح لطلبات التفعيل خلال 24 ساعة.']);
            }

            DB::table('employee_activation_codes')
                ->where('user_id', $lockedUser->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $id = DB::table('employee_activation_codes')->insertGetId([
                'user_id' => $lockedUser->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::OTP_LIFETIME_MINUTES),
                'consumed_at' => null,
                'failed_attempts' => 0,
                'delivery_status' => 'pending',
                'sent_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'phone' => $phone];
        });

        try {
            $this->sms->send(
                $record['phone'],
                'معهد الأمل للأيتام: رمز تفعيل حسابك '.$code
                    .' صالح لمدة 5 دقائق. لا تشاركه مع أحد.'
            );
        } catch (Throwable $exception) {
            // The provider might have received the SMS before a timeout.
            // Do not claim a definitive failure or retry automatically.
            throw new RuntimeException(
                'تعذّر تأكيد طلب الإرسال. إذا وصلك الرمز فجرّب تفعيله، وإلا انتظر قبل طلب رمز جديد.',
                0
            );
        }

        DB::table('employee_activation_codes')
            ->where('id', $record['id'])
            ->update(['sent_at' => now(), 'updated_at' => now()]);

        return ['status' => 'pending', 'record_id' => $record['id']];
    }

    /**
     * The second way to activate, for the system administrator only (owner request 2026-10-24): no SMS, a temporary
     * password handed to the employee in person, which the employee must change at the first sign-in. Open SMS codes
     * are consumed so they cannot be used afterwards.
     */
    public function activateByAdministrator(User $actor, User $user, string $temporaryPassword, ?string $ipAddress = null): void
    {
        if (! $actor->is_active || ! $actor->isSystemAdmin()) {
            throw ValidationException::withMessages(['account' => 'التفعيل دون رمز SMS لمدير النظام فقط.']);
        }

        DB::transaction(function () use ($actor, $user, $temporaryPassword, $ipAddress): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($lockedUser->is_active) {
                throw ValidationException::withMessages(['account' => 'الحساب مفعّل بالفعل.']);
            }
            if ((int) $lockedUser->id === (int) $actor->id) {
                throw ValidationException::withMessages(['account' => 'لا يفعّل المستخدم حسابه بنفسه.']);
            }

            $lockedUser->password = Hash::make($temporaryPassword);
            $lockedUser->is_active = true;
            $lockedUser->must_change_password = true;
            $lockedUser->save();

            $profile = DB::table('employees')->where('user_id', $lockedUser->id)->whereNull('deleted_at')->first(['id', 'onboarding_status']);
            DB::table('employees')->where('user_id', $lockedUser->id)
                ->whereNull('deleted_at')->where('onboarding_status', 'pending_activation')
                ->update(['onboarding_status' => 'completed', 'updated_at' => now()]);

            DB::table('employee_activation_codes')->where('user_id', $lockedUser->id)->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'employee_profile.activated_without_sms',
                'subject_type' => $profile ? 'employees' : 'users',
                'subject_id' => (string) ($profile->id ?? $lockedUser->id),
                'metadata' => json_encode(['before' => ['is_active' => false, 'onboarding_status' => $profile->onboarding_status ?? null],
                    'after' => ['is_active' => true, 'must_change_password' => true, 'method' => 'administrator_temporary_password', 'user_id' => $lockedUser->id]],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * The HTTP controller must throttle requests and bind user identity to an
     * authorized activation session; never accept an arbitrary user_id.
     */
    public function activate(User $user, string $code, string $password): void
    {
        Validator::make(
            ['code' => $code, 'password' => $password],
            ['code' => ['required', 'regex:/^[0-9]{6}$/D'],
                'password' => ['required', 'string', 'min:12', 'max:128']]
        )->validate();

        // Return an outcome from the transaction rather than throwing on a
        // wrong OTP: otherwise PostgreSQL would roll back failed_attempts.
        $outcome = DB::transaction(function () use ($user, $code, $password): string {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($lockedUser->is_active) {
                return 'already_active';
            }

            $record = DB::table('employee_activation_codes')
                ->where('user_id', $lockedUser->id)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $record || Carbon::parse($record->expires_at)->isPast()) {
                return 'expired';
            }
            if ((int) $record->failed_attempts >= self::MAX_ATTEMPTS) {
                return 'blocked';
            }

            $phone = DB::table('employees')
                ->where('user_id', $lockedUser->id)
                ->whereNull('deleted_at')
                ->value('phone');
            if (! $phone || trim((string) $phone) !== $record->phone) {
                return 'phone_changed';
            }

            if (! Hash::check($code, $record->code_hash)) {
                $attempts = (int) $record->failed_attempts + 1;
                DB::table('employee_activation_codes')
                    ->where('id', $record->id)
                    ->update([
                        'failed_attempts' => $attempts,
                        'consumed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
                        'updated_at' => now(),
                    ]);

                return 'invalid';
            }

            $lockedUser->password = Hash::make($password);
            $lockedUser->is_active = true;
            $lockedUser->must_change_password = false;
            $lockedUser->save();

            DB::table('employees')->where('user_id', $lockedUser->id)
                ->whereNull('deleted_at')->where('onboarding_status', 'pending_activation')
                ->update(['onboarding_status' => 'completed', 'updated_at' => now()]);

            DB::table('employee_activation_codes')
                ->where('id', $record->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return 'activated';
        });

        if ($outcome !== 'activated') {
            $messages = [
                'already_active' => ['account' => 'الحساب مفعّل بالفعل.'],
                'expired' => ['code' => 'الرمز منتهي الصلاحية أو غير موجود.'],
                'blocked' => ['code' => 'تم تجاوز عدد محاولات التحقق.'],
                'phone_changed' => ['phone' => 'تغيّر رقم الجوال الأساسي. اطلب رمز تفعيل جديدًا.'],
                'invalid' => ['code' => 'رمز التفعيل غير صحيح.'],
            ];
            throw ValidationException::withMessages($messages[$outcome] ?? [
                'code' => 'تعذر التحقق من الرمز.',
            ]);
        }
    }
}
