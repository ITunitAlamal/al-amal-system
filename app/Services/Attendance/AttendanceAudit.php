<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Append-only attendance audit trail (attendance_audit_events): who did what to which record, with its details.
 */
final class AttendanceAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(int $actorId, string $entityType, int $entityId, string $action, array $details = []): void
    {
        DB::table('attendance_audit_events')->insert([
            'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => $action, 'actor_id' => $actorId,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
