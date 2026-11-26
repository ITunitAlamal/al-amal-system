<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\OrphanAccess;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** RELIEF_CAMPAIGN: department draft → head recommendation → executive director's decision. */
final class ReliefCampaignWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'relief_campaign';

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit, private readonly NotificationCenter $notifications) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $campaign = DB::table('relief_campaigns')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($campaign, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['approved', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'اعتماد الحملات قرار للمدير التنفيذي.');
            abort_if((int) $campaign->created_by === $actorId, 403, 'لا يعتمد معد الحملة حملته.');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = match ($target->code) {
            'approved' => 'approved', 'rejected' => 'rejected', 'proposer' => 'draft', default => 'in_review',
        };
        $update = ['status' => $status, 'updated_at' => now()];
        if ($target->code === 'approved') {
            $update += ['approved_by' => $actorId, 'approved_at' => now()];
        }
        if (in_array($target->code, ['rejected', 'proposer'], true)) {
            $update['status_reason'] = $comment;
        }
        DB::table('relief_campaigns')->where('id', $instance->subject_id)->update($update);
        $campaign = DB::table('relief_campaigns')->where('id', $instance->subject_id)->first();
        $this->audit->recordRow('relief.campaign.'.$transition->action_code, 'relief_campaigns', (int) $campaign->id, null, ['status' => $status, 'workflow' => $instance->reference_no], $actorId);
        if (in_array($target->code, ['approved', 'rejected', 'proposer'], true)) {
            foreach (array_unique([(int) $campaign->manager_user_id, (int) $campaign->created_by]) as $userId) {
                if ($userId !== $actorId) {
                    $this->notifications->notify($userId, 'relief_campaign_update', ['title' => (ReliefCampaigns::STATUS_LABELS[$status] ?? $status).': الحملة '.$campaign->reference_no,
                        'url' => $this->url((int) $campaign->id), 'dedupe' => 'relief-campaign:'.$campaign->id.':'.$target->code.':'.$instance->revision]);
                }
            }
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/relief/campaigns/'.DB::table('relief_campaigns')->where('id', $subjectId)->value('public_id');
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('relief_campaigns')->whereIn('id', $subjectIds)->get(['id', 'reference_no', 'title'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => 'حملة إغاثية '.$row->reference_no.': '.$row->title])->all();
    }
}
