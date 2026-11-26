<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly self-evaluation shown only to the employee in «مساحتي», as motivation.
 *
 * Built from real assignments: every time a workflow step was assigned to the user this month.
 * Completed = the user acted on it. Pending = still waiting for them. Assignments taken over by another
 * member of the same pool count for neither. Leave decisions made by the user count as completed tasks.
 * On time = acted before the step's deadline (steps without a deadline count as on time).
 */
final class SelfEvaluation
{
    public function __construct(private readonly LeaveApproverResolver $leaves) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $now = CarbonImmutable::now();
        $current = $this->period($user, $now->startOfMonth(), $now);
        $previous = $this->period($user, $now->subMonthNoOverflow()->startOfMonth(), $now->startOfMonth());
        $current['pending'] += $this->leaves->awaiting($user)->count();
        $current['rate'] = $this->rate($current['done'], $current['pending']);
        $previous['rate'] = $this->rate($previous['done'], $previous['pending']);

        return [
            'month' => $now->locale('ar')->translatedFormat('F Y'),
            ...$current,
            'previousRate' => $previous['rate'],
            'trend' => $current['rate'] === null || $previous['rate'] === null ? null : $current['rate'] - $previous['rate'],
            ...$this->message($current['rate'], $current['done'] + $current['pending']),
        ];
    }

    /**
     * @return array{done: int, pending: int, onTime: ?int, avgHours: ?float}
     */
    private function period(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assignments = DB::table('workflow_participants as p')
            ->where('p.user_id', $user->id)
            ->where('p.participation_type', 'assignee')
            ->where('p.created_at', '>=', $from)
            ->where('p.created_at', '<', $to)
            ->get(['p.workflow_instance_id', 'p.is_active', 'p.created_at', 'p.revoked_at', 'p.revoked_by']);

        $done = $assignments->filter(fn (object $row): bool => ! $row->is_active && (int) $row->revoked_by === (int) $user->id);
        $pending = $assignments->where('is_active', true)->count();

        $onTime = 0;
        $hours = [];
        foreach ($done as $row) {
            $assignedAt = CarbonImmutable::parse($row->created_at);
            $actedAt = CarbonImmutable::parse($row->revoked_at);
            $hours[] = max(0, $assignedAt->diffInMinutes($actedAt)) / 60;
            $slaHours = DB::table('workflow_events as e')
                ->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
                ->where('e.workflow_instance_id', $row->workflow_instance_id)
                ->where('e.actor_id', $user->id)
                ->where('e.occurred_at', $row->revoked_at)
                ->value('s.sla_hours');
            if (! $slaHours || $actedAt->lessThanOrEqualTo($assignedAt->addHours((int) $slaHours))) {
                $onTime++;
            }
        }

        $leaveDecisions = DB::table('employee_leave_approvals')
            ->where('actor_id', $user->id)
            ->whereIn('to_status', ['approved', 'rejected'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->count();

        $completed = $done->count() + $leaveDecisions;

        return [
            'done' => $completed,
            'pending' => $pending,
            'onTime' => $completed > 0 ? (int) round((($onTime + $leaveDecisions) / $completed) * 100) : null,
            'avgHours' => $hours === [] ? null : round(array_sum($hours) / count($hours), 1),
        ];
    }

    private function rate(int $done, int $pending): ?int
    {
        return $done + $pending === 0 ? null : (int) round(($done / ($done + $pending)) * 100);
    }

    /**
     * @return array{message: string, tone: string}
     */
    private function message(?int $rate, int $total): array
    {
        return match (true) {
            $total === 0 => ['message' => 'لا توجد مهام مسندة إليك هذا الشهر بعد.', 'tone' => 'muted'],
            $rate >= 90 => ['message' => 'أداء ممتاز، شكرًا لالتزامك وسرعة إنجازك.', 'tone' => 'success'],
            $rate >= 70 => ['message' => 'أداء جيد جدًا، استمر على هذا المستوى.', 'tone' => 'info'],
            $rate >= 50 => ['message' => 'تقدّم جيد، بقيت مهام تنتظر إجراءك.', 'tone' => 'warning'],
            default => ['message' => 'لديك مهام متراكمة؛ ابدأ بالأقدم والمتأخر منها.', 'tone' => 'danger'],
        };
    }
}
