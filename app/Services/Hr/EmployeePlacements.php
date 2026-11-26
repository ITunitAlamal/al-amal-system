<?php

namespace App\Services\Hr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Placement history (department, job title, grade). A change closes the current placement the day before it
 * takes effect and opens a new one, so earlier placements are never overwritten; a same-day correction updates
 * the placement that started today. The database rejects overlapping periods.
 */
final class EmployeePlacements
{
    /**
     * @param  array{department_id?: int|null, job_title_id?: int|null, job_grade_id?: int|null, department_source?: string|null, job_title_source?: string|null, grade_source?: string|null}  $placement
     */
    public function record(int $employeeId, array $placement, string $effectiveFrom, string $source, ?string $reason, ?int $actorId, ?int $batchId = null): int
    {
        $from = Carbon::parse($effectiveFrom)->toDateString();
        $row = [
            'department_id' => $placement['department_id'] ?? null, 'job_title_id' => $placement['job_title_id'] ?? null, 'job_grade_id' => $placement['job_grade_id'] ?? null,
            'department_source' => $placement['department_source'] ?? null, 'job_title_source' => $placement['job_title_source'] ?? null, 'grade_source' => $placement['grade_source'] ?? null,
        ];
        $current = DB::table('employee_placements')->where('employee_id', $employeeId)->whereNull('effective_to')->lockForUpdate()->first();

        if ($current && $current->effective_from >= $from) {
            DB::table('employee_placements')->where('id', $current->id)->update([...$row, 'reason' => $reason, 'updated_at' => now()]);

            return (int) $current->id;
        }
        if ($current) {
            DB::table('employee_placements')->where('id', $current->id)->update(['effective_to' => Carbon::parse($from)->subDay()->toDateString(), 'updated_at' => now()]);
        }

        return (int) DB::table('employee_placements')->insertGetId([
            'employee_id' => $employeeId, ...$row, 'effective_from' => $from, 'effective_to' => null, 'source' => $source, 'reason' => $reason,
            'import_batch_id' => $batchId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return list<object>
     */
    public function history(int $employeeId): array
    {
        return DB::table('employee_placements as p')
            ->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->leftJoin('job_titles as t', 't.id', '=', 'p.job_title_id')
            ->leftJoin('job_grades as g', 'g.id', '=', 'p.job_grade_id')
            ->where('p.employee_id', $employeeId)->orderByDesc('p.effective_from')
            ->get(['p.id', 'p.effective_from', 'p.effective_to', 'p.source', 'p.reason', 'd.name as department', 't.name_ar as job_title', 'g.name_ar as grade',
                'p.department_source', 'p.job_title_source', 'p.grade_source'])->all();
    }
}
