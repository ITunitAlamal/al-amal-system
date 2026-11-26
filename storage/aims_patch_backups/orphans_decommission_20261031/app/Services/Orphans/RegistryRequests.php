<?php

namespace App\Services\Orphans;

use Illuminate\Support\Facades\DB;

/**
 * Log of calls to the civil-registry (ministry) provider: purpose, outcome and timing, with the identifier only
 * as a keyed hash and its last digits. No response content is kept here; provider data lives in the verification
 * snapshot of the application it belongs to.
 */
final class RegistryRequests
{
    public const OUTCOMES = ['available' => 'متاح وأجاب', 'unavailable' => 'غير متاح', 'pending' => 'بانتظار الرد', 'error' => 'خطأ'];

    public static function record(string $provider, string $purpose, string $subjectKind, ?string $identifier, string $outcome, ?int $userId = null,
        ?int $guardianId = null, ?int $claimId = null, ?int $durationMs = null, ?int $httpStatus = null): void
    {
        $identifier = $identifier !== null ? trim($identifier) : null;
        DB::table('registry_requests')->insert([
            'provider' => mb_substr($provider, 0, 60), 'purpose' => $purpose, 'subject_kind' => $subjectKind,
            'identifier_hash' => $identifier ? RegistryIdentity::hash($identifier) : null,
            'identifier_mask' => $identifier ? '•••'.mb_substr($identifier, -3) : null,
            'outcome' => array_key_exists($outcome, self::OUTCOMES) ? $outcome : 'error', 'http_status' => $httpStatus, 'duration_ms' => $durationMs,
            'requested_by' => $userId, 'requested_by_guardian' => $guardianId, 'guardian_application_id' => $claimId, 'created_at' => now(),
        ]);
    }
}
