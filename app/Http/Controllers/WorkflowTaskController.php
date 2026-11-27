<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Maintenance\MaintenanceAddenda;
use App\Services\Progress\PurchaseRequestProgress;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\OpenStepRerouter;
use App\Services\Workflow\RequestAttachments;
use App\Services\Workflow\ReturnedRequestEditor;
use App\Services\Workflow\StepAssignments;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\Workflow\WorkflowSubjects;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "مهامي" and the request page where the current assignee takes action.
 */
class WorkflowTaskController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly CustomFieldService $fields,
        private readonly WorkflowVisibilityService $visibility,
        private readonly WorkflowProgress $progress,
        private readonly WorkflowSubjects $subjects,
        private readonly ApprovalDelegations $delegations,
        private readonly RequestAttachments $attachments,
        private readonly ReturnedRequestEditor $edits,
    ) {}

    public function index(Request $request, TransactionTracker $tracker): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);

        // ?type=leave (from the HR head's home page): only the leave requests among the tasks. tasksOf() already has one
        // row per request (each workflow instance is read once), so the full list and the badge keep one number.
        $leaveOnly = $request->query('type') === TransactionTracker::LEAVE_TYPE;
        $tasks = $tracker->tasksOf($user);

        return Inertia::render('Tasks/Index', [
            'leaveOnly' => $leaveOnly,
            'tasks' => $tasks->when($leaveOnly, fn ($items) => $items->where('type', TransactionTracker::LEAVE_TYPE))->values()->all(),
            // «أنجزتها» is read only when its tab is opened (a partial reload that asks for "done").
            'done' => Inertia::optional(fn (): array => $tracker->handledBy($user)),
        ]);
    }

    public function show(Request $request, string $publicId): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $instance = DB::table('workflow_instances')->where('public_id', $publicId)->first();
        abort_unless($instance, 404);
        $level = $this->visibility->accessLevel($user, (int) $instance->id);
        abort_unless($level !== null, 403);

        $isRequest = $instance->subject_type === WorkflowEngine::SUBJECT_TYPE;
        if (! $isRequest) {
            // Module records (e.g. a leave request) are handled on their own screen, stepper and actions included.
            return redirect($this->subjects->url($instance));
        }
        $actions = $this->engine->availableActions($instance, $user);
        $stepFields = [];
        $stepValues = [];
        $requestEdit = null;
        $requesterStep = null;
        if ($actions !== []) {
            $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first(['code', 'step_type', 'assignment_type']);
            // A request returned to its start step: the requester edits it in the same form as the resend.
            $editForm = $this->edits->form($instance, $user);
            if ($editForm !== null) {
                $requestEdit = ['title' => $editForm['title'], 'description' => $editForm['description']];
                $stepFields = $editForm['fields'];
                $stepValues = $editForm['values'];
            } else {
                $definitionCode = DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                    ->where('v.id', $instance->workflow_version_id)->value('d.code');
                $definitions = $this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, (string) $definitionCode, (string) $step?->code);
                $stepFields = $this->fields->forForm($definitions);
                // A step visited again starts from what it already holds.
                $stepValues = $this->fields->formValues(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id, $definitions, $level === 'full_authorized');
            }
            if ($step?->assignment_type === 'requester' && (int) $instance->requested_by === (int) $user->id) {
                $requesterStep = $step->step_type === 'start' ? 'edit' : 'verify';
            }
        }

        $isHolder = $instance->status === 'in_progress' && $this->engine->isCurrentAssignee((int) $instance->id, $user);
        $canReopen = $instance->status === 'completed' && $this->delegations->isExecutive($user);
        // «إعادة تحديد المسؤول»: the executive director or the system administrator, when nobody qualified holds the step.
        $rerouter = app(OpenStepRerouter::class);
        $recovery = $instance->status === 'in_progress' && $rerouter->mayRecover($user) ? $rerouter->recoveryState($instance) : null;

        return Inertia::render('Workflows/Show', [
            'username' => $user->name,
            'publicId' => $publicId,
            'referenceNo' => $instance->reference_no,
            'workflowName' => DB::table('workflow_versions as v')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('v.id', $instance->workflow_version_id)->value('d.name'),
            'revision' => (int) $instance->revision,
            'dueAt' => $instance->due_at,
            'slaBreached' => $instance->sla_breached_at !== null,
            'progress' => $this->progress->view($instance, $level),
            'actions' => $actions,
            // «إسناد إلى موظف»: who holds the current step, and the supervisor's candidates.
            'assignment' => app(StepAssignments::class)->panel($instance, $user, $level),
            // Suspend / resume for the current holder; reopen for the executive director (ED requirements doc §5).
            'hold' => $instance->held_at === null ? null : [
                'since' => $instance->held_at, 'until' => $instance->hold_until,
                'reason' => $level === 'public_status' ? null : $instance->hold_reason,
                // The requester's public view never names the people handling the request.
                'by' => $level === 'public_status' ? null : DB::table('users')->where('id', $instance->held_by)->value('name'),
            ],
            'canHold' => $isHolder && $instance->held_at === null && $actions !== [],
            'canResume' => $isHolder && $instance->held_at !== null,
            // Stages before a recorded issue or payment are not offered (WorkflowEngine::reopenTargets).
            'reopenSteps' => $canReopen ? $this->engine->reopenTargets($instance) : [],
            // {step, resolvable, needed, rolesUrl} while the request waits with no qualified holder; null otherwise.
            'recovery' => $recovery === null ? null : [...$recovery, 'rolesUrl' => $user->isSystemAdmin() ? route('admin.employees.index', [], false) : null],
            'stepFields' => $stepFields,
            // Stored values of those fields, keyed by code, in the form their inputs take.
            'stepValues' => (object) $stepValues,
            // {title, description} while the requester may edit a returned request; null otherwise.
            'requestEdit' => $requestEdit,
            // The requester's own turn: "edit" (returned to the start step) or "verify" (confirm what was done).
            'requesterStep' => $requesterStep,
            // Upload limits for the action form (files go with the action, to its event).
            // Null when there is no action to take, or on a confidential request, which takes no attachments.
            'attachmentPolicy' => $actions !== [] && $this->attachments->offeredOn((int) $instance->workflow_version_id) ? $this->attachments->limits() : null,
            // «طلب شراء» handed to procurement: where the purchase stands in Accounts (stage only, no prices).
            'purchase' => $isRequest ? $this->linkedPurchase((int) $instance->subject_id, $level === 'public_status') : null,
            // «طلب صيانة»: its original when it is an addendum, its addenda, and the viewer's addenda actions; null otherwise.
            'addenda' => app(MaintenanceAddenda::class)->panel($instance, $user, $level),
            'fieldValues' => $isRequest
                ? $this->fields->display(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id, $level === 'full_authorized')
                : [],
        ]);
    }

    /** @return array{number: string, progress: array<string, mixed>}|null */
    private function linkedPurchase(int $requestId, bool $public): ?array
    {
        $purchase = DB::table('finance_purchase_requests')->where('source_module', 'aims_request')->where('source_id', (string) $requestId)->first();
        if (! $purchase) {
            return null;
        }
        $progress = app(PurchaseRequestProgress::class)->view($purchase);
        if ($public) {
            // The requester's view shows the stage only: no names, Accounts document numbers or finance notes.
            $progress['steps'] = array_map(fn (array $step): array => [...$step, 'owner' => null, 'note' => null], $progress['steps']);
            $progress['summary']['where'] = $progress['summary']['status'];
        }

        return ['number' => $purchase->number, 'progress' => $progress];
    }

    public function act(Request $request, string $publicId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $instance = DB::table('workflow_instances')->where('public_id', $publicId)->first();
        abort_unless($instance, 404);

        $data = $request->validate([
            'transition_id' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'fields' => ['nullable', 'array'],
            'picked_user_id' => ['nullable', 'integer'],
            // A returned request edited by its requester with the resend (ReturnedRequestEditor); ignored elsewhere.
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array'],
        ]);

        // Files go with the action, to its event. The whole set is checked first: a refused action stores nothing.
        $files = array_values(array_filter(Arr::wrap($request->file('attachments', []))));
        if ($files !== [] && $instance->subject_type !== WorkflowEngine::SUBJECT_TYPE) {
            throw ValidationException::withMessages(['attachments' => 'المرفقات متاحة في الطلبات العامة فقط.']);
        }
        $this->attachments->validate($files, $this->attachments->offeredOn((int) $instance->workflow_version_id));

        $written = [];
        try {
            DB::transaction(function () use ($request, $user, $instance, $data, $files, &$written): void {
                $this->edits->apply($instance, $user, $data, $request->ip());
                $this->engine->act(
                    (int) $instance->id,
                    $user,
                    (int) $data['transition_id'],
                    $data['comment'] ?? null,
                    $data['fields'] ?? [],
                    isset($data['picked_user_id']) ? (int) $data['picked_user_id'] : null,
                    (int) $data['revision'],
                );
                if ($files !== []) {
                    $this->attachments->store($instance, $this->attachments->eventOf((int) $instance->id, (int) $user->id), $user, $files, $written, $request->ip());
                }
            });
        } catch (\Throwable $exception) {
            $this->attachments->cleanup($written);

            throw $exception;
        }

        return back()->with('success', 'تم تنفيذ الإجراء.');
    }

    public function hold(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'review_on' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(90)->toDateString()],
        ]);
        $this->engine->hold($this->instanceId($publicId), $request->user(), trim($data['reason']), $data['review_on'], (int) $data['revision']);

        return back()->with('success', 'عُلّقت المعاملة حتى '.$data['review_on'].'.');
    }

    public function resume(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $this->engine->resume($this->instanceId($publicId), $request->user(), $data['comment'] ?? null, (int) $data['revision']);

        return back()->with('success', 'استؤنفت المعاملة.');
    }

    public function reopen(Request $request, string $publicId, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['step_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $instanceId = $this->instanceId($publicId);
        DB::transaction(function () use ($request, $audit, $data, $instanceId): void {
            $this->engine->reopen($instanceId, $request->user(), (int) $data['step_id'], trim($data['reason']));
            $audit->recordRow('workflow.instance.reopened', 'workflow_instances', $instanceId,
                ['status' => 'completed'], ['status' => 'in_progress', 'step_id' => (int) $data['step_id'], 'reason' => trim($data['reason'])],
                $request->user()->id, $request->ip());
        });

        return back()->with('success', 'أُعيد فتح المعاملة.');
    }

    /**
     * «إعادة تحديد المسؤول»: the step's own rules choose its holder again now (OpenStepRerouter::recover). Nothing
     * is approved or skipped, and nobody is picked by hand; the reroute is audited with the person who asked.
     */
    public function reroute(Request $request, string $publicId, OpenStepRerouter $rerouter): RedirectResponse
    {
        $instanceId = $this->instanceId($publicId);
        abort_unless($this->visibility->accessLevel($request->user(), $instanceId) !== null, 403);
        $result = $rerouter->recover($instanceId, $request->user());
        if (! $result['held']) {
            throw ValidationException::withMessages(['reroute' => 'لا يوجد صاحب صلاحية مؤهل لهذه المرحلة بعد. يلزم: '.$result['needed'].'.']);
        }

        return back()->with('success', $result['changed'] ? 'أُعيد تحديد المسؤول وأُحيلت المعاملة إلى صاحب الصلاحية الحالي.' : 'المعاملة لدى صاحب صلاحية مؤهل؛ لا حاجة لإعادة التحديد.');
    }

    private function instanceId(string $publicId): int
    {
        abort_unless(request()->user()?->is_active, 403);
        $instanceId = DB::table('workflow_instances')->where('public_id', $publicId)->value('id');
        abort_unless($instanceId, 404);

        return (int) $instanceId;
    }
}
