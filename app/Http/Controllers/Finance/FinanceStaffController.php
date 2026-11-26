<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\FinanceAccessGrants;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinancePayrollRuns;
use App\Services\Finance\FinancePermissionCatalog;
use App\Services\Finance\FinanceSod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «موظفو القسم وصلاحياتهم»: the head of Accounts (within the department) and the system administrator assign role
 * templates and permissions, with a preview of effective access, sensitive items and segregation-of-duties conflicts.
 * Sensitive grants and exceptions are decided by the executive director on the grant page.
 */
final class FinanceStaffController extends Controller
{
    public function __construct(private readonly FinanceAccessGrants $grants, private readonly FinanceGuard $guard) {}

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $this->grants->actorRole($user)) {
            if ($user) {
                $this->guard->audit('access', 'finance.staff.assign', (int) $user->id, 'denied', ['route' => $request->route()?->getName()]);
            }
            abort(403, 'إسناد اختصاصات الحسابات لرئيس القسم ومدير النظام فقط.');
        }

        return $user;
    }

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $staff = $this->grants->eligibleStaff($actor);
        $ids = $staff->pluck('id')->all();
        $assignments = DB::table('finance_staff_assignments')->whereIn('user_id', $ids)->where('is_active', true)->get()->groupBy('user_id');
        $pending = DB::table('finance_access_grants')->where('state', 'pending_approval')
            ->where(fn ($q) => $q->whereIn('user_id', $ids)->orWhere('requested_by', $actor->id))->orderByDesc('id')->get();

        return Inertia::render('Finance/Staff', [
            'role' => $this->grants->actorRole($actor),
            'staff' => $staff->map(function (object $row) use ($assignments): array {
                $user = User::find($row->id);
                $held = $this->grants->held($user);

                return ['id' => (int) $row->id, 'name' => $row->name, 'department' => $row->department,
                    'templates' => ($assignments[$row->id] ?? collect())->map(fn (object $a): array => ['code' => $a->specialization,
                        'label' => FinancePermissionCatalog::templateLabel($a->specialization), 'primary' => (bool) $a->is_primary, 'until' => $a->valid_until])->values(),
                    'conflicts' => count(FinancePermissionCatalog::conflictsIn($held))];
            })->values(),
            'templates' => collect(FinancePermissionCatalog::TEMPLATES)->map(fn (array $t, string $code): array => [
                'code' => $code, 'label' => $t[0], 'permissions' => array_map(FinancePermissionCatalog::label(...), $this->grants->templatePermissions($code)),
                'assignable' => $this->grants->actorRole($actor) === 'system_admin' || in_array($code, FinancePermissionCatalog::HEAD_TEMPLATES, true),
                'sensitive' => in_array($code, FinancePermissionCatalog::SENSITIVE_TEMPLATES, true) || array_intersect($this->grants->templatePermissions($code), FinancePermissionCatalog::SENSITIVE) !== [],
            ])->values(),
            'catalog' => collect(FinancePermissionCatalog::AREAS)->map(fn (array $area, string $code): array => ['code' => $code, 'name' => $area[0],
                'permissions' => collect($area[1])->map(fn (string $label, string $permission): array => ['code' => $permission, 'label' => $label,
                    'sensitive' => in_array($permission, FinancePermissionCatalog::SENSITIVE, true),
                    'assignable' => $this->grants->actorRole($actor) === 'system_admin' || ! in_array($permission, FinancePermissionCatalog::HEAD_EXCLUDED, true),
                    'inactive' => $this->guard->documentaryMode() && in_array($permission, FinanceGuard::ACCOUNTING_PERMISSIONS, true)])->values()])->values(),
            'pending' => $pending->map(fn (object $g): array => $this->grants->present($g))->values(),
            'sod' => FinanceSod::MATRIX,
            'exceptionMaxDays' => FinanceAccessGrants::EXCEPTION_MAX_DAYS,
            'uncovered' => $this->uncoveredSteps(),
        ]);
    }

    /**
     * Workflow steps nobody can take today. A step without a holder stops every request that reaches it, so it is
     * shown to whoever assigns the department's staff; the assignment itself stays an explicit decision.
     *
     * @return list<array{permission: string, label: string, assigned_by: string}>
     */
    private function uncoveredSteps(): array
    {
        $threshold = DB::table('finance_purchase_policy')->where('code', 'board_treasurer_purchase_threshold')->where('is_active', true)->value('threshold_ils');
        $steps = [
            'finance.procurement.manage' => ['إعداد طلبات الشراء وعروض الأسعار وأوامر الشراء', 'رئيس القسم أو مدير النظام'],
            'finance.procurement.review' => ['مراجعة رئيس الحسابات لطلب الشراء', 'مدير النظام بقرار المدير التنفيذي'],
            'finance.procurement.director_approve' => ['اعتماد المدير التنفيذي لطلب الشراء', 'مدير النظام (تكليف «المعتمد المالي»)'],
            'finance.procurement.board_approve' => ['موافقة أمين صندوق المجلس'.($threshold !== null ? ' لما يزيد على '.rtrim(rtrim((string) $threshold, '0'), '.').' شيكل' : ''), 'مدير النظام بقرار مجلس الإدارة'],
            'finance.purchase.receive' => ['استلام أصناف أمر الشراء', 'رئيس القسم أو مدير النظام'],
            'finance.purchase.invoice' => ['تسجيل فاتورة المورد ورقم سند الصرف', 'رئيس القسم أو مدير النظام'],
            'finance.stock.count.approve' => ['اعتماد محضر الجرد', 'رئيس القسم أو مدير النظام'],
            'finance.payroll.record' => ['إدخال مسير الرواتب المعتمد', 'مدير النظام بقرار المدير التنفيذي'],
            'finance.payroll.publish' => ['مراجعة المسير ونشر القسائم (شخص غير المُدخل)', 'مدير النظام بقرار المدير التنفيذي'],
        ];
        $holders = app(FinancePayrollRuns::class);

        return collect($steps)->filter(fn (array $step, string $permission): bool => $holders->holders($permission)->isEmpty())
            ->map(fn (array $step, string $permission): array => ['permission' => $permission, 'label' => $step[0], 'assigned_by' => $step[1]])
            ->values()->all();
    }

    /** One person's finance access in detail. */
    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $this->actor($request);
        if ($error = $this->grants->targetError($actor, $user)) {
            abort(403, $error);
        }
        $held = $this->grants->held($user);
        $guard = new FinanceGuard;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'assignments' => DB::table('finance_staff_assignments')->where('user_id', $user->id)->orderByDesc('is_active')->get(['specialization', 'is_primary', 'is_active', 'valid_from', 'valid_until'])
                ->map(fn (object $a): array => ['code' => $a->specialization, 'label' => FinancePermissionCatalog::templateLabel($a->specialization), 'primary' => (bool) $a->is_primary,
                    'active' => (bool) $a->is_active, 'from' => $a->valid_from, 'until' => $a->valid_until]),
            'overrides' => DB::table('finance_user_permissions')->where('user_id', $user->id)->get(['permission_code', 'effect', 'reason', 'valid_until'])
                ->map(fn (object $o): array => ['code' => $o->permission_code, 'label' => FinancePermissionCatalog::label($o->permission_code), 'effect' => $o->effect, 'reason' => $o->reason, 'until' => $o->valid_until]),
            'effective' => collect(FinancePermissionCatalog::codes())->filter(fn (string $code): bool => $guard->allows($user, $code))
                ->map(fn (string $code): array => ['code' => $code, 'label' => FinancePermissionCatalog::label($code)])->values(),
            'held' => $held,
            'conflicts' => array_map(fn (array $pair): array => array_map(FinancePermissionCatalog::label(...), $pair), FinancePermissionCatalog::conflictsIn($held)),
            'history' => DB::table('finance_access_grants')->where('user_id', $user->id)->orderByDesc('id')->limit(30)->get()->map(fn (object $g): array => $this->grants->present($g)),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->validated($request, false);
        $preview = $this->grants->preview($actor, User::findOrFail($data['user_id']), $data['kind'], $data['code']);

        return response()->json([...$preview,
            'adds' => array_map(fn (string $code): array => ['code' => $code, 'label' => FinancePermissionCatalog::label($code), 'sensitive' => in_array($code, FinancePermissionCatalog::SENSITIVE, true)], $preview['adds']),
            'conflicts' => array_map(fn (array $pair): array => array_map(FinancePermissionCatalog::label(...), $pair), $preview['conflicts'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->validated($request, true);
        $grant = $this->grants->request($actor, User::findOrFail($data['user_id']), $data);

        return response()->json(['grant' => $this->grants->present($grant),
            'message' => $grant->state === 'applied' ? 'نُفّذ الإسناد وأُشعر الموظف.' : 'أُرسل الطلب إلى المدير التنفيذي؛ لا يسري قبل قراره.'], 201);
    }

    public function end(Request $request, User $user): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['specialization' => ['required', Rule::in(array_keys(FinancePermissionCatalog::TEMPLATES))], 'reason' => 'required|string|min:5|max:1000']);
        $this->grants->endSpecialization($actor, $user, $data['specialization'], $data['reason']);

        return response()->json(['message' => 'أُنهي الاختصاص.']);
    }

    public function withdraw(Request $request, User $user): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['code' => ['required', Rule::in(FinancePermissionCatalog::codes())], 'reason' => 'required|string|min:5|max:1000']);
        $this->grants->withdrawPermission($actor, $user, $data['code'], $data['reason']);

        return response()->json(['message' => 'سُحبت الصلاحية فورًا.']);
    }

    /** The grant page: the executive director decides here; the requester, the person and the assigners follow it. */
    public function grant(Request $request, string $publicId): Response
    {
        $user = $request->user();
        $grant = DB::table('finance_access_grants')->where('public_id', $publicId)->first();
        abort_unless($grant, 404);
        $canDecide = $this->grants->canDecide($user, $grant);
        abort_unless($canDecide || $this->grants->actorRole($user) || in_array((int) $user->id, [(int) $grant->user_id, (int) $grant->requested_by], true), 403);

        return Inertia::render('Finance/AccessGrant', [
            'grant' => $this->grants->present($grant),
            'canDecide' => $canDecide && $grant->state === 'pending_approval',
        ]);
    }

    public function decide(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:1000']);
        $grant = $this->grants->decide($request->user(), $publicId, $data['decision'] === 'approve', $data['note'] ?? null);

        return response()->json(['grant' => $this->grants->present($grant), 'message' => $grant->state === 'applied' ? 'اعتُمد الطلب ونُفّذ.' : 'رُفض الطلب.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $full): array
    {
        $kind = $request->input('kind');

        return $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'kind' => 'required|in:specialization,permission',
            'code' => ['required', 'string', $kind === 'specialization' ? Rule::in(array_keys(FinancePermissionCatalog::TEMPLATES)) : Rule::in(FinancePermissionCatalog::codes())],
            'is_primary' => 'nullable|boolean',
            'valid_from' => 'nullable|date_format:Y-m-d',
            'valid_until' => 'nullable|date_format:Y-m-d',
            'exception' => 'nullable|boolean',
            'reason' => $full ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000',
        ]);
    }
}
