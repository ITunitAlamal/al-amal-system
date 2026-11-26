<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** RELIEF_CLOSURE: closure request with evidence → head recommendation → executive decision, re-checking the evidence. */
final class ReliefClosureWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'relief_campaign_closure';

    public function __construct(private readonly OrphanAccess $access, private readonly ReliefClosure $closure, private readonly AuditService $audit) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $campaign = DB::table('relief_campaigns')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($campaign, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['closed', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'إغلاق الحملة قرار للمدير التنفيذي.');
        }
        if ($target->code === 'closed' && ($blockers = $this->closure->blockers((int) $campaign->id))) {
            throw ValidationException::withMessages(['campaign' => $blockers]);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $update = match ($target->code) {
            'closed' => ['status' => 'closed', 'closed_by' => $actorId, 'closed_at' => now()],
            'rejected', 'manager' => ['status' => 'active', 'closure_instance_id' => $target->code === 'rejected' ? null : DB::raw('closure_instance_id'), 'status_reason' => $comment],
            default => [],
        };
        if ($update) {
            DB::table('relief_campaigns')->where('id', $instance->subject_id)->update($update + ['updated_at' => now()]);
        }
        $this->audit->recordRow('relief.closure.'.$transition->action_code, 'relief_campaigns', (int) $instance->subject_id, null, ['step' => $target->code, 'workflow' => $instance->reference_no], $actorId);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/relief/campaigns/'.DB::table('relief_campaigns')->where('id', $subjectId)->value('public_id').'?tab=closure';
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('relief_campaigns')->whereIn('id', $subjectIds)->pluck('reference_no', 'id')->map(fn ($reference) => 'إغلاق الحملة '.$reference)->all();
    }
}
