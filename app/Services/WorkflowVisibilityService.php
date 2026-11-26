<?php

namespace App\Services;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceEditions;
use App\Services\Workflow\ApprovalDelegations;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WorkflowVisibilityService
{
    /**
     * Versions of confidential workflows (complaints), for lists that must leave them out.
     */
    public static function confidentialVersions(): Builder
    {
        return DB::table('workflow_versions as cv')->join('workflow_definitions as cd', 'cd.id', '=', 'cv.workflow_definition_id')
            ->where('cd.is_confidential', true)->select('cv.id');
    }

    public function accessLevel(User $user, int $instanceId): ?string
    {
        if (! $user->is_active) {
            return null;
        }

        $instance = DB::table('workflow_instances')
            ->where('id', $instanceId)
            ->first();

        if (! $instance) {
            return null;
        }
        if ($instance->subject_type === 'official_correspondence') {
            return app(CorrespondenceAccess::class)->visible($user)->where('c.id', $instance->subject_id)->exists() ? 'public_status' : null;
        }
        if ($instance->subject_type === 'correspondence_edition') {
            $edition = DB::table('correspondence_editions')->find($instance->subject_id);
            if (! $edition || ! app(CorrespondenceEditions::class)->canView($user, $edition)) {
                return null;
            }

            $hasOperationalParticipation = DB::table('workflow_participants')
                ->where('workflow_instance_id', $instanceId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->whereIn('visibility_scope', ['operational', 'full_authorized'])
                ->exists();

            return (int) $instance->current_assignee_id === (int) $user->id || $hasOperationalParticipation
                ? 'operational'
                : 'public_status';
        }

        // A confidential request (a complaint) is seen only by the people on it: its author and whoever handled or
        // holds it. Not the department head, not the system administrator's technical access (SRS §2.4, TA07).
        if (DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $instance->workflow_version_id)->value('d.is_confidential')) {
            $level = (int) $instance->requested_by === (int) $user->id ? 'public_status' : null;
            foreach (DB::table('workflow_participants')->where('workflow_instance_id', $instanceId)->where('user_id', $user->id)
                ->where('is_active', true)->whereNull('revoked_at')->pluck('visibility_scope') as $scope) {
                $level = $this->higherLevel($level, $scope);
            }

            return $level;
        }

        if ($user->isSystemAdmin()) {
            return 'full_authorized';
        }

        // المدير التنفيذي يتابع مرحلة كل معاملة والجهة الحالية والتأخير (SRS §3.5.1.1 وCORE05، وثيقة المدير التنفيذي §3)؛
        // ما يتجاوز الحالة العامة يحتاج مشاركة في المعاملة.
        $level = app(ApprovalDelegations::class)->isExecutive($user) ? 'public_status' : null;

        // مقدم الطلب: الحالة العامة وسجل الإحالات فقط.
        if ((int) $instance->requested_by === (int) $user->id) {
            $level = 'public_status';
        }

        // رئيس القسم الأصلي، بعد التحقق من التكليف والدور.
        $isOriginHead = DB::table('departments as d')
            ->join('department_user_roles as dur', function ($join) {
                $join->on('dur.department_id', '=', 'd.id');
            })
            ->join('roles as r', 'r.id', '=', 'dur.role_id')
            ->where('d.id', $instance->department_id)
            ->where('d.head_user_id', $user->id)
            ->where('d.is_active', true)
            ->whereNull('d.deleted_at')
            ->where('dur.user_id', $user->id)
            ->where('r.code', 'department_head')
            ->exists();

        if ($isOriginHead) {
            $level = $this->higherLevel($level, 'operational');
        }

        // المكلف الحالي يستطيع رؤية بيانات المعالجة التشغيلية.
        if (
            (int) $instance->current_assignee_id === (int) $user->id
            && in_array(
                $instance->status,
                ['pending', 'in_progress'],
                true
            )
            && $instance->completed_at === null
        ) {
            $level = $this->higherLevel($level, 'operational');
        }

        // مشاركة صريحة في المعاملة.
        $participations = DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->pluck('visibility_scope');

        foreach ($participations as $scope) {
            if (in_array(
                $scope,
                ['public_status', 'operational', 'full_authorized'],
                true
            )) {
                $level = $this->higherLevel($level, $scope);
            }
        }

        return $level;
    }

    public function detail(User $user, int $instanceId): array
    {
        $level = $this->accessLevel($user, $instanceId);

        if ($level === null) {
            abort(403, 'غير مصرح لك بعرض هذه المعاملة.');
        }

        $instance = DB::table('workflow_instances as wi')
            ->leftJoin(
                'workflow_steps as ws',
                'ws.id',
                '=',
                'wi.current_step_id'
            )
            ->leftJoin(
                'users as assignee',
                'assignee.id',
                '=',
                'wi.current_assignee_id'
            )
            ->where('wi.id', $instanceId)
            ->select([
                'wi.id',
                'wi.public_id',
                'wi.subject_type',
                'wi.subject_id',
                'wi.department_id',
                'wi.requested_by',
                'wi.current_assignee_id',
                'wi.status',
                'wi.started_at',
                'wi.completed_at',
                'wi.created_at',
                'ws.name as current_step_name',
                'assignee.name as current_assignee_name',
            ])
            ->first();

        abort_unless($instance, 404);

        $requestData = null;

        if ($instance->subject_type === 'aims_request') {
            $request = DB::table('aims_requests')
                ->where('id', $instance->subject_id)
                ->where('requested_by', $instance->requested_by)
                ->where('department_id', $instance->department_id)
                ->first();

            abort_unless($request, 404);

            $requestData = [
                'public_id' => $request->public_id,
                'request_type' => $request->request_type,
                'title' => $request->title,
                'status' => $request->status,
            ];

            if ($level !== 'public_status') {
                $requestData['description'] = $request->description;
            }
        }

        $events = DB::table('workflow_events as we')
            ->leftJoin(
                'workflow_steps as origin',
                'origin.id',
                '=',
                'we.from_step_id'
            )
            ->leftJoin(
                'workflow_steps as destination',
                'destination.id',
                '=',
                'we.to_step_id'
            )
            ->leftJoin(
                'users as actor',
                'actor.id',
                '=',
                'we.actor_id'
            )
            ->leftJoin(
                'workflow_transitions as transition',
                'transition.id',
                '=',
                'we.workflow_transition_id'
            )
            ->where('we.workflow_instance_id', $instanceId)
            ->orderBy('we.occurred_at')
            ->orderBy('we.id')
            ->get([
                'we.id',
                'we.workflow_transition_id',
                'we.action',
                'we.comment',
                'we.occurred_at',
                'origin.name as from_step_name',
                'destination.name as to_step_name',
                'actor.name as actor_name',
                'transition.name as transition_name',
            ]);

        $timeline = [];

        foreach ($events as $event) {
            // The requester sees every move between steps (action codes are
            // configurable), but not internal system events such as SLA flags.
            if ($level === 'public_status' && $event->workflow_transition_id === null) {
                continue;
            }

            $item = [
                'action' => $event->action,
                'label' => $event->transition_name,
                'from_step' => $event->from_step_name,
                'to_step' => $event->to_step_name,
                'occurred_at' => $event->occurred_at,
            ];

            if ($level !== 'public_status') {
                $item['id'] = $event->id;
                $item['actor_name'] = $event->actor_name;
                $item['comment'] = $event->comment;

                $item['attachments'] = $this->attachmentsForEvent(
                    $user,
                    $instanceId,
                    $event->id,
                    $level
                );
            }

            $timeline[] = $item;
        }

        return [
            'access_level' => $level,
            'instance' => [
                'public_id' => $instance->public_id,
                'status' => $instance->status,
                'current_step_name' => $instance->current_step_name,
                'current_responsible' => $level === 'public_status'
                        ? $instance->current_step_name
                        : $instance->current_assignee_name,
                'started_at' => $instance->started_at,
                'completed_at' => $instance->completed_at,
                'created_at' => $instance->created_at,
            ],
            'request' => $requestData,
            'timeline' => $timeline,
        ];
    }

    public function canViewAttachment(
        User $user,
        int $instanceId,
        int $attachmentId
    ): bool {
        $level = $this->accessLevel($user, $instanceId);

        if ($level === null || $level === 'public_status') {
            return false;
        }

        $attachment = DB::table(
            'workflow_event_attachments as a'
        )
            ->join(
                'workflow_events as e',
                'e.id',
                '=',
                'a.workflow_event_id'
            )
            ->where('a.id', $attachmentId)
            ->where('e.workflow_instance_id', $instanceId)
            ->first(['a.visibility_class']);

        if (! $attachment) {
            return false;
        }

        return $this->allowsAttachmentClass(
            $level,
            $attachment->visibility_class
        );
    }

    private function attachmentsForEvent(
        User $user,
        int $instanceId,
        int $eventId,
        string $level
    ): array {
        $attachments = DB::table(
            'workflow_event_attachments'
        )
            ->where('workflow_event_id', $eventId)
            ->orderBy('id')
            ->get([
                'id',
                'public_id',
                'original_filename',
                'file_size',
                'mime_type',
                'visibility_class',
                'document_type',
                'created_at',
            ]);

        return $attachments
            ->filter(
                fn ($attachment) => $this->allowsAttachmentClass(
                    $level,
                    $attachment->visibility_class
                )
            )
            ->map(fn ($attachment) => [
                'public_id' => $attachment->public_id,
                'filename' => $attachment->original_filename,
                'file_size' => $attachment->file_size,
                'mime_type' => $attachment->mime_type,
                'document_type' => $attachment->document_type,
                'created_at' => $attachment->created_at,
            ])
            ->values()
            ->all();
    }

    private function allowsAttachmentClass(
        string $level,
        string $classification
    ): bool {
        if ($level === 'full_authorized') {
            return in_array(
                $classification,
                [
                    'general',
                    'internal',
                    'financial',
                    'confidential',
                ],
                true
            );
        }

        return $level === 'operational'
            && in_array(
                $classification,
                ['general', 'internal'],
                true
            );
    }

    private function higherLevel(
        ?string $current,
        string $incoming
    ): string {
        $weights = [
            'public_status' => 1,
            'operational' => 2,
            'full_authorized' => 3,
        ];

        if ($current === null) {
            return $incoming;
        }

        return $weights[$incoming] > $weights[$current]
            ? $incoming
            : $current;
    }
}
