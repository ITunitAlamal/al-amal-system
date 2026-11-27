<?php

namespace App\Services;

use App\Models\User;
use App\Services\Configuration\CustomFieldService;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceEditions;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\StepAssignments;
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
            ->whereRaw(EffectiveAssignments::sql('dur'))
            ->exists();

        if ($isOriginHead) {
            $level = $this->higherLevel($level, 'operational');
        }

        // المكلف الحالي يستطيع رؤية بيانات المعالجة التشغيلية؛ عدا مقدم الطلب العام حين ينتظره دوره هو
        // (تعديل طلب مُعاد أو التحقق من الإنجاز): يبقى على الحالة العامة ويتخذ إجراءه منها.
        if (
            (int) $instance->current_assignee_id === (int) $user->id
            && in_array(
                $instance->status,
                ['pending', 'in_progress'],
                true
            )
            && $instance->completed_at === null
            && ! $this->requesterOnOwnStep($user, $instance)
        ) {
            $level = $this->higherLevel($level, 'operational');
        }

        // رئيس القسم المشرف على المرحلة الحالية يتابعها تشغيليًا ليُسندها أو يسحب إسنادها (StepAssignments).
        if ($level !== 'operational' && $instance->status === 'in_progress'
            && app(StepAssignments::class)->supervisorId($instance) === (int) $user->id) {
            $level = 'operational';
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
                'wi.workflow_version_id',
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
        $isRequester = (int) $instance->requested_by === (int) $user->id;

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

            // The requester reads their own text whatever their level; other public viewers get the status only.
            if ($level !== 'public_status' || $isRequester) {
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
            ->leftJoin('users as assigned', 'assigned.id', '=', 'we.assigned_to_id')
            ->where('we.workflow_instance_id', $instanceId)
            ->orderBy('we.occurred_at')
            ->orderBy('we.id')
            ->get([
                'we.id',
                'we.workflow_transition_id',
                'we.action',
                'we.comment',
                'we.metadata',
                'we.from_step_id',
                'we.to_step_id',
                'we.occurred_at',
                'origin.name as from_step_name',
                'origin.assignment_config as from_assignment_config',
                'destination.name as to_step_name',
                'destination.assignment_type as to_assignment_type',
                'actor.name as actor_name',
                'transition.name as transition_name',
                'assigned.name as assigned_to_name',
            ]);

        $attachments = $this->attachmentsByEvent($user, $instance, $level);
        $timeline = [];
        // What the latest move into each step recorded (e.g. the worker named when a field job was registered).
        $openedWith = [];

        foreach ($events as $event) {
            // A move between steps: a transition, or a reopening (which the stepper shows as a move too).
            $isMove = $event->workflow_transition_id !== null || $event->action === 'reopen';
            // Fields the action recorded with this event (CustomFieldService::actionRecord; never sensitive ones).
            $fields = $event->workflow_transition_id !== null ? CustomFieldService::eventFields($event->metadata) : [];
            $passFields = $event->from_step_id !== null ? ($openedWith[(int) $event->from_step_id] ?? []) : [];
            if ($event->workflow_transition_id !== null && $event->to_step_id !== null) {
                $openedWith[(int) $event->to_step_id] = $fields;
            }

            // The requester sees every move between steps (action codes are
            // configurable), but not internal system events such as SLA flags.
            if ($level === 'public_status' && ! $isMove) {
                continue;
            }

            // A move to the requester's own step is addressed to them: the reason of a return, or the completion
            // note they are asked to verify.
            $addressed = $isRequester && $isMove && $event->to_assignment_type === 'requester';

            $item = [
                'id' => $event->id,
                'action' => $event->action,
                'label' => $event->transition_name,
                'from_step' => $event->from_step_name,
                'to_step' => $event->to_step_name,
                'occurred_at' => $event->occurred_at,
                'is_move' => $isMove,
                'addressed' => $addressed,
                // Only the files this viewer may open (see attachmentsByEvent).
                'attachments' => $attachments[(int) $event->id] ?? [],
            ];

            if ($level !== 'public_status') {
                $item['actor_name'] = $event->actor_name;
                $item['comment'] = $event->comment;
                // Assigning a step to an employee, or taking it back: who it went to.
                $item['assigned_to_name'] = $event->workflow_transition_id === null
                    && in_array($event->action, [StepAssignments::ACTION_ASSIGNED, StepAssignments::ACTION_WITHDRAWN], true)
                    ? $event->assigned_to_name : null;
                $item['fields'] = $fields;
            } elseif ($addressed) {
                $item['comment'] = $event->comment;
                // The outcome of a field job the requester is asked to verify: who did it (named when the job was
                // registered) and when it was actually finished. Other recorded fields, such as voucher numbers, are
                // for the people who handle the request.
                $item['fields'] = $this->isFieldWork($event->from_assignment_config) ? [...$passFields, ...$fields] : [];
            }

            $timeline[] = $item;
        }

        return [
            'access_level' => $level,
            'viewer_is_requester' => $isRequester,
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

    /** A step that waits for a field job to be documented ("field_work" in its assignment_config). */
    private function isFieldWork(mixed $assignmentConfig): bool
    {
        $config = is_string($assignmentConfig) && $assignmentConfig !== '' ? json_decode($assignmentConfig, true) : $assignmentConfig;

        return is_array($config) && ! empty($config['field_work']);
    }

    public function canViewAttachment(
        User $user,
        int $instanceId,
        int $attachmentId
    ): bool {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        $publicId = DB::table('workflow_event_attachments')->where('id', $attachmentId)->value('public_id');
        $file = $instance && $publicId ? $this->attachment($instance, (string) $publicId) : null;

        return $file !== null && $this->mayOpenAttachment($user, $instance, $this->accessLevel($user, $instanceId), $file);
    }

    /**
     * One file attached to an event of this request, with where it is stored; null when it is not on this request.
     * Says nothing about who may open it: ask mayOpenAttachment().
     */
    public function attachment(object $instance, string $publicId): ?object
    {
        return $this->attachmentRows((int) $instance->id)->where('a.public_id', $publicId)->first();
    }

    /**
     * Whether this viewer may open a file attached to the request (a row from attachment()). The one rule for
     * the list on the request page and for every download:
     * - an operational or full view opens every attachment its level allows by class (generic requests store
     *   theirs as "internal", so all of them);
     * - the requester's public view opens the files they uploaded themselves, and the files that came with a
     *   move to their own step (the completion evidence to verify, or what was sent back with a return);
     * - any other public view opens only the viewer's own uploads;
     * - on a confidential workflow, only the full view opens attachments. Nobody holds that view on a confidential
     *   request, which is why such requests accept no files (RequestAttachments::offeredOn()).
     *
     * @param  ?string  $level  the viewer's access level on the request (accessLevel())
     */
    public function mayOpenAttachment(User $user, object $instance, ?string $level, object $file): bool
    {
        return $this->opensAttachment($user, $instance, $level, $file, $this->isConfidential((int) $instance->workflow_version_id));
    }

    private function opensAttachment(User $user, object $instance, ?string $level, object $file, bool $confidential): bool
    {
        if ($level === null) {
            return false;
        }
        if ($confidential) {
            return $level === 'full_authorized';
        }
        if ($level !== 'public_status') {
            return $this->allowsAttachmentClass($level, (string) $file->visibility_class);
        }
        if (! in_array($file->visibility_class, ['general', 'internal'], true)) {
            return false;
        }
        if ((int) $file->uploaded_by === (int) $user->id) {
            return true;
        }

        return (int) $instance->requested_by === (int) $user->id
            && $file->workflow_transition_id !== null
            && $file->to_assignment_type === 'requester';
    }

    /**
     * The attachments of a request this viewer may open, grouped by the event they were sent with (one query).
     * A public viewer is not told who uploaded a file, except their own.
     *
     * @return array<int, list<array<string, mixed>>> keyed by workflow_events.id
     */
    public function attachmentsByEvent(User $user, object $instance, ?string $level): array
    {
        $result = [];
        $files = $this->attachmentRows((int) $instance->id)->orderBy('a.id')->get();
        $confidential = $files->isNotEmpty() && $this->isConfidential((int) $instance->workflow_version_id);
        foreach ($files as $file) {
            if (! $this->opensAttachment($user, $instance, $level, $file, $confidential)) {
                continue;
            }
            $mine = (int) $file->uploaded_by === (int) $user->id;
            $result[(int) $file->workflow_event_id][] = [
                'public_id' => $file->public_id,
                'filename' => $file->original_filename,
                'file_size' => (int) $file->file_size,
                'mime_type' => $file->mime_type,
                'document_type' => $file->document_type,
                'created_at' => $file->created_at,
                'mine' => $mine,
                'uploaded_by_name' => $level !== 'public_status' || $mine ? $file->uploader_name : null,
                'url' => route('workflows.attachments.download', ['publicId' => $instance->public_id, 'attachment' => $file->public_id], false),
            ];
        }

        return $result;
    }

    private function attachmentRows(int $instanceId): Builder
    {
        return DB::table('workflow_event_attachments as a')
            ->join('workflow_events as e', 'e.id', '=', 'a.workflow_event_id')
            ->leftJoin('workflow_steps as destination', 'destination.id', '=', 'e.to_step_id')
            ->leftJoin('users as uploader', 'uploader.id', '=', 'a.uploaded_by')
            ->where('e.workflow_instance_id', $instanceId)
            ->select([
                'a.id', 'a.public_id', 'a.workflow_event_id', 'a.uploaded_by', 'a.original_filename', 'a.storage_disk', 'a.storage_path',
                'a.mime_type', 'a.file_size', 'a.visibility_class', 'a.document_type', 'a.created_at',
                'e.workflow_transition_id', 'destination.assignment_type as to_assignment_type', 'uploader.name as uploader_name',
            ]);
    }

    private function isConfidential(int $versionId): bool
    {
        return (bool) DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $versionId)->value('d.is_confidential');
    }

    /**
     * A generic request waiting at a step that belongs to its requester (editing a returned request, or verifying
     * what was done), seen by that requester. Holding such a step is not handling the request, so it gives no
     * operational view. A requester who acted on a step of another holder of this request (a structural holder
     * of that step) is an insider and is left as before.
     */
    private function requesterOnOwnStep(User $user, object $instance): bool
    {
        if ($instance->subject_type !== 'aims_request' || (int) $instance->requested_by !== (int) $user->id) {
            return false;
        }
        if (DB::table('workflow_steps')->where('id', $instance->current_step_id)->value('assignment_type') !== 'requester') {
            return false;
        }

        return ! DB::table('workflow_events as e')->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
            ->where('e.workflow_instance_id', $instance->id)->where('e.actor_id', $user->id)
            ->whereNotNull('e.workflow_transition_id')->where('s.assignment_type', '<>', 'requester')
            ->exists();
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
