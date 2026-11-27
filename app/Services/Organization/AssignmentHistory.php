<?php

declare(strict_types=1);

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

/**
 * Role assignments keep their history. Every change or removal of a department_user_roles row is copied, as it was,
 * into the append-only table department_user_role_history by a database trigger (migration 2026_11_30_000039), so
 * whoever held a role on a past date can still be read after the assignment ended. The screens that end an
 * assignment name who ended it and why through remove(); a path that does not (a cascade, an automatic expiry) is
 * still copied, without them.
 */
final class AssignmentHistory
{
    /**
     * Ends the given assignments now, recording the person and the reason with the copy the trigger keeps.
     *
     * @param  list<int>|int  $assignmentIds
     */
    public static function remove(array|int $assignmentIds, ?int $actorId, string $reason): int
    {
        $ids = array_values(array_map('intval', (array) $assignmentIds));
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $actorId, $reason): int {
            self::context($actorId, $reason);
            try {
                return DB::table('department_user_roles')->whereIn('id', $ids)->delete();
            } finally {
                self::context(null, null);
            }
        });
    }

    /** Who changes assignments in this transaction, and why, for the history trigger (PostgreSQL only). */
    public static function context(?int $actorId, ?string $reason): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $value = $actorId === null && $reason === null ? ''
            : (string) json_encode(['by' => $actorId, 'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 1000)], JSON_UNESCAPED_UNICODE);
        DB::select("SELECT set_config('aims.assignment_change', ?, true)", [$value]);
    }
}
