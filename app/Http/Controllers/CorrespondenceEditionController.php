<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\Correspondence\CorrespondenceEditions;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class CorrespondenceEditionController extends Controller
{
    public function store(Request $request, string $publicId, CorrespondenceEditions $editions): RedirectResponse
    {
        $edition = $editions->propose($request->user(), $publicId, $request->all(), $request->file('attachments', []));

        return to_route('correspondence.editions.show', $edition->public_id);
    }

    public function show(Request $request, string $edition, CorrespondenceEditions $editions, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility, AuditService $audit): Response
    {
        abort_unless(Schema::hasTable('correspondence_editions'), 404);
        $row = DB::table('correspondence_editions')->where('public_id', $edition)->first();
        abort_unless($row && $editions->canView($request->user(), $row), 404);
        $instance = DB::table('workflow_instances')->find($row->workflow_instance_id);
        $letter = DB::table('official_correspondences')->find($row->correspondence_id);
        abort_unless($instance && $letter, 404);
        $visibilityLevel = $visibility->accessLevel($request->user(), (int) $instance->id);
        abort_unless($visibilityLevel, 404);
        $audit->recordRow('correspondence.edition.view', 'correspondence_editions', $row->id, null, ['technical' => $request->user()->isSystemAdmin()], (int) $request->user()->id);

        return Inertia::render('Correspondence/Edition', [
            'edition' => collect((array) $row)->only(['public_id', 'version', 'status', 'subject', 'body', 'reason', 'origin_role', 'approved_at', 'board_related', 'substantive'])->all(),
            'differences' => json_decode($row->differences, true),
            'progress' => $progress->view($instance, $visibilityLevel),
            'actions' => $row->origin_role === 'board' ? [] : $engine->availableActions($instance, $request->user()),
            'revision' => $instance->revision,
            'backUrl' => route('correspondence.show', $letter->public_id, false),
            'attachments' => DB::table('workflow_event_attachments')->where('correspondence_action_id', $row->action_id)->get(['public_id', 'original_filename', 'document_version']),
        ]);
    }

    public function act(Request $request, string $edition, CorrespondenceEditions $editions, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless(Schema::hasTable('correspondence_editions'), 404);
        $data = $request->validate(['transition_id' => ['required', 'integer'], 'revision' => ['required', 'integer'], 'comment' => ['required', 'string', 'max:5000']]);
        $row = DB::table('correspondence_editions')->where('public_id', $edition)->first();
        abort_unless($row && $editions->canView($request->user(), $row), 404);
        $engine->act((int) $row->workflow_instance_id, $request->user(), (int) $data['transition_id'], $data['comment'], [], null, (int) $data['revision']);

        return to_route('correspondence.editions.show', $edition);
    }
}
