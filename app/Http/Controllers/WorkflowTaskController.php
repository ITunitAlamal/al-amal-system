<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Progress\PurchaseRequestProgress;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\Workflow\WorkflowSubjects;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function index(Request $request, TransactionTracker $tracker): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);

        return Inertia::render('Tasks/Index', ['tasks' => $tracker->tasksOf($user)->values()->all()]);
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
        if ($isRequest && $actions !== []) {
            $stepCode = DB::table('workflow_steps')->where('id', $instance->current_step_id)->value('code');
            $definitionCode = DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('v.id', $instance->workflow_version_id)->value('d.code');
            $stepFields = $this->fields->forForm($this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, (string) $definitionCode, (string) $stepCode));
        }

        $isHolder = $instance->status === 'in_progress' && $this->engine->isCurrentAssignee((int) $instance->id, $user);
        $canReopen = $instance->status === 'completed' && $this->delegations->isExecutive($user);

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
            // Suspend / resume for the current holder; reopen for the executive director (ED requirements doc §5).
            'hold' => $instance->held_at === null ? null : [
                'since' => $instance->held_at, 'until' => $instance->hold_until,
                'reason' => $level === 'public_status' ? null : $instance->hold_reason,
                'by' => DB::table('users')->where('id', $instance->held_by)->value('name'),
            ],
            'canHold' => $isHolder && $instance->held_at === null && $actions !== [],
            'canResume' => $isHolder && $instance->held_at !== null,
            'reopenSteps' => $canReopen ? DB::table('workflow_steps')->where('workflow_version_id', $instance->workflow_version_id)
                ->where('step_type', '<>', 'end')->orderBy('sort_order')->get(['id', 'name'])->all() : [],
            'stepFields' => $stepFields,
            // «طلب شراء» handed to procurement: where the purchase stands in Accounts (stage only, no prices).
            'purchase' => $isRequest ? $this->linkedPurchase((int) $instance->subject_id) : null,
            'fieldValues' => $isRequest
                ? $this->fields->display(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id, $level === 'full_authorized')
                : [],
        ]);
    }

    /** @return array{number: string, progress: array<string, mixed>}|null */
    private function linkedPurchase(int $requestId): ?array
    {
        $purchase = DB::table('finance_purchase_requests')->where('source_module', 'aims_request')->where('source_id', (string) $requestId)->first();

        return $purchase ? ['number' => $purchase->number, 'progress' => app(PurchaseRequestProgress::class)->view($purchase)] : null;
    }

    public function act(Request $request, string $publicId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $instanceId = DB::table('workflow_instances')->where('public_id', $publicId)->value('id');
        abort_unless($instanceId, 404);

        $data = $request->validate([
            'transition_id' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'fields' => ['nullable', 'array'],
            'picked_user_id' => ['nullable', 'integer'],
        ]);

        $this->engine->act(
            (int) $instanceId,
            $user,
            (int) $data['transition_id'],
            $data['comment'] ?? null,
            $data['fields'] ?? [],
            isset($data['picked_user_id']) ? (int) $data['picked_user_id'] : null,
            (int) $data['revision'],
        );

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

    private function instanceId(string $publicId): int
    {
        abort_unless(request()->user()?->is_active, 403);
        $instanceId = DB::table('workflow_instances')->where('public_id', $publicId)->value('id');
        abort_unless($instanceId, 404);

        return (int) $instanceId;
    }
}
