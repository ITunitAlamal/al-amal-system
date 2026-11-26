<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceRequestController extends Controller
{
    public function create(Request $request): Response
    {
        $this->ensureEligibleUser($request);

        return Inertia::render('Maintenance/Create', [
            'username' => $request->user()->name,
            'mode' => 'create',
            'draft' => null,
        ]);
    }

    public function store(
        Request $request,
        MaintenanceDraftService $service
    ): RedirectResponse {
        $this->ensureEligibleUser($request);

        $data = $this->validateDraft($request);

        $publicId = $service->create(
            $request->user(),
            $data
        );

        return redirect()
            ->route('maintenance.requests.edit', [
                'publicId' => $publicId,
            ])
            ->with('success', 'تم حفظ مسودة طلب الصيانة بنجاح.');
    }

    public function edit(
        Request $request,
        string $publicId,
        MaintenanceDraftService $service
    ): Response {
        $this->ensureEligibleUser($request);

        $draft = $service->getDraft(
            $request->user(),
            $publicId
        );

        return Inertia::render('Maintenance/Create', [
            'username' => $request->user()->name,
            'mode' => 'edit',
            'draft' => $draft,
        ]);
    }

    public function update(
        Request $request,
        string $publicId,
        MaintenanceDraftService $service
    ): RedirectResponse {
        $this->ensureEligibleUser($request);

        $data = $this->validateDraft($request);

        $service->update(
            $request->user(),
            $publicId,
            $data
        );

        return redirect()
            ->route('maintenance.requests.edit', [
                'publicId' => $publicId,
            ])
            ->with('success', 'تم تحديث مسودة طلب الصيانة بنجاح.');
    }

    private function validateDraft(Request $request): array
    {
        return $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'required',
                'string',
                'max:10000',
            ],
            'location' => [
                'required',
                'string',
                'max:255',
            ],
            'fault_type' => [
                'required',
                'string',
                'max:100',
            ],
            'priority' => [
                'required',
                Rule::in([
                    'low',
                    'normal',
                    'high',
                    'urgent',
                ]),
            ],
        ]);
    }

    private function ensureEligibleUser(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->is_active,
            403,
            'حساب المستخدم غير نشط أو غير مصرح له.'
        );
    }
}
