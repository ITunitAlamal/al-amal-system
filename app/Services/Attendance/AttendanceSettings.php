<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shift-swap limits kept in «attendance_module_settings» (one row) and changed only by the HR head or the executive
 * director, with the before/after values audited. Before the completion migration the provisional defaults apply.
 */
final class AttendanceSettings
{
    /** Provisional values approved by the owner on 2026-10-29 (decision C) until reviewed against the employment policy. */
    public const DEFAULTS = ['swap_same_department' => true, 'swap_min_rest_hours' => 8, 'swap_max_hours_per_day' => 16, 'swap_max_days' => 7, 'policy_reference' => null];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    /**
     * @return array{swap_same_department: bool, swap_min_rest_hours: int, swap_max_hours_per_day: int, swap_max_days: int, policy_reference: ?string, updated_at: ?string, updated_by: ?string}
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $row = AttendanceLedger::hasTable('attendance_module_settings') ? DB::table('attendance_module_settings as s')->leftJoin('users as u', 'u.id', '=', 's.updated_by')
            ->where('s.id', 1)->first(['s.*', 'u.name as updated_by_name']) : null;

        return $this->loaded = [
            'swap_same_department' => (bool) ($row->swap_same_department ?? self::DEFAULTS['swap_same_department']),
            'swap_min_rest_hours' => (int) ($row->swap_min_rest_hours ?? self::DEFAULTS['swap_min_rest_hours']),
            'swap_max_hours_per_day' => (int) ($row->swap_max_hours_per_day ?? self::DEFAULTS['swap_max_hours_per_day']),
            'swap_max_days' => max(1, (int) ($row->swap_max_days ?? self::DEFAULTS['swap_max_days'])),
            'policy_reference' => $row->policy_reference ?? null,
            'updated_at' => $row?->updated_by ? (string) $row->updated_at : null,
            'updated_by' => $row->updated_by_name ?? null,
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    /**
     * @param  array{swap_same_department: bool, swap_min_rest_hours: int, swap_max_hours_per_day: int, swap_max_days: int, policy_reference: ?string}  $values
     */
    public function update(User $actor, array $values): void
    {
        DB::transaction(function () use ($actor, $values): void {
            $before = (array) DB::table('attendance_module_settings')->where('id', 1)->lockForUpdate()->first();
            $after = collect($values)->only(array_keys(self::DEFAULTS))->all();
            DB::table('attendance_module_settings')->where('id', 1)->update($after + ['updated_by' => $actor->id, 'updated_at' => now()]);
            AttendanceAudit::record($actor->id, 'attendance_settings', 1, 'settings_updated',
                ['before' => collect($before)->only(array_keys(self::DEFAULTS))->all(), 'after' => $after]);
        });
        $this->loaded = null;
    }
}
