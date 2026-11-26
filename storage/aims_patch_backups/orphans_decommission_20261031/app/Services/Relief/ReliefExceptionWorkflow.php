<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\OrphanAccess;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * RELIEF_EXCEPTION: request → head's opinion → the executive director's personal decision. The ED step carries no
 * delegation scope, the move re-checks the role, and the database trigger refuses any other decider.
 */
final class ReliefExceptionWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'relief_exception';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly ReliefExceptions $exceptions,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $exception = DB::table('relief_exceptions')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($exception, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_review' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'رأي رئيس قسم الكفالات قبل قرار المدير التنفيذي.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['approved', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'استثناءات الحملات يقررها المدير التنفيذي وحده.');
            abort_if((int) $exception->requested_by === $actorId, 403, 'لا يقرر مقدم الطلب طلبه.');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        if ($target->code === 'ed_decision') {
            DB::table('relief_exceptions')->where('id', $instance->subject_id)->update(['head_recommendation' => $comment, 'updated_at' => now()]);
        }
        if (in_array($target->code, ['approved', 'rejected'], true)) {
            $this->exceptions->decide((int) $instance->subject_id, $actorId, $target->code === 'approved', $comment);
        }
        $exception = DB::table('relief_exceptions')->where('id', $instance->subject_id)->first();
        $this->audit->recordRow('relief.exception.'.$transition->action_code, 'relief_exceptions', (int) $exception->id, null, ['status' => $exception->status, 'workflow' => $instance->reference_no], $actorId);
        if (in_array($target->code, ['approved', 'rejected', 'requester'], true) && (int) $exception->requested_by !== $actorId) {
            $this->notifications->notify((int) $exception->requested_by, 'relief_exception_decided', ['title' => (ReliefExceptions::STATUS_LABELS[$exception->status] ?? $exception->status)
                .': طلب الاستثناء '.$exception->reference_no, 'url' => $this->url((int) $exception->id), 'dedupe' => 'relief-exception:'.$exception->id.':'.$target->code.':'.$instance->revision]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/relief/exceptions/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('relief_exceptions as e')->join('relief_campaigns as c', 'c.id', '=', 'e.campaign_id')->whereIn('e.id', $subjectIds)->get(['e.id', 'e.reference_no', 'c.reference_no as campaign'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => 'استثناء '.$row->reference_no.' في الحملة '.$row->campaign])->all();
    }
}
