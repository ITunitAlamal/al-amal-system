<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AttendancePolicy
{
    public function graceMinutes(): ?int
    {
        $rows = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'attendance_grace_minutes')->where('t.is_active', true)->where('v.is_active', true)->pluck('v.code');
        if ($rows->isEmpty()) {
            return null;
        } // Unapproved, not zero.
        if ($rows->count() !== 1 || ! preg_match('/^min_(0|[1-9][0-9]{0,2})$/', (string) $rows[0]) || (int) substr((string) $rows[0], 4) > 240) {
            throw new RuntimeException('Exactly one active numeric HR grace value (0..240) is required.');
        }

        return (int) substr((string) $rows[0], 4);
    }
}
