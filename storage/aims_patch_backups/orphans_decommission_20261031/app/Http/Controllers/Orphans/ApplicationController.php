<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanChild;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanApplications;
use App\Services\Orphans\OrphanChanges;
use App\Services\Orphans\OrphanDocuments;
use App\Services\Orphans\OrphanForms;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** File activation applications (per-child decisions) and change requests, each on its workflow with the global stepper. */
final class ApplicationController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function show(Request $request, int $application, OrphanApplications $applications, OrphanDocuments $documents, WorkflowEngine $engine,
        WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $row = DB::table('orphan_applications as a')->join('orphan_households as h', 'h.id', '=', 'a.household_id')->where('a.id', $application)
            ->first(['a.*', 'h.public_id as household_public_id', 'h.reference_no as household_reference']);
        abort_unless($row, 404);
        $this->access->logRead($user, 'application', $application);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.id', $row->workflow_instance_id)->first(['i.*', 's.code as step_code']) : null;
        $open = $instance && in_array($instance->status, ['submitted', 'in_progress', 'returned'], true);
        $step = $open ? $instance->step_code : null;
        $children = DB::table('orphan_application_children')->where('orphan_application_id', $application)->orderBy('id')->get();
        $files = OrphanChild::whereIn('id', $children->pluck('orphan_child_id'))->get()->keyBy('id');
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $isCurrent = $instance && $open && $engine->isCurrentAssignee((int) $instance->id, $user);

        return Inertia::render('Orphans/Application', [
            'application' => ['id' => $row->id, 'reference_no' => $row->reference_no, 'status' => $row->status, 'status_label' => OrphanApplications::STATUS_LABELS[$row->status] ?? $row->status,
                'source' => $row->source, 'household' => ['public_id' => $row->household_public_id, 'reference_no' => $row->household_reference],
                'decision_reason' => $row->decision_reason, 'submitted' => (bool) $row->workflow_instance_id, 'step' => $step],
            'children' => $children->map(fn ($c) => ['id' => $c->id, 'name' => $files[$c->orphan_child_id]?->full_name, 'public_id' => $files[$c->orphan_child_id]?->public_id,
                'age' => $files[$c->orphan_child_id]?->ageOn(), 'class' => $values[$files[$c->orphan_child_id]?->orphan_class_value_id] ?? null,
                'file_no' => $files[$c->orphan_child_id]?->file_no,
                'verification' => $c->verification, 'verification_label' => OrphanApplications::VERIFICATION_LABELS[$c->verification] ?? $c->verification, 'verification_note' => $c->verification_note,
                'recommendation' => $c->recommendation, 'recommendation_note' => $c->recommendation_note, 'decision' => $c->decision, 'decision_note' => $c->decision_note,
                'labels' => OrphanApplications::DECISION_LABELS]),
            'problems' => ! $row->workflow_instance_id ? $applications->submissionProblems($row) : [],
            'checklist' => $documents->checklist((int) $row->household_id),
            'manualReview' => (bool) DB::table('orphans_module_settings')->where('id', 1)->value('manual_evidence_review_enabled'),
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
            'can' => [
                'submit' => ! $row->workflow_instance_id && $this->access->allows($user, 'records') && in_array($row->status, ['under_review', 'submitted'], true),
                'verify' => $this->access->allows($user, 'verify') && ($step === null || $step === 'staff_review') && ! in_array($row->status, ['approved', 'partially_approved', 'rejected'], true),
                'recommend' => $step === 'head_recommendation' && $this->access->isHead($user),
                'decide' => $step === 'ed_decision' && $isCurrent,
            ],
        ]);
    }

    public function submit(Request $request, int $application, OrphanApplications $applications, WorkflowEngine $engine): RedirectResponse
    {
        $applications->submit($request->user(), $application, $engine);

        return back()->with('success', 'رُفع الطلب إلى رئيس قسم الكفالات للتوصية.');
    }

    public function childAction(Request $request, int $row, string $action, OrphanApplications $applications): RedirectResponse
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:2000']]);
        match ($action) {
            'verify' => $applications->verifyChild($request->user(), $row, $data['value'], $data['note'] ?? null),
            'recommend' => $applications->recommend($request->user(), $row, $data['value'], $data['note'] ?? null),
            'decide' => $applications->decideChild($request->user(), $row, $data['value'], $data['note'] ?? null),
            default => abort(404),
        };

        return back()->with('success', 'سُجل الإجراء على الطفل.');
    }

    public function storeChange(Request $request, OrphanChanges $changes, WorkflowEngine $engine): RedirectResponse
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:20'], 'subject_type' => ['required', 'in:child,household,guardian,father,mother'],
            'subject_id' => ['required', 'integer'], 'changes' => ['required', 'array'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:10'], 'evidence.*' => ['integer']]);
        $id = $changes->create($request->user(), $data['category'], $data['subject_type'], (int) $data['subject_id'], $data['changes'], $data['reason'], $data['evidence'] ?? []);
        if ($this->access->allows($request->user(), 'verify')) {
            $changes->start($request->user(), $id, $engine);
        }

        return redirect()->route('orphans.changes.show', $id)->with('success', 'سُجل طلب التعديل؛ لا يُطبّق على الملف قبل قرار المدير التنفيذي.');
    }

    public function showChange(Request $request, int $change, OrphanChanges $changes, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $row = DB::table('orphan_change_requests as c')->join('orphan_households as h', 'h.id', '=', 'c.household_id')->where('c.id', $change)
            ->first(['c.*', 'h.public_id as household_public_id', 'h.reference_no as household_reference']);
        abort_unless($row, 404);
        $this->access->logRead($user, 'change', $change);
        $sensitive = $this->access->allows($user, 'sensitive');
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $diff = collect($changes->changes($row))->map(fn ($c, $field) => ['field' => OrphanForms::LABELS[$field] ?? $field,
            'before' => $this->display($c['before'], $field, $sensitive), 'after' => $this->display($c['after'], $field, $sensitive)])->values();

        return Inertia::render('Orphans/Change', [
            'change' => ['id' => $row->id, 'reference_no' => $row->reference_no, 'category' => OrphanChanges::CATEGORY_LABELS[$row->category] ?? $row->category,
                'status' => $row->status, 'status_label' => OrphanChanges::STATUS_LABELS[$row->status] ?? $row->status, 'reason' => $row->reason,
                'decision_note' => $row->decision_note, 'from_portal' => (bool) $row->requested_by_guardian, 'applied_at' => $row->applied_at,
                'household' => ['public_id' => $row->household_public_id, 'reference_no' => $row->household_reference]],
            'diff' => $diff,
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
            'canStart' => ! $row->workflow_instance_id && $row->status === 'submitted' && $this->access->allows($user, 'verify'),
        ]);
    }

    public function startChange(Request $request, int $change, OrphanChanges $changes, WorkflowEngine $engine): RedirectResponse
    {
        $changes->start($request->user(), $change, $engine);

        return back()->with('success', 'بدأت مراجعة طلب التعديل.');
    }

    private function display(mixed $value, string $field, bool $sensitive): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($field, ['identity_no', 'phone', 'alt_phone'], true) && ! $sensitive) {
            return '•••'.mb_substr((string) $value, -3);
        }
        if (in_array($field, ['health'], true) && ! $sensitive) {
            return 'بيانات صحية محجوبة';
        }
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }
        if (is_array($value)) {
            return collect($value)->filter(fn ($v) => $v !== null && $v !== '' && ! is_array($v))->map(fn ($v, $k) => (OrphanForms::LABELS[$k] ?? $k).': '
                .(str_ends_with((string) $k, '_value_id') ? (DB::table('master_data_values')->where('id', (int) $v)->value('name_ar') ?? $v) : (is_bool($v) ? ($v ? 'نعم' : 'لا') : $v)))->implode('، ');
        }
        if (str_ends_with($field, '_value_id') || $field === 'primary_guardian_id') {
            return (string) ($field === 'primary_guardian_id' ? DB::table('guardians')->where('id', (int) $value)->value('name') : DB::table('master_data_values')->where('id', (int) $value)->value('name_ar')) ?: (string) $value;
        }
        if ($field === 'status') {
            return OrphanChild::STATUS_LABELS[$value] ?? (string) $value;
        }

        return (string) $value;
    }
}
