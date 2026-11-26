<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/** ORPHAN_CHANGE on the central engine: staff verification → head recommendation → executive decision → applied. */
final class OrphanChangeWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'orphan_change_request';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanChanges $changes,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $request = DB::table('orphan_change_requests')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($request, 404);
        $actor = User::findOrFail($actorId);
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['applied', 'rejected'], true)) {
            abort_if((int) $instance->requested_by === $actorId || (int) $request->requested_by === $actorId, 403, 'لا يبت مقدم الطلب في طلبه.');
            abort_if($actor->isSystemAdmin() && ! $this->access->isExecutive($actor) && (int) $instance->current_assignee_id !== $actorId, 403, 'مدير النظام لا يتخذ القرار الإداري.');
        }
        if ($target->code === 'applied') {
            $this->changes->apply($request, $actorId);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = match ($target->code) {
            'applied' => 'applied', 'rejected' => 'rejected', 'staff_verification' => 'returned', default => 'in_review',
        };
        DB::table('orphan_change_requests')->where('id', $instance->subject_id)->update(['status' => $status,
            'decision_note' => in_array($target->code, ['applied', 'rejected'], true) ? $comment : DB::raw('decision_note'), 'updated_at' => now()]);
        $request = DB::table('orphan_change_requests')->where('id', $instance->subject_id)->first();
        if (in_array($target->code, ['applied', 'rejected', 'staff_verification'], true) && (int) $instance->requested_by !== $actorId) {
            $this->notifications->notify((int) $instance->requested_by, 'orphans_change_update', [
                'title' => (OrphanChanges::STATUS_LABELS[$status] ?? $status).': طلب تعديل '.$request->reference_no,
                'url' => $this->url((int) $request->id), 'dedupe' => 'orphan-change:'.$request->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/changes/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('orphan_change_requests')->whereIn('id', $subjectIds)->get(['id', 'reference_no', 'category'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => 'تعديل ملف يتيم '.$row->reference_no.': '.(OrphanChanges::CATEGORY_LABELS[$row->category] ?? $row->category)])->all();
    }
}
