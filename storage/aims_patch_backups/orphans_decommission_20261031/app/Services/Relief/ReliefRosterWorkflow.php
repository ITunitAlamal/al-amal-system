<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\OrphanAccess;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** RELIEF_ROSTER: frozen roster revision → head recommendation → executive approval (reserves the allocations). */
final class ReliefRosterWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'relief_roster';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly CampaignRosters $rosters,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $roster = DB::table('relief_rosters')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($roster, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'preparer') {
            $this->rosters->assertIntact($roster);
        }
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['approved', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'اعتماد كشف المستفيدين قرار للمدير التنفيذي.');
        }
        if ($target->code === 'approved') {
            $this->rosters->approve((int) $roster->id, $actorId);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        if ($target->code === 'rejected') {
            DB::table('relief_rosters')->where('id', $instance->subject_id)->update(['status' => 'rejected', 'notes' => $comment, 'updated_at' => now()]);
        } elseif ($target->code === 'preparer') {
            // Returned: the revision becomes a draft again so it can be regenerated, then resubmitted on the same transaction.
            DB::table('relief_rosters')->where('id', $instance->subject_id)->update(['status' => 'draft', 'notes' => $comment, 'updated_at' => now()]);
        } elseif ($from->code === 'preparer') {
            DB::table('relief_rosters')->where('id', $instance->subject_id)->update(['status' => 'pending_approval', 'submitted_at' => now(), 'updated_at' => now()]);
        }
        $roster = DB::table('relief_rosters')->where('id', $instance->subject_id)->first();
        $campaign = DB::table('relief_campaigns')->where('id', $roster->campaign_id)->first();
        $this->audit->recordRow('relief.roster.'.$transition->action_code, 'relief_rosters', (int) $roster->id, null, ['status' => $roster->status, 'workflow' => $instance->reference_no,
            'snapshot' => $roster->snapshot_hash], $actorId);
        if (in_array($target->code, ['approved', 'rejected', 'preparer'], true) && (int) $roster->created_by !== $actorId) {
            $this->notifications->notify((int) $roster->created_by, 'relief_campaign_update', ['title' => (CampaignRosters::STATUS_LABELS[$roster->status] ?? $roster->status)
                .': كشف الحملة '.$campaign->reference_no.' (المراجعة '.$roster->revision.')', 'url' => $this->url((int) $roster->id),
                'dedupe' => 'relief-roster:'.$roster->id.':'.$target->code.':'.$instance->revision]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        $campaign = DB::table('relief_rosters as r')->join('relief_campaigns as c', 'c.id', '=', 'r.campaign_id')->where('r.id', $subjectId)->value('c.public_id');

        return '/relief/campaigns/'.$campaign.'?tab=roster';
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('relief_rosters as r')->join('relief_campaigns as c', 'c.id', '=', 'r.campaign_id')->whereIn('r.id', $subjectIds)->get(['r.id', 'r.revision', 'c.reference_no'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => 'كشف مستفيدي الحملة '.$row->reference_no.' (المراجعة '.$row->revision.')'])->all();
    }
}
