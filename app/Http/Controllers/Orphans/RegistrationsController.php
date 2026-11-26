<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orphans\FamilyRegistrationRequest;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registration\FamilyRegistrations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Staff-entered family registration requests: drafts, submission to «الطلبات والمراجعات», initial attachments. */
final class RegistrationsController extends Controller
{
    public function __construct(private readonly FamilyRegistrations $registrations, private readonly OrphanAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->access->assertEdit($user);
        $rows = DB::table('portal_applications as a')->leftJoin('users as u', 'u.id', '=', 'a.created_by_user')->where('a.source', 'staff')
            ->whereIn('a.status', FamilyRegistrations::EDITABLE)->when(! $this->access->canAssign($user), fn ($q) => $q->where('a.created_by_user', $user->id))
            ->orderByDesc('a.last_saved_at')->limit(100)->get(['a.public_id', 'a.reference_no', 'a.full_name', 'a.status', 'a.last_saved_at', 'a.completion_request', 'u.name as creator'])
            ->map(fn ($r) => [...(array) $r, 'status_label' => PortalApplications::STATUS[$r->status] ?? $r->status]);

        return Inertia::render('Orphans/Registrations/Index', ['rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        $application = $this->registrations->createDraft(null, $request->user());

        return redirect()->route('orphans.registrations.edit', $application->public_id)->with('success', 'أُنشئت مسودة الطلب '.$application->reference_no.'. احفظها متى شئت وأكملها لاحقًا.');
    }

    public function edit(Request $request, string $publicId): Response
    {
        $application = $this->find($publicId);
        $this->registrations->assertOwner($application, null, $request->user());

        return Inertia::render('Orphans/Registrations/Edit', $this->registrations->page($application, $this->access->allows($request->user(), 'health'), [
            'save' => route('orphans.registrations.update', $publicId), 'submit' => route('orphans.registrations.submit', $publicId),
            'documents' => route('orphans.registrations.documents', $publicId), 'discard' => route('orphans.registrations.discard', $publicId), 'back' => route('orphans.registrations.index'),
        ]));
    }

    public function update(FamilyRegistrationRequest $request, string $publicId): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $saved = $this->registrations->save($this->find($publicId), $request->validated(), false, null, $request->user(), $request->integer('version') ?: null, $request->integer('last_step'));

        return $request->expectsJson() ? response()->json(FamilyRegistrations::savedState($saved)) : back()->with('success', 'حُفظت المسودة.');
    }

    public function submit(FamilyRegistrationRequest $request, string $publicId): RedirectResponse
    {
        $current = $this->find($publicId);
        if ($current->submitted_at && ! in_array($current->status, FamilyRegistrations::EDITABLE, true)) {
            return redirect()->route('orphans.reviews.show', $current->public_id)->with('success', 'الطلب '.$current->reference_no.' مُرسل مسبقًا.');
        }
        $application = $this->registrations->save($current, $request->validated(), true, null, $request->user(), $request->integer('version') ?: null);

        return redirect()->route('orphans.reviews.show', $application->public_id)->with('success', 'أُرسل الطلب '.$application->reference_no.' إلى «الطلبات والمراجعات». الإرسال ليس اعتمادًا؛ يُبت في كل طفل على حدة.');
    }

    public function upload(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(FamilyRegistrations::uploadRules(), [], FamilyRegistrations::uploadAttributes());
        $this->registrations->attach($this->find($publicId), $request->file('file'), $data['document_type_value_id'] ?? null, $data['target'], null, $request->user());

        return back()->with('success', 'أُرفق المستند.');
    }

    public function removeDocument(Request $request, string $publicId, string $document): RedirectResponse
    {
        $this->registrations->detach($this->find($publicId), $document, null, $request->user());

        return back()->with('success', 'حُذف المرفق.');
    }

    public function discard(Request $request, string $publicId): RedirectResponse
    {
        $this->registrations->discard($this->find($publicId), null, $request->user());

        return redirect()->route('orphans.registrations.index')->with('success', 'حُذفت المسودة.');
    }

    private function find(string $publicId): object
    {
        return DB::table('portal_applications')->where('public_id', $publicId)->firstOrFail();
    }
}
