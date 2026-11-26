<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** ORPHAN_SOCIAL_VISIT: researcher's attested report → head review; the researcher never approves their own report. */
final class OrphanVisitWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'orphan_social_visit';

    public function __construct(private readonly OrphanAccess $access, private readonly NotificationCenter $notifications) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $visit = DB::table('orphan_social_visits')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($visit, 404);
        if ($from->code === 'head_review') {
            abort_unless($this->access->isHead(User::findOrFail($actorId)), 403, 'مراجعة تقارير الزيارات لرئيس قسم الكفالات.');
            abort_if((int) $visit->researcher_id === $actorId, 403, 'لا يعتمد الباحث تقريره.');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $update = match ($target->code) {
            'reviewed' => ['status' => 'reviewed', 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'review_note' => $comment],
            'researcher' => ['status' => 'returned', 'review_note' => $comment],
            default => ['status' => 'submitted'],
        };
        DB::table('orphan_social_visits')->where('id', $instance->subject_id)->update($update + ['updated_at' => now()]);
        $visit = DB::table('orphan_social_visits')->where('id', $instance->subject_id)->first();
        if (in_array($target->code, ['reviewed', 'researcher'], true)) {
            $this->notifications->notify((int) $visit->researcher_id, 'orphans_visit_update', [
                'title' => ($target->code === 'reviewed' ? 'اعتُمد تقرير الزيارة ' : 'أُعيد تقرير الزيارة ').$visit->reference_no,
                'url' => $this->url((int) $visit->id), 'dedupe' => 'visit:'.$visit->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/visits/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('orphan_social_visits')->whereIn('id', $subjectIds)->pluck('reference_no', 'id')
            ->map(fn ($reference) => 'تقرير زيارة اجتماعية '.$reference)->all();
    }
}
