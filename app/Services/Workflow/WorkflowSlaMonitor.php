<?php

namespace App\Services\Workflow;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;

/**
 * Flags requests that passed their step deadline and widens the assignee pool
 * when the step names an escalation role (SRS: SLA and Escalation).
 * Missing a deadline never counts as approval.
 */
final class WorkflowSlaMonitor
{
    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly NotificationCenter $notifications,
    ) {}

    /**
     * @return int number of requests flagged
     */
    public function flagOverdue(): int
    {
        $overdue = DB::table('workflow_instances')
            ->where('status', 'in_progress')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('sla_breached_at')
            ->pluck('id');

        foreach ($overdue as $instanceId) {
            DB::transaction(fn () => $this->flag((int) $instanceId));
        }

        return $overdue->count();
    }

    /**
     * Suspended requests whose review date has come: their holders are reminded once a day. Nothing resumes by itself.
     *
     * @return int number of requests reminded
     */
    public function remindHeldReviews(): int
    {
        $due = DB::table('workflow_instances')->where('status', 'in_progress')->whereNotNull('held_at')
            ->where('hold_until', '<=', now()->toDateString())->get(['id', 'public_id', 'reference_no']);

        foreach ($due as $instance) {
            $holders = DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)
                ->where('participation_type', 'assignee')->where('is_active', true)->pluck('user_id');
            $this->notifications->notifyMany($holders, 'workflow_hold_review_due', [
                'title' => 'حلّ موعد مراجعة معاملة معلقة: '.($instance->reference_no ?? ''),
                'body' => 'استأنفها، أو استأنفها ثم علّقها من جديد بسبب موثق.',
                'url' => route('workflows.show', ['publicId' => $instance->public_id], false),
                'dedupe' => 'hold-review:'.$instance->id.':'.now()->toDateString(),
            ]);
        }

        return $due->count();
    }

    private function flag(int $instanceId): void
    {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        if (! $instance || $instance->status !== 'in_progress' || $instance->sla_breached_at !== null) {
            return;
        }

        $now = now();
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        $escalationRole = $this->assignees->config($step)['escalate_to_role_code'] ?? null;
        $escalatedTo = [];

        if ($escalationRole) {
            $escalationStep = (object) [
                'assignment_type' => 'role',
                'assigned_role_code' => $escalationRole,
                'assignment_config' => ['department_scope' => 'any'],
            ];
            $escalatedTo = $this->assignees->candidates($escalationStep, $instance);

            foreach ($escalatedTo as $userId) {
                $exists = DB::table('workflow_participants')
                    ->where('workflow_instance_id', $instanceId)->where('user_id', $userId)->where('participation_type', 'assignee')->first();
                $exists
                    ? DB::table('workflow_participants')->where('id', $exists->id)->update(['is_active' => true, 'visibility_scope' => 'operational', 'revoked_at' => null, 'revoked_by' => null, 'updated_at' => $now])
                    : DB::table('workflow_participants')->insert([
                        'workflow_instance_id' => $instanceId, 'user_id' => $userId, 'participation_type' => 'assignee',
                        'visibility_scope' => 'operational', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
            }
        }

        DB::table('workflow_instances')->where('id', $instanceId)->update(['sla_breached_at' => $now, 'updated_at' => $now]);
        DB::table('workflow_events')->insert([
            'workflow_instance_id' => $instanceId,
            'from_step_id' => $instance->current_step_id,
            'to_step_id' => $instance->current_step_id,
            'action' => 'sla_breached',
            'comment' => null,
            'metadata' => json_encode(['due_at' => (string) $instance->due_at, 'escalated_to' => $escalatedTo]),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        // Everyone now responsible for the step, escalation included. One notice per deadline.
        $responsible = DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')->where('is_active', true)
            ->pluck('user_id');
        $this->notifications->notifyMany($responsible, 'workflow_task_overdue', [
            'title' => 'معاملة تجاوزت المهلة: '.($instance->reference_no ?? ''),
            'body' => 'المرحلة: '.($step->name ?? ''),
            'url' => route('workflows.show', ['publicId' => $instance->public_id], false),
            'dedupe' => 'sla:'.$instanceId.':'.$instance->due_at,
        ]);
    }
}
