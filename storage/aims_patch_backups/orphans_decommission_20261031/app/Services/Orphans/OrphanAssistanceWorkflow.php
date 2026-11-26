<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * ORPHAN_ASSISTANCE: request → head recommendation → executive decision → execution by Accounts/stores (the comment
 * is their document reference) → delivery confirmed by the requester (the comment is the proof) → closed.
 */
final class OrphanAssistanceWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'orphan_assistance';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly FinanceGuard $finance,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $row = DB::table('orphan_assistance_requests')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($row, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['execution', 'rejected'], true)) {
            abort_unless($this->access->isExecutive($actor), 403, 'قرار المساعدة للمدير التنفيذي.');
            abort_if((int) $row->created_by === $actorId, 403, 'لا يبت مقدم الطلب في طلبه.');
        }
        if ($from->code === 'execution' && $target->code === 'delivery') {
            abort_unless(! $actor->isSystemAdmin() && ($this->finance->allows($actor, 'finance.sponsorships.record') || $this->finance->allows($actor, 'finance.stock.issue')), 403,
                'التنفيذ يسجله قسم الحسابات/المخازن من مستنداته.');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = match ($target->code) {
            'requester' => 'returned', 'execution' => 'approved', 'delivery' => 'executed', 'closed' => 'delivered', 'rejected' => 'rejected', default => 'in_review',
        };
        $update = ['status' => $status, 'updated_at' => now()];
        if ($target->code === 'delivery') {
            $update += ['execution_reference' => mb_substr(trim((string) $comment), 0, 120), 'executed_by' => $actorId, 'executed_at' => now()];
        }
        if ($target->code === 'closed') {
            $update += ['delivery_proof' => trim((string) $comment), 'delivered_by' => $actorId, 'delivered_at' => now()];
        }
        DB::table('orphan_assistance_requests')->where('id', $instance->subject_id)->update($update);
        $row = DB::table('orphan_assistance_requests')->where('id', $instance->subject_id)->first();
        $this->audit->recordRow('orphans.assistance.'.$transition->action_code, 'orphan_assistance_requests', (int) $row->id, null, ['status' => $status, 'workflow' => $instance->reference_no], $actorId);
        if (in_array($status, ['returned', 'approved', 'executed', 'rejected'], true) && (int) $row->created_by !== $actorId) {
            $this->notifications->notify((int) $row->created_by, 'assistance_update', [
                'title' => (OrphanAssistance::STATUS_LABELS[$status] ?? $status).': طلب المساعدة '.$row->reference_no,
                'url' => $this->url((int) $row->id), 'dedupe' => 'assistance:'.$row->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/assistance/'.DB::table('orphan_assistance_requests')->where('id', $subjectId)->value('public_id');
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('orphan_assistance_requests')->whereIn('id', $subjectIds)->pluck('reference_no', 'id')
            ->map(fn ($reference): string => 'مساعدة لأسرة/يتيم '.$reference)->all();
    }
}
