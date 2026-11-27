<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Maintenance\MaintenanceAddenda;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\RequestAttachments;
use App\Services\Workflow\WorkflowCatalog;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly RequestAttachments $attachments,
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
        $fields = $this->fields->forForm($this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, $definition->code));

        return Inertia::render('Requests/Create', [
            'definition' => ['code' => $definition->code, 'name' => $definition->name, 'description' => $definition->description],
            'fields' => $fields,
            // A draft saved on the old maintenance screen: its text fills the form ({title, description, fields}).
            'initial' => $definition->code === 'MAINTENANCE' ? $this->legacyMaintenanceValues($user, (string) $request->query('draft', ''), $fields) : null,
            // «طلب ملحق»: the maintenance request this one is opened from ({publicId, referenceNo, title}); null otherwise.
            'parent' => $definition->code === MaintenanceAddenda::DEFINITION && Str::isUuid((string) $request->query('parent', ''))
                ? app(MaintenanceAddenda::class)->formParent((string) $request->query('parent'), $user) : null,
            'pickOptions' => $this->firstStepPickOptions($user, (int) $version->id),
            'hasDepartment' => (bool) $user->department_id,
            // Null on a confidential workflow (complaints), which takes no attachments.
            'attachmentPolicy' => $this->attachments->offeredOn((int) $version->id) ? $this->attachments->limits() : null,
        ]);
    }

    /**
     * The old maintenance screen saved drafts that were never sent (aims_requests, status "draft"). Its address
     * now opens «طلب صيانة» with the draft's text filled in, for its author only.
     */
    public function legacyMaintenanceDraft(Request $request, string $publicId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403);
        abort_unless($this->legacyMaintenanceDraftRow($user, $publicId), 404, 'مسودة الصيانة غير موجودة.');

        return redirect()->route('requests.create', ['code' => 'MAINTENANCE', 'draft' => $publicId]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields  the form's fields (CustomFieldService::forForm)
     * @return array{title: string, description: string, fields: array<string, string>}|null
     */
    private function legacyMaintenanceValues(User $user, string $publicId, array $fields): ?array
    {
        $draft = $this->legacyMaintenanceDraftRow($user, $publicId);
        if (! $draft) {
            return null;
        }
        $description = trim((string) $draft->description);
        if (trim((string) $draft->fault_type) !== '') {
            $description = trim($description."\n".'نوع العطل: '.trim((string) $draft->fault_type));
        }
        $hasLocation = in_array('location', array_column($fields, 'code'), true);

        return [
            'title' => (string) $draft->title,
            'description' => $description,
            'fields' => $hasLocation && trim((string) $draft->location) !== '' ? ['location' => (string) $draft->location] : [],
        ];
    }

    private function legacyMaintenanceDraftRow(User $user, string $publicId): ?object
    {
        if (! Str::isUuid($publicId)) {
            return null;
        }

        return DB::table('aims_requests as ar')
            ->leftJoin('maintenance_request_details as md', 'md.aims_request_id', '=', 'ar.id')
            ->where('ar.public_id', $publicId)->where('ar.request_type', 'maintenance')
            ->where('ar.requested_by', $user->id)->where('ar.status', 'draft')
            ->first(['ar.title', 'ar.description', 'md.location', 'md.fault_type']);
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
            'attachments' => ['nullable', 'array'],
            'parent' => ['nullable', 'uuid'],
            'addendum_required' => ['nullable', 'boolean'],
        ]);
        abort_if(! empty($data['parent']) && $code !== MaintenanceAddenda::DEFINITION, 422, 'الطلب الملحق يكون «طلب صيانة».');

        // Optional photos and documents go with the submission, to its event. A refused request stores nothing.
        $files = array_values(array_filter(Arr::wrap($request->file('attachments', []))));
        $this->attachments->validate($files, ! ($this->catalog->startableDefinition($code)?->is_confidential ?? false));

        $written = [];
        try {
            $instance = DB::transaction(function () use ($request, $user, $code, $data, $files, &$written): object {
                $instance = $this->engine->start(
                    $user,
                    $code,
                    ['title' => $data['title'], 'description' => $data['description'] ?? null],
                    $data['fields'] ?? [],
                    isset($data['picked_user_id']) ? (int) $data['picked_user_id'] : null,
                );
                if (! empty($data['parent'])) {
                    // An addendum is linked to its original in the same transaction, or not created at all.
                    app(MaintenanceAddenda::class)->attach($instance, (string) $data['parent'], $user, (bool) ($data['addendum_required'] ?? true));
                }
                if ($files !== []) {
                    $this->attachments->store($instance, $this->attachments->eventOf((int) $instance->id, (int) $user->id), $user, $files, $written, $request->ip());
                }

                return $instance;
            });
        } catch (\Throwable $exception) {
            $this->attachments->cleanup($written);

            throw $exception;
        }

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
