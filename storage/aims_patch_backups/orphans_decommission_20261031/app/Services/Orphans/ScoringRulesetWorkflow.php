<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** SCORING_RULESET: proposal → head recommendation → executive director's decision (approval freezes the version). */
final class ScoringRulesetWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'scoring_ruleset';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly ScoringRulesets $rulesets,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $row = DB::table('scoring_rulesets')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($row, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['approved', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'اعتماد أوزان التقييم قرار للمدير التنفيذي.');
            abort_if((int) $row->created_by === $actorId, 403, 'لا يعتمد معد القواعد قواعده.');
        }
        if ($target->code === 'approved') {
            $this->rulesets->approve((int) $row->id, $actorId);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = match ($target->code) {
            'approved' => 'approved', 'rejected' => 'rejected', 'proposer' => 'draft', default => 'pending_approval',
        };
        $update = ['decision_note' => in_array($target->code, ['approved', 'rejected', 'proposer'], true) ? $comment : DB::raw('decision_note'), 'updated_at' => now()];
        if ($status !== 'approved') {
            $update['status'] = $status;
        }
        DB::table('scoring_rulesets')->where('id', $instance->subject_id)->update($update);
        $row = DB::table('scoring_rulesets')->where('id', $instance->subject_id)->first();
        $this->audit->recordRow('orphans.scoring.'.$transition->action_code, 'scoring_rulesets', (int) $row->id, null, ['status' => $row->status, 'workflow' => $instance->reference_no], $actorId);
        if (in_array($target->code, ['approved', 'rejected', 'proposer'], true) && (int) $row->created_by !== $actorId) {
            $this->notifications->notify((int) $row->created_by, 'scoring_ruleset_decided', [
                'title' => (ScoringRulesets::STATUS_LABELS[$row->status] ?? $row->status).': قواعد التقييم «'.$row->name.'»',
                'url' => $this->url((int) $row->id), 'dedupe' => 'scoring:'.$row->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/scoring/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('scoring_rulesets')->whereIn('id', $subjectIds)->get(['id', 'name', 'version', 'kind'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => ($row->kind === 'social' ? 'قواعد التقييم الاجتماعي' : 'قواعد أولوية حملة').' «'.$row->name.'» إصدار '.$row->version])->all();
    }
}
