<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\CaseStudy\CaseStudies;
use App\Services\Orphans\CaseStudy\CaseStudySchema;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** «دراسة الحالة» of a household: a follow-up stage after registration, from the household in the orphan profile. */
class CaseStudyController extends Controller
{
    public function __construct(private readonly CaseStudies $studies, private readonly OrphanAccess $access) {}

    public function show(Request $request, string $household, ?string $study = null): Response
    {
        $user = $request->user();
        $this->access->assertView($user);
        $home = $this->studies->household($household);
        $row = $study ? $this->studies->find($study) : $this->studies->current($home->id);
        abort_if($row && (int) $row->household_id !== (int) $home->id, 404);
        $mayHealth = $this->access->allows($user, 'health');
        if ($row && $mayHealth && $row->sensitive_answers) {
            $this->access->logRead($user, 'household_case_study', (int) $row->id, 'viewed_sensitive');
        }
        $version = (int) ($row->schema_version ?? CaseStudySchema::CURRENT);
        $answers = $row ? $this->studies->answers($row, $mayHealth) : [];
        $schema = array_filter(CaseStudySchema::version($version), fn (array $s): bool => $mayHealth || ! $s['sensitive']);
        $names = fn (?int $id): ?string => $id ? DB::table('users')->where('id', $id)->value('name') : null;

        return Inertia::render('Orphans/CaseStudy/Edit', [
            'household' => ['public_id' => $home->public_id, 'reference_no' => $home->reference_no, 'name' => $home->display_name],
            'study' => $row ? ['public_id' => $row->public_id, 'status' => $row->status, 'status_label' => CaseStudies::STATUS[$row->status], 'version' => $version,
                'lock_version' => (int) $row->lock_version, 'assessed_on' => $row->assessed_on, 'assessor' => $names($row->assessor_id), 'submitted_at' => $row->submitted_at,
                'reviewer' => $names($row->reviewed_by), 'reviewed_at' => $row->reviewed_at, 'review_note' => $row->review_note, 'mine' => (int) $row->assessor_id === (int) $user->id,
                'assigned_to' => isset($row->assigned_to) ? (int) $row->assigned_to : null, 'assignee' => $names(isset($row->assigned_to) ? (int) $row->assigned_to : null),
                'recommendation' => CaseStudies::RECOMMENDATIONS[$row->review_recommendation ?? ''] ?? null] : null,
            'history' => DB::table('household_case_studies')->where('household_id', $home->id)->orderByDesc('id')->get(['public_id', 'status', 'assessed_on', 'reviewed_at'])
                ->map(fn ($h): array => [...(array) $h, 'status_label' => CaseStudies::STATUS[$h->status]])->all(),
            'schema' => $schema, 'answers' => $answers, 'options' => $this->studies->options($version, $answers), 'persons' => $this->studies->persons($home->id),
            'canHealth' => $mayHealth, 'canEdit' => $this->access->canEdit($user) && (! isset($row->assigned_to) || (int) $row->assigned_to === (int) $user->id || $this->access->isHead($user)),
            'canReview' => $this->access->isHead($user), 'recommendations' => CaseStudies::RECOMMENDATIONS,
            'staff' => $this->access->isHead($user) ? $this->access->staff() : [],
        ]);
    }

    public function store(Request $request, string $household): RedirectResponse
    {
        $home = $this->studies->household($household);
        $this->studies->open($home, $request->user());

        return redirect("/orphans/households/{$home->public_id}/case-study");
    }

    public function update(Request $request, string $study): RedirectResponse|JsonResponse
    {
        $row = $this->studies->find($study);
        $data = $request->validate(['answers' => ['nullable', 'array'], 'assessed_on' => ['nullable', 'date'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $saved = $this->studies->save($row, $data, (int) $data['lock_version'], $request->user());
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['lock_version' => (int) $saved->lock_version]);
        }

        return back()->with('success', 'حُفظت الدراسة كمسودة.');
    }

    public function submit(Request $request, string $study): RedirectResponse
    {
        $row = $this->studies->find($study);
        $data = $request->validate(['answers' => ['nullable', 'array'], 'assessed_on' => ['nullable', 'date'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->studies->save($row, $data, (int) $data['lock_version'], $request->user(), true);

        return back()->with('success', 'أُرسلت الدراسة لمراجعة رئيس القسم.');
    }

    /** The head assigns the study to a researcher (ORP-19; req-04 «تعيين الباحث»). */
    public function assign(Request $request, string $study): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        $this->studies->assign($this->studies->find($study), isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, $request->user());

        return back()->with('success', isset($data['assigned_to']) ? 'عُيّن الباحث للدراسة.' : 'أُلغي تعيين الباحث.');
    }

    public function decide(Request $request, string $study, string $decision): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'recommendation' => ['nullable', 'string', 'in:'.implode(',', array_keys(CaseStudies::RECOMMENDATIONS))]]);
        $this->studies->decide($this->studies->find($study), $decision, $data['note'] ?? null, $request->user(), $data['recommendation'] ?? null);

        return back()->with('success', $decision === 'review' ? 'سُجلت مراجعة الدراسة.' : 'أُعيدت الدراسة للاستكمال.');
    }
}
