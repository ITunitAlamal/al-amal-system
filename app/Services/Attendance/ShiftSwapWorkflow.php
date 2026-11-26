<?php

namespace App\Services\Attendance;

use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «تبديل المناوبة» on the central workflow (ATTENDANCE_SHIFT_SWAP). The rosters change only when the request reaches
 * the approved end step, inside the same transaction as the move (a failed check undoes the approval).
 */
final class ShiftSwapWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'attendance_shift_swap';

    public function __construct(
        private readonly ShiftSwapService $swaps,
        private readonly AssigneeResolver $assignees,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $swap = $this->locked($instance);
        if ($swap->status !== 'pending_approval') {
            throw ValidationException::withMessages(['transition' => 'طلب التبديل مغلق ولا يقبل إجراءات جديدة.']);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        if ($target->step_type !== 'end') {
            return;
        }
        $swap = $this->locked($instance);
        ($this->assignees->config($target)['outcome'] ?? 'completed') === 'rejected'
            ? $this->swaps->rejected($swap, $actorId, $comment)
            : $this->swaps->apply($swap, $actorId, $comment);
    }

    /**
     * @return array<string, mixed>
     */
    public function conditionContext(object $instance): array
    {
        $swap = DB::table('attendance_shift_swaps')->where('id', $instance->subject_id)->first(['days']);

        return ['swap_days' => $swap ? count(json_decode((string) $swap->days, true) ?: []) : null];
    }

    public function url(int $subjectId): string
    {
        return ShiftSwapService::url($subjectId);
    }

    public function titles(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return DB::table('attendance_shift_swaps as s')->join('employees as a', 'a.id', '=', 's.requester_employee_id')
            ->join('employees as b', 'b.id', '=', 's.counterpart_employee_id')->whereIn('s.id', $subjectIds)
            ->get(['s.id', 's.days', 'a.first_name_ar', 'a.father_name_ar', 'a.family_name_ar', 'b.first_name_ar as b_first', 'b.family_name_ar as b_family'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => 'تبديل مناوبة: '.AttendanceLedger::fullName($row).' ↔ '.trim($row->b_first.' '.$row->b_family)
                .' · '.implode('، ', array_column(json_decode((string) $row->days, true) ?: [], 'date'))])->all();
    }

    private function locked(object $instance): object
    {
        return DB::table('attendance_shift_swaps')->where('id', $instance->subject_id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['transition' => 'طلب التبديل المرتبط بالمعاملة غير موجود.']);
    }
}
