<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** SPONSORSHIP: department draft → head recommendation → executive director's decision → active with its dues. */
final class SponsorshipWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'sponsorship';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly Sponsorships $sponsorships,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $row = DB::table('sponsorships')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($row, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'proposer') {
            $this->sponsorships->assertSubmittable($row);
        }
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['approved', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'اعتماد الكفالة قرار للمدير التنفيذي.');
            abort_if((int) $row->created_by === $actorId, 403, 'لا يعتمد معدّ الكفالة كفالته.');
        }
        if ($target->code === 'approved') {
            $this->sponsorships->activate($row, $actorId);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = match ($target->code) {
            'approved' => 'active', 'rejected' => 'rejected', 'proposer' => 'draft', default => 'pending_approval',
        };
        $update = ['status' => $status, 'updated_at' => now()];
        if ($target->code === 'approved') {
            $update += ['approved_by' => $actorId, 'approved_at' => now(), 'status_reason' => null];
        }
        if (in_array($target->code, ['rejected', 'proposer'], true)) {
            $update['status_reason'] = $comment;
        }
        DB::table('sponsorships')->where('id', $instance->subject_id)->update($update);
        $row = DB::table('sponsorships')->where('id', $instance->subject_id)->first();
        if ($target->code === 'approved') {
            $this->sponsorships->extend($row);
            $this->sponsorships->refreshRegularity((int) $row->id);
        }
        $this->audit->recordRow('sponsorships.'.$transition->action_code, 'sponsorships', (int) $row->id, null, ['status' => $status, 'workflow' => $instance->reference_no], $actorId);
        if (in_array($target->code, ['approved', 'rejected', 'proposer'], true) && (int) $row->created_by !== $actorId) {
            $this->notifications->notify((int) $row->created_by, 'sponsorship_update', [
                'title' => (Sponsorships::STATUS_LABELS[$status] ?? $status).': الكفالة '.$row->sponsorship_no,
                'url' => $this->url((int) $row->id), 'dedupe' => 'sponsorship:'.$row->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/sponsorships/'.DB::table('sponsorships')->where('id', $subjectId)->value('public_id');
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('sponsorships')->whereIn('id', $subjectIds)->get(['id', 'sponsorship_no', 'supersedes_id'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => ($row->supersedes_id ? 'تعديل كفالة ' : 'اعتماد كفالة ').$row->sponsorship_no])->all();
    }
}
