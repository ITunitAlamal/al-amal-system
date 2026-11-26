<?php

namespace App\Support\Attendance;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation and columns of a shift template's own settings (kind, grace, break, punch windows, rotation cycle,
 * effective dates). Shared by template creation and the approval-gated template edit.
 */
final class ScheduleSettings
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:56'],
            'days.*.weekday' => ['required', 'integer', 'between:1,56'],
            'days.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.ends_next_day' => ['sometimes', 'boolean'],
            'kind' => ['nullable', Rule::in(['fixed', 'flexible', 'rotation'])],
            'grace_minutes' => ['nullable', 'integer', 'between:0,240'],
            'break_minutes' => ['nullable', 'integer', 'between:0,600'],
            'break_paid' => ['sometimes', 'boolean'],
            'required_minutes' => ['nullable', 'required_if:kind,flexible', 'integer', 'between:1,1440'],
            'required_punches' => ['nullable', Rule::in([1, 2])],
            'window_before_minutes' => ['nullable', 'integer', 'between:0,720'],
            'window_after_minutes' => ['nullable', 'integer', 'between:0,720'],
            'cycle_days' => ['nullable', 'required_if:kind,rotation', 'integer', 'between:1,56'],
            'cycle_anchor' => ['nullable', 'required_if:kind,rotation', 'date_format:Y-m-d'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /**
     * Checks the day rows against the kind: weekdays 1–7, or cycle days 1..cycle_days for a rotation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function checkDays(array $data): void
    {
        $kind = $data['kind'] ?? 'fixed';
        $limit = $kind === 'rotation' ? (int) ($data['cycle_days'] ?? 0) : 7;
        $keys = array_column($data['days'], 'weekday');
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['days' => 'تكرر يوم في الجدول.']);
        }
        foreach ($data['days'] as $day) {
            if ((int) $day['weekday'] > $limit) {
                throw ValidationException::withMessages(['days' => $kind === 'rotation' ? 'رقم يوم الدورة أكبر من طول الدورة.' : 'أيام الأسبوع من 1 إلى 7.']);
            }
            if ($day['start_time'] === $day['end_time'] || ($day['end_time'] < $day['start_time'] && empty($day['ends_next_day']))) {
                throw ValidationException::withMessages(['days' => 'تحقق من وقت البداية والنهاية وخيار «ينتهي اليوم التالي».']);
            }
        }
    }

    /**
     * Columns to write; empty before the settings migration.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function columns(array $data): array
    {
        if (! Schema::hasColumn('attendance_schedules', 'kind')) {
            return [];
        }
        $kind = $data['kind'] ?? 'fixed';

        return [
            'kind' => $kind, 'grace_minutes' => $data['grace_minutes'] ?? null, 'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'break_paid' => (bool) ($data['break_paid'] ?? true), 'required_minutes' => $kind === 'flexible' ? (int) $data['required_minutes'] : null,
            'required_punches' => (int) ($data['required_punches'] ?? 2), 'window_before_minutes' => $data['window_before_minutes'] ?? null,
            'window_after_minutes' => $data['window_after_minutes'] ?? null, 'cycle_days' => $kind === 'rotation' ? (int) $data['cycle_days'] : null,
            'cycle_anchor' => $kind === 'rotation' ? $data['cycle_anchor'] : null, 'location' => $data['location'] ?? null, 'notes' => $data['notes'] ?? null,
            'effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null,
        ];
    }
}
