<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GuardianApplication;
use App\Models\GuardianApplicationChild;
use App\Services\Orphans\ChildVerificationEvaluator;
use App\Services\Orphans\FamilyRelationshipResolver;
use App\Services\Orphans\GuardianRegistrationService;
use App\Services\Orphans\GuardianRegistryClient;
use App\Services\Orphans\OrphanAccess;
use App\Services\Progress\GuardianApplicationProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GuardianReviewController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function index(Request $request, GuardianRegistrationService $service): Response
    {
        $this->access->assert($request->user(), 'view');
        $status = $request->validate(['status' => ['nullable', 'in:awaiting_api,pending_review,verification_failed,needs_changes,approved,rejected']])['status'] ?? null;
        $applications = GuardianApplication::with('guardian:id,name')->when($status, fn ($q) => $q->where('status', $status))->latest('id')->paginate(25)->withQueryString();
        $service->event(null, 'review_queue_viewed', $request->user());

        return Inertia::render('Admin/Orphans/Guardians', [
            'username' => $request->user()->name, 'applications' => $applications->through(fn ($app) => [
                'public_id' => $app->public_id, 'guardian_name' => $app->guardian->name,
                'status' => $app->status, 'verification_status' => $app->verification_status,
                'submitted_at' => $app->created_at?->toIso8601String(),
            ]), 'selected' => null, 'statusFilter' => $status,
        ]);
    }

    public function show(Request $request, string $publicId, GuardianRegistryClient $registry, GuardianRegistrationService $service): Response
    {
        $this->access->assert($request->user(), 'view');
        $application = GuardianApplication::with('guardian')->where('public_id', $publicId)->firstOrFail();
        $service->event($application, 'application_viewed', $request->user());
        $children = GuardianApplicationChild::where('guardian_application_id', $application->id)
            ->where('verification_revision', $application->verification_revision)
            ->orderBy('id')->get();

        return Inertia::render('Admin/Orphans/Guardians', [
            'username' => $request->user()->name, 'applications' => null, 'statusFilter' => null,
            'selected' => [
                'public_id' => $application->public_id, 'guardian_name' => $application->guardian->name,
                'guardian_identity' => $application->guardian->national_id, 'phone' => $application->guardian->phone,
                'deceased_identity' => $application->deceased_national_id, 'relationship' => $application->relationship,
                'status' => $application->status, 'verification_status' => $application->verification_status,
                'verified_at' => $application->verified_at?->toIso8601String(), 'review_note' => $application->review_note,
                'children' => $children->map(fn (GuardianApplicationChild $child) => [
                    'id' => $child->id,
                    'full_name' => $child->full_name,
                    'date_of_birth' => $child->date_of_birth->format('Y-m-d'),
                    'kinship' => FamilyRelationshipResolver::LABELS[$child->kinship] ?? $child->kinship,
                    'authority' => ChildVerificationEvaluator::authorityKinds()[$child->authority_kind] ?? $child->authority_kind,
                    'authority_valid_until' => $child->authority_valid_until?->format('Y-m-d'),
                    'result' => $child->result,
                    'result_label' => ChildVerificationEvaluator::RESULT_LABELS[$child->result] ?? $child->result,
                    'reasons' => $child->reasons ?? [],
                    'linked' => $child->linked,
                ])->values(),
                'verifiedChildren' => $children->where('result', 'verified')->where('linked', false)->count(),
                'progress' => app(GuardianApplicationProgress::class)->view($application, true),
                'canApprove' => $this->access->allows($request->user(), 'approve') && $application->status === 'pending_review' && $application->verification_status === 'verified',
                'canReview' => $this->access->allows($request->user(), 'review') && ! in_array($application->status, ['approved', 'rejected', 'verifying'], true),
                'canReject' => $this->access->allows($request->user(), 'reject') && ! in_array($application->status, ['approved', 'rejected', 'verifying'], true),
                'canVerify' => $registry->available() && $this->access->allows($request->user(), 'verify') && ! in_array($application->status, ['approved', 'rejected', 'verifying'], true),
                'registryAvailable' => $registry->available(),
            ],
        ]);
    }

    public function verify(Request $request, string $publicId, GuardianRegistrationService $service): RedirectResponse
    {
        $this->access->assert($request->user(), 'verify');
        $application = GuardianApplication::where('public_id', $publicId)->firstOrFail();
        $service->verify($application, $request->user());

        return back()->with('success', $application->fresh()->status === 'awaiting_api' ? 'خدمة التحقق غير متاحة؛ لم يعتمد الطلب ولم يستورد أي طفل.' : 'تم تحديث نتيجة التحقق.');
    }

    public function review(Request $request, string $publicId, GuardianRegistrationService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject,needs_changes'], 'note' => ['nullable', 'required_if:decision,reject,needs_changes', 'string', 'min:5', 'max:2000']]);
        $this->access->assert($request->user(), match ($data['decision']) {
            'approve' => 'approve', 'reject' => 'reject', default => 'review',
        });
        $application = GuardianApplication::where('public_id', $publicId)->firstOrFail();
        $count = $service->review($application, $request->user(), $data['decision'], $data['note'] ?? null);

        return back()->with('success', $data['decision'] === 'approve'
            ? 'اعتمد الطلب ورُبط '.$count.' طفلًا متحققًا منه. الأطفال ذوو التعارض أو النقص يبقون بأسبابهم للمتابعة.'
            : 'تم توثيق القرار وإظهاره للوصي.');
    }
}
