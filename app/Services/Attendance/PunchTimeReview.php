<?php

namespace App\Services\Attendance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device punches whose wall time a daylight-saving change repeated or skipped («time_review», HR-20), listed with the
 * readings each can stand for and whether the user may confirm it (AttendanceAccess::mayResolvePunchTime). Shown on
 * the operations page and, for whoever decides them (the executive director included), under «تصحيح البصمات».
 */
final class PunchTimeReview
{
    public function __construct(private AttendanceAccess $access) {}

    /** The one employee whose number the device id matches exactly, unless a device mapping names someone else. */
    public function employeeFor(object $row): ?int
    {
        if ($row->employee_id !== null) {
            return (int) $row->employee_id;
        }
        $matches = DB::table('employees')->whereNull('deleted_at')->where('employee_number', $row->raw_user_id)->limit(2)->pluck('id');
        if ($matches->count() !== 1) {
            return null;
        }
        $mapped = DB::table('attendance_employee_device_ids')->where('device_id', $row->device_id)->where('device_user_id', $row->raw_user_id)->value('employee_id');

        return $mapped !== null && (int) $mapped !== (int) $matches->first() ? null : (int) $matches->first();
    }

    /** @return list<array<string, mixed>> */
    public function queue(User $user, bool $decidableOnly = false): array
    {
        if (! Schema::hasColumn('attendance_punches', 'raw_payload')) {
            return [];
        }

        return DB::table('attendance_punches as p')->leftJoin('employees as e', 'e.id', '=', 'p.employee_id')
            ->where('p.match_status', 'time_review')->orderBy('p.id')->limit(100)
            ->get(['p.id', 'p.device_id', 'p.raw_user_id', 'p.device_local_time', 'p.device_timezone', 'p.direction', 'p.occurred_at', 'p.employee_id',
                'e.first_name_ar', 'e.family_name_ar'])
            ->map(function (object $row) use ($user): array {
                $stored = CarbonImmutable::parse($row->occurred_at)->utc();
                $zone = (string) $row->device_timezone;

                return [
                    'id' => (int) $row->id, 'device_id' => (int) $row->device_id, 'raw_user_id' => $row->raw_user_id,
                    'device_local_time' => $row->device_local_time, 'device_timezone' => $row->device_timezone,
                    'employee' => $row->employee_id !== null ? trim($row->first_name_ar.' '.$row->family_name_ar) : null,
                    'direction_known' => in_array($row->direction, ['in', 'out'], true),
                    'readings' => array_map(fn (CarbonImmutable $reading): array => [
                        'value' => $reading->toIso8601String(),
                        'label' => $reading->setTimezone($zone)->format('Y-m-d H:i').' (UTC'.$reading->setTimezone($zone)->format('P').')',
                        'stored' => $reading->equalTo($stored),
                    ], AttendanceRawImporter::timeReadings((string) $row->device_local_time, $zone)),
                    'decidable' => $this->access->mayResolvePunchTime($user, $this->employeeFor($row)),
                ];
            })
            ->when($decidableOnly, fn ($rows) => $rows->filter(fn (array $row): bool => $row['decidable']))
            ->values()->all();
    }
}
