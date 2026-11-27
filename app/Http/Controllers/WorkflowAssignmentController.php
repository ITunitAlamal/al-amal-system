<?php

namespace App\Http\Controllers;

use App\Services\Workflow\StepAssignments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «إسناد إلى موظف»، «إعادة الإسناد» و«سحب الإسناد»: the head who supervises a request's current step hands it
 * to an employee of the department or takes it back. The rules are in StepAssignments.
 */
class WorkflowAssignmentController extends Controller
{
    public function __construct(private readonly StepAssignments $assignments) {}

    public function assign(Request $request, string $publicId): RedirectResponse
    {
        $instance = $this->instance($request, $publicId);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer'],
            'to_user_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['to_user_id.required' => 'اختر الموظف الذي تُسند إليه المهمة.']);

        $this->assignments->assign($instance, $request->user(), (int) $data['to_user_id'], $data['note'] ?? null, (int) $data['expected_revision'], $request->ip());

        return back()->with('success', 'أُسندت المهمة وأُشعر الموظف بها.');
    }

    public function withdraw(Request $request, string $publicId): RedirectResponse
    {
        $instance = $this->instance($request, $publicId);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assignments->withdraw($instance, $request->user(), $data['note'] ?? null, (int) $data['expected_revision'], $request->ip());

        return back()->with('success', 'سُحب الإسناد وعادت المهمة إليك.');
    }

    private function instance(Request $request, string $publicId): object
    {
        abort_unless($request->user()?->is_active, 403);
        $instance = DB::table('workflow_instances')->where('public_id', $publicId)->first();
        abort_unless($instance, 404);

        return $instance;
    }
}
