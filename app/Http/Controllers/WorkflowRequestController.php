<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowCatalog;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "طلباتي": every request the signed-in employee made (generic requests and leave), where each one is now,
 * with filters; and the generic form for any workflow the administrator marked as startable.
 */
class WorkflowRequestController extends Controller
{
    public function __construct(
        private readonly WorkflowCatalog $catalog,
        private readonly WorkflowEngine $engine,
        private readonly CustomFieldService $fields,
        private readonly AssigneeResolver $assignees,
        private readonly NavigationAccessRules $accessRules,
    ) {}

    public function index(Request $request, TransactionTracker $tracker): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(TransactionTracker::STATUS_FILTERS))],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        unset($filters['page']);

        return Inertia::render('Requests/Index', [
            'requests' => $tracker->requestsOf($user, $filters, $page),
            'filters' => (object) $filters,
            'typeOptions' => $tracker->typeOptions($user),
            'statusOptions' => TransactionTracker::STATUS_FILTERS,
            'startable' => $this->catalog->startableFor($user),
            'canRequestLeave' => $this->accessRules->allows($user, 'leave_requester'),
        ]);
    }

    public function create(Request $request, string $code): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $definition = $this->catalog->startableDefinition($code);
        $version = $definition ? $this->catalog->publishedVersion((int) $definition->id) : null;
        abort_unless($definition && $version, 404, 'نوع الطلب غير متاح حاليًا.');

        return Inertia::render('Requests/Create', [
            'definition' => ['code' => $definition->code, 'name' => $definition->name, 'description' => $definition->description],
            'fields' => $this->fields->forForm($this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, $definition->code)),
            'pickOptions' => $this->firstStepPickOptions($user, (int) $version->id),
            'hasDepartment' => (bool) $user->department_id,
        ]);
    }

    public function store(Request $request, string $code): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'fields' => ['nullable', 'array'],
            'picked_user_id' => ['nullable', 'integer'],
        ]);

        $instance = $this->engine->start(
            $user,
            $code,
            ['title' => $data['title'], 'description' => $data['description'] ?? null],
            $data['fields'] ?? [],
            isset($data['picked_user_id']) ? (int) $data['picked_user_id'] : null,
        );

        return redirect()->route('workflows.show', $instance->public_id)
            ->with('success', 'تم إرسال الطلب برقم '.$instance->reference_no.'.');
    }

    /**
     * When the step after submission is chosen by the requester, offer the eligible people.
     *
     * @return list<array{id: int, name: string}>
     */
    private function firstStepPickOptions(User $user, int $versionId): array
    {
        $next = DB::table('workflow_steps as start')
            ->join('workflow_transitions as t', 't.from_step_id', '=', 'start.id')
            ->join('workflow_steps as next', 'next.id', '=', 't.to_step_id')
            ->where('start.workflow_version_id', $versionId)
            ->where('start.step_type', 'start')
            ->where('t.is_active', true)
            ->first(['next.assignment_type', 'next.assigned_role_code', 'next.assignment_config']);

        if (! $next || $next->assignment_type !== 'pick') {
            return [];
        }

        return $this->assignees->pickOptions($next, (object) ['requested_by' => $user->id, 'department_id' => $user->department_id]);
    }
}
