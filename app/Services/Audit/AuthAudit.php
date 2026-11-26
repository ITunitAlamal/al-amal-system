<?php

namespace App\Services\Audit;

use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in and credential events of staff and portal accounts. Never stores a password, code or the typed login
 * name: a failed attempt keeps a short keyed fingerprint of the login name (to see repeated attempts on one account)
 * and, when the account exists, a reference to it.
 */
final class AuthAudit
{
    public function __construct(private readonly AuditService $audit) {}

    public static function fingerprint(string $login): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($login)), (string) config('app.key')), 0, 16);
    }

    public function staffLoginFailed(Request $request, string $employeeNumber): void
    {
        $userId = DB::table('users')->where('employee_number', trim($employeeNumber))->value('id');
        $this->audit->event('auth.login_failed', ['actor_id' => null, 'subject_type' => $userId ? 'users' : null, 'subject_id' => $userId,
            'outcome' => 'failure', 'reason_code' => $userId ? 'bad_credentials_or_inactive' : 'unknown_account',
            'metadata' => ['login_fingerprint' => self::fingerprint($employeeNumber)], 'ip' => $request->ip()]);
    }

    public function staffPasswordChanged(Request $request, int $userId, string $how): void
    {
        $this->audit->event('auth.password_changed', ['actor_id' => $userId, 'subject_type' => 'users', 'subject_id' => $userId,
            'severity' => 'notice', 'metadata' => ['how' => $how], 'ip' => $request->ip()]);
    }

    public function guardianLogin(Request $request, int $guardianId): void
    {
        $this->audit->event('portal.auth.login', ['actor_id' => null, 'actor_guardian_id' => $guardianId, 'subject_type' => 'guardians', 'subject_id' => $guardianId,
            'sensitivity' => 'normal', 'ip' => $request->ip()]);
    }

    public function guardianLoginFailed(Request $request, string $phone): void
    {
        $this->audit->event('portal.auth.login_failed', ['actor_id' => null, 'outcome' => 'failure', 'reason_code' => 'bad_credentials_or_inactive',
            'sensitivity' => 'normal', 'metadata' => ['login_fingerprint' => self::fingerprint($phone)], 'ip' => $request->ip()]);
    }

    public function guardianLogout(Request $request, int $guardianId): void
    {
        $this->audit->event('portal.auth.logout', ['actor_id' => null, 'actor_guardian_id' => $guardianId, 'subject_type' => 'guardians', 'subject_id' => $guardianId,
            'sensitivity' => 'normal', 'ip' => $request->ip()]);
    }

    public function guardianPasswordChanged(Request $request, int $guardianId, string $how): void
    {
        $this->audit->event('portal.auth.password_changed', ['actor_id' => null, 'actor_guardian_id' => $guardianId, 'subject_type' => 'guardians', 'subject_id' => $guardianId,
            'severity' => 'notice', 'sensitivity' => 'normal', 'metadata' => ['how' => $how], 'ip' => $request->ip()]);
    }
}
