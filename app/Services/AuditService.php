<?php

namespace App\Services;

use App\Services\Audit\AuditTaxonomy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The central writer of «سجل النظام» (audit_logs). Call it inside the same DB::transaction as the change it records,
 * so a rolled-back change leaves no audit claim. The database trigger adds event id, module, origin, actor snapshot,
 * correlation id and classification; this class keeps secrets and protected personal data out of the payload.
 */
class AuditService
{
    /**
     * تسجيل عملية على سجل في النظام.
     *
     * يجب استدعاء هذه الدالة داخل نفس
     * DB::transaction الخاصة بالتغيير.
     */
    public function record(
        Request $request,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        $this->event($action, [
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * تسجيل عملية على سجل جدول لا يملك Model.
     *
     * يجب استدعاء هذه الدالة داخل نفس DB::transaction الخاصة بالتغيير.
     */
    public function recordRow(
        string $action,
        string $table,
        int|string $id,
        ?array $before,
        ?array $after,
        ?int $actorId,
        ?string $ipAddress = null
    ): void {
        $this->event($action, ['subject_type' => $table, 'subject_id' => (string) $id, 'before' => $before, 'after' => $after,
            'actor_id' => $actorId, 'ip' => $ipAddress]);
    }

    /**
     * One audit event. Options: subject_type, subject_id, subject_ref (human-safe reference), before, after, metadata,
     * actor_id (defaults to the signed-in staff user; pass null for the system), actor_guardian_id, outcome
     * (success|failure|denied), reason_code, severity, sensitivity, ip.
     *
     * @param  array<string, mixed>  $options
     */
    public function event(string $action, array $options = []): void
    {
        $metadata = $options['metadata'] ?? [];
        if (array_key_exists('before', $options) || array_key_exists('after', $options)) {
            $metadata = ['before' => $this->sanitize($options['before'] ?? null), 'after' => $this->sanitize($options['after'] ?? null), ...$metadata];
        }
        DB::table('audit_logs')->insert(array_filter([
            'actor_id' => array_key_exists('actor_id', $options) ? $options['actor_id'] : auth('web')->id(),
            'actor_guardian_id' => $options['actor_guardian_id'] ?? null,
            'action' => mb_substr($action, 0, 255),
            'subject_type' => $options['subject_type'] ?? null,
            'subject_id' => isset($options['subject_id']) ? mb_substr((string) $options['subject_id'], 0, 255) : null,
            'subject_ref' => isset($options['subject_ref']) ? mb_substr(self::clean((string) $options['subject_ref']), 0, 120) : null,
            'metadata' => json_encode($this->sanitize($metadata) ?: (object) [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'ip_address' => $options['ip'] ?? null,
            'outcome' => $options['outcome'] ?? null,
            'reason_code' => isset($options['reason_code']) ? mb_substr((string) $options['reason_code'], 0, 60) : null,
            'severity' => $options['severity'] ?? null,
            'sensitivity' => $options['sensitivity'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value, string $key): bool => $value !== null || in_array($key, ['actor_id', 'subject_type', 'subject_id', 'ip_address'], true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Credentials are dropped, identity/health/restricted values are reduced to «changed», strings are bounded and
     * stripped of control characters (log injection), and nesting is limited.
     */
    private function sanitize(?array $data, int $depth = 0): ?array
    {
        if ($data === null) {
            return null;
        }
        if ($depth > 4) {
            return ['…' => 'حُذف التفصيل العميق'];
        }
        foreach ($data as $key => $value) {
            $name = (string) $key;
            if (preg_match(AuditTaxonomy::SECRET_KEYS, $name)) {
                $data[$key] = '[محجوب]';
            } elseif (preg_match(AuditTaxonomy::PROTECTED_KEYS, $name)) {
                $data[$key] = $value === null || $value === '' ? null : '[تغيّر — بيانات محمية]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = mb_substr(self::clean($value), 0, 500);
            }
        }

        return $data;
    }

    private static function clean(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }
}
