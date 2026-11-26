<?php

namespace App\Services\Rentals;

use Illuminate\Support\Facades\DB;

/** One audit line per change in the rentals file: actor, action, record, before and after, reason. */
final class RentalAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public function log(?int $actorId, string $action, string $table, int $id, ?array $before = null, array $after = [], ?string $ip = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $ip ?? request()?->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
