<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanDelegationService;
use App\Services\Progress\ApprovalProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrphanDelegationController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->canManageDelegations($request->user()), 403);
        $department = $this->access->department();
        $requests = DB::table('orphan_access_requests as r')
            ->join('users as employee', 'employee.id', '=', 'r.user_id')
            ->join('users as head', 'head.id', '=', 'r.requested_by')
            ->leftJoin('users as admin', 'admin.id', '=', 'r.handled_by')
            ->when(! $request->user()->isSystemAdmin(), fn ($q) => $q->where('r.department_id', $department->id))
            ->select('r.*', 'employee.name as employee_name', 'head.name as head_name', 'admin.name as admin_name')
            ->orderByDesc('r.id')->paginate(20);
        $progress = app(ApprovalProgress::class);
        $requests->through(function ($record) use ($request, $progress): array {
            return [
                'steps' => $progress->steps($record->status, [
                    'requestLabel' => 'طلب رئيس القسم', 'requestedBy' => (int) $record->requested_by, 'requestedAt' => $record->created_at,
                    'decisionLabel' => 'تنفيذ مدير النظام', 'decidedBy' => $record->handled_by ? (int) $record->handled_by : null,
                    'decidedAt' => $record->handled_at, 'decisionOwner' => 'مدير النظام', 'note' => $record->decision_note,
                    'execution' => ['label' => 'سريان الصلاحيات', 'done' => $record->status === 'implemented', 'at' => $record->handled_at, 'owner' => 'تلقائي عند التنفيذ'],
                ]),
                'id' => $record->id, 'employee_name' => $record->employee_name, 'head_name' => $record->head_name,
                'admin_name' => $record->admin_name, 'operation' => $record->operation,
                'capabilities' => json_decode($record->capabilities, true, flags: JSON_THROW_ON_ERROR),
                'reason' => $record->reason, 'status' => $record->status, 'decision_note' => $record->decision_note,
                'created_at' => $record->created_at, 'handled_at' => $record->handled_at,
                'can_decide' => $record->status === 'pending' && $request->user()->isSystemAdmin() && (int) $record->requested_by !== (int) $request->user()->id,
                'decision_url' => route('admin.orphans.delegations.decide', $record->id),
            ];
        });
        $grants = $department ? DB::table('orphan_access_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.department_id', $department->id)->where('g.is_active', true)->where('u.is_active', true)->where('u.department_id', $department->id)
            ->select('u.id', 'u.name', 'g.capability')->orderBy('u.name')->get() : collect();

        return Inertia::render('Admin/Orphans/Delegations', [
            'username' => $request->user()->name, 'departmentName' => $department?->name,
            'departmentHeadName' => $department?->head?->name,
            'organizationUrl' => $request->user()->canManageOrganization() ? route('admin.departments.vue') : null,
            'canRequest' => $this->access->isHead($request->user()), 'isAdmin' => $request->user()->isSystemAdmin(),
            'employees' => $department && $this->access->isHead($request->user()) ? User::where('department_id', $department->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_number']) : [],
            'capabilities' => OrphanAccess::CAPABILITIES, 'requests' => $requests, 'grants' => $grants,
            'requestUrl' => route('admin.orphans.delegations.store'),
        ]);
    }

    public function store(Request $request, OrphanDelegationService $service): RedirectResponse
    {
        abort_unless($this->access->isHead($request->user()), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'], 'operation' => ['required', 'in:grant,revoke'],
            'capabilities' => ['required', 'array', 'min:1', 'max:5'],
            'capabilities.*' => ['required', 'string', 'distinct', Rule::in(array_keys(OrphanAccess::CAPABILITIES))],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $service->request($request->user(), $data);

        return back()->with('success', 'أرسل طلب التفويض إلى مدير النظام؛ لا تسري الصلاحيات قبل تنفيذه.');
    }

    public function decide(Request $request, int $accessRequest, OrphanDelegationService $service): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:implement,reject'],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:2000'],
        ]);
        $service->decide($request->user(), $accessRequest, $data['decision'], $data['note'] ?? null);

        return back()->with('success', $data['decision'] === 'implement' ? 'تم تنفيذ الصلاحيات المحددة في طلب رئيس القسم وتوثيق المنفذ.' : 'رفض طلب التفويض مع توثيق السبب.');
    }
}
