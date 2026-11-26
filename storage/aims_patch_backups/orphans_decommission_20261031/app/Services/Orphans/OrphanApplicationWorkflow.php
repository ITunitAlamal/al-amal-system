<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORPHAN_FILE_ACTIVATION on the central engine: department review → head recommendation per child → executive
 * director's decision per child. The approval applies the decisions inside the same transaction as the move.
 */
final class OrphanApplicationWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'orphan_application';

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanApplications $applications,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $application = DB::table('orphan_applications')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($application, 404);
        $actor = User::findOrFail($actorId);
        $rows = DB::table('orphan_application_children')->where('orphan_application_id', $application->id)->get();
        if ($from->code === 'head_recommendation' && $target->code === 'ed_decision') {
            abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
            if ($rows->whereNull('recommendation')->isNotEmpty()) {
                throw ValidationException::withMessages(['application' => 'سجّل توصيتك لكل طفل قبل رفع الطلب للمدير التنفيذي.']);
            }
        }
        if ($from->code === 'ed_decision' && in_array($target->code, ['activated', 'rejected'], true)) {
            // The engine routes this step to the executive director or a written delegate; the system admin's technical role never decides.
            abort_if((int) $instance->requested_by === $actorId, 403, 'لا يبت مقدم الطلب في طلبه.');
            abort_if($actor->isSystemAdmin() && ! $this->access->isExecutive($actor) && ! $this->isDelegate($instance, $actorId), 403, 'مدير النظام لا يتخذ القرار الإداري.');
        }
        if ($target->code === 'activated') {
            $counts = $this->applications->applyDecisions((int) $application->id, $actorId);
            if ($counts['approved'] === 0) {
                throw ValidationException::withMessages(['application' => 'لا يوجد طفل بقرار اعتماد؛ استخدم «رفض الطلب» أو عدّل القرارات.']);
            }
        }
        if ($target->code === 'rejected') {
            DB::table('orphan_application_children')->where('orphan_application_id', $application->id)
                ->update(['decision' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now(), 'updated_at' => now()]);
            DB::table('orphan_children')->whereIn('id', $rows->pluck('orphan_child_id'))->where('status', 'pending_activation')
                ->update(['status' => 'applicant', 'updated_at' => now()]);
            DB::table('orphan_applications')->where('id', $application->id)->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now(), 'updated_at' => now()]);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $application = DB::table('orphan_applications')->where('id', $instance->subject_id)->first();
        if ($target->code === 'staff_review') {
            DB::table('orphan_applications')->where('id', $application->id)->update(['status' => 'under_review', 'updated_at' => now()]);
        } elseif (in_array($target->code, ['head_recommendation', 'ed_decision'], true)) {
            DB::table('orphan_applications')->where('id', $application->id)->update(['status' => 'in_decision', 'updated_at' => now()]);
        }
        if (in_array($target->code, ['activated', 'rejected'], true) && $comment) {
            DB::table('orphan_applications')->where('id', $application->id)->update(['decision_reason' => $comment]);
        }
        $this->audit->recordRow('orphans.application.'.$transition->action_code, 'orphan_applications', (int) $application->id, ['status' => $application->status],
            ['step' => $target->code, 'workflow' => $instance->reference_no], $actorId);
        if ((int) $instance->requested_by !== $actorId && in_array($target->code, ['activated', 'rejected', 'staff_review'], true)) {
            $this->notifications->notify((int) $instance->requested_by, 'orphans_application_update', [
                'title' => match ($target->code) {
                    'activated' => 'صدر قرار اعتماد طلب ملف أيتام', 'rejected' => 'رُفض طلب ملف أيتام', default => 'أُعيد طلب ملف أيتام للاستكمال',
                }.' '.$application->reference_no,
                'url' => $this->url((int) $application->id), 'dedupe' => 'orphan-app:'.$application->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/orphans/applications/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        // Titles carry the reference and the number of children only: no child names in inboxes or notifications.
        return DB::table('orphan_applications as a')->whereIn('a.id', $subjectIds)
            ->get(['a.id', 'a.reference_no', DB::raw('(select count(*) from orphan_application_children c where c.orphan_application_id = a.id) as children')])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => "طلب ملف أيتام {$row->reference_no} ({$row->children} طفل)"])->all();
    }

    private function isDelegate(object $instance, int $actorId): bool
    {
        return (int) $instance->current_assignee_id === $actorId;
    }

    /** Child names for the screens of authorised users only. */
    public static function childName(?string $cipher): string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
