<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\FinanceAccessGrants;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinancePermissionCatalog;
use App\Services\Organization\RoleSeparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** System admin manages finance staff assignments; this never gives the admin business approval privileges. */
final class FinanceSetupController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard) {}

    private function authorizeAdmin(Request $r): void
    {
        abort_unless($r->user()?->isSystemAdmin(), 403, 'الإعداد متاح لمدير النظام فقط');
    }

    public function index(Request $r)
    {
        $this->authorizeAdmin($r);

        return Inertia::render('Finance/Setup', [
            'users' => DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'assignments' => DB::table('finance_staff_assignments as a')->join('users as u', 'a.user_id', '=', 'u.id')->get(['a.id', 'a.user_id', 'u.name', 'a.specialization', 'a.is_primary', 'a.is_active']),
            'board' => DB::table('finance_board_approvers as b')->join('users as u', 'b.user_id', '=', 'u.id')->get(['b.user_id', 'u.name', 'b.is_active']),
            'threshold' => DB::table('finance_purchase_policy')->where('code', 'board_treasurer_purchase_threshold')->value('threshold_ils'),
            'minQuotes' => DB::table('finance_purchase_rules')->where('code', 'default_quotes')->value('min_quotes'),
            'catalog' => collect(FinancePermissionCatalog::AREAS)->map(fn (array $area, string $code): array => ['code' => $code, 'name' => $area[0],
                'permissions' => collect($area[1])->map(fn (string $label, string $permission): array => ['code' => $permission, 'label' => $label])->values()])->values(),
            'documentary' => $this->guard->documentaryMode(),
            'accountingPermissions' => FinanceGuard::ACCOUNTING_PERMISSIONS,
        ]);
    }

    /**
     * One user's finance access: roles, assignments, per-user decisions and the resulting effective permissions.
     *
     * @return array<string, mixed>
     */
    public function userAccess(Request $r, User $user): array
    {
        $this->authorizeAdmin($r);
        $rolePermissions = DB::table('department_user_roles as a')->join('permission_role as pr', 'pr.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')->where('a.user_id', $user->id)->where('p.code', 'like', 'finance.%')
            ->distinct()->pluck('p.code')->all();
        $guard = new FinanceGuard;
        $effective = array_values(array_filter(FinancePermissionCatalog::codes(), fn (string $code): bool => $guard->allows($user, $code)));

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'is_admin' => $user->isSystemAdmin()],
            'roles' => DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $user->id)
                ->orderBy('r.name')->pluck('r.name')->unique()->values(),
            'assignments' => DB::table('finance_staff_assignments')->where('user_id', $user->id)->orderByDesc('is_active')->get(['id', 'specialization', 'is_primary', 'is_active']),
            'rolePermissions' => $rolePermissions,
            'overrides' => DB::table('finance_user_permissions')->where('user_id', $user->id)->get(['permission_code', 'effect', 'reason', 'updated_at'])->keyBy('permission_code'),
            'effective' => $effective,
            'conflicts' => array_map(fn (array $pair): array => array_map(FinancePermissionCatalog::label(...), $pair), FinancePermissionCatalog::conflictsIn($effective)),
        ];
    }

    /** Grants, revokes ('deny') or returns to the role's default ('inherit') one finance permission of one user. */
    public function permission(Request $r): JsonResponse
    {
        $this->authorizeAdmin($r);
        $d = $r->validate([
            'user_id' => 'required|integer|exists:users,id',
            'permission_code' => ['required', 'string', Rule::in(FinancePermissionCatalog::codes())],
            'effect' => 'required|in:grant,deny,inherit',
            'reason' => 'required_unless:effect,inherit|nullable|string|min:5|max:1000',
        ]);
        $actor = (int) $r->user()->id;
        if ((int) $d['user_id'] === $actor) {
            throw ValidationException::withMessages(['user_id' => 'لا يعدّل مدير النظام صلاحياته المالية بنفسه.']);
        }
        $user = User::findOrFail($d['user_id']);
        if ($d['effect'] === 'grant' && $user->isSystemAdmin() && ! in_array($d['permission_code'], FinanceGuard::TECHNICAL_ADMIN, true)) {
            throw ValidationException::withMessages(['permission_code' => FinanceAccessGrants::ADMIN_REFUSED]);
        }
        // A grant follows the same rules as the head's: no new SoD conflict, sensitive access decided by the ED.
        if ($d['effect'] === 'grant') {
            $grant = app(FinanceAccessGrants::class)->request($r->user(), $user, ['kind' => 'permission', 'code' => $d['permission_code'], 'reason' => (string) $d['reason']]);

            return response()->json(['ok' => true, 'pending' => $grant->state === 'pending_approval',
                'message' => $grant->state === 'pending_approval' ? 'أُرسل المنح إلى المدير التنفيذي؛ لا يسري قبل قراره.' : 'تم منح الصلاحية.']);
        }
        DB::transaction(function () use ($d, $actor): void {
            $previous = DB::table('finance_user_permissions')->where('user_id', $d['user_id'])->where('permission_code', $d['permission_code'])->lockForUpdate()->first();
            if ($previous?->grant_id) {
                DB::table('finance_access_grants')->where('id', $previous->grant_id)->where('state', 'applied')
                    ->update(['state' => 'ended', 'ended_by' => $actor, 'ended_at' => now(), 'end_reason' => $d['reason'] ?? 'إرجاع إلى صلاحيات الدور', 'updated_at' => now()]);
            }
            if ($d['effect'] === 'inherit') {
                DB::table('finance_user_permissions')->where('user_id', $d['user_id'])->where('permission_code', $d['permission_code'])->delete();
            } else {
                DB::table('finance_user_permissions')->updateOrInsert(['user_id' => $d['user_id'], 'permission_code' => $d['permission_code']],
                    ['effect' => $d['effect'], 'reason' => $d['reason'], 'decided_by' => $actor, 'valid_until' => null, 'grant_id' => null, 'updated_at' => now(), 'created_at' => $previous->created_at ?? now()]);
            }
            $this->guard->audit('finance_user_permission', $d['user_id'], $actor, $d['effect'], [
                'permission' => $d['permission_code'], 'previous' => $previous->effect ?? 'inherit', 'reason' => $d['reason'] ?? null,
            ]);
        });

        return response()->json(['ok' => true]);
    }

    /** Ends a finance specialization and removes the role it added in «الحسابات»; the history stays in the audit. */
    public function endAssignment(Request $r, int $id): JsonResponse
    {
        $this->authorizeAdmin($r);
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        $assignment = DB::table('finance_staff_assignments')->find($id);
        abort_unless($assignment && $assignment->is_active, 404);
        app(FinanceAccessGrants::class)->endSpecialization($r->user(), User::findOrFail($assignment->user_id), $assignment->specialization, $d['reason']);

        return response()->json(['ok' => true]);
    }

    /** A template assignment, through the same rules as the head's (SoD refusal, ED decision when sensitive). */
    public function assign(Request $r): JsonResponse
    {
        $this->authorizeAdmin($r);
        $d = $r->validate(['user_id' => 'required|exists:users,id', 'specialization' => ['required', Rule::in(array_keys(FinancePermissionCatalog::TEMPLATES))], 'is_primary' => 'required|boolean',
            'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:today', 'reason' => 'required|string|min:5|max:1000']);
        $grant = app(FinanceAccessGrants::class)->request($r->user(), User::findOrFail($d['user_id']), ['kind' => 'specialization', 'code' => $d['specialization'],
            'is_primary' => $d['is_primary'], 'valid_until' => $d['valid_until'] ?? null, 'reason' => $d['reason']]);

        return response()->json(['ok' => true, 'pending' => $grant->state === 'pending_approval',
            'message' => $grant->state === 'pending_approval' ? 'أُرسل الإسناد إلى المدير التنفيذي؛ لا يسري قبل قراره.' : 'تم إسناد الاختصاص.']);
    }

    public function board(Request $r)
    {
        $this->authorizeAdmin($r);
        $d = $r->validate(['user_id' => 'required|exists:users,id', 'decision_reference' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($d, $r) {
            $user = User::findOrFail($d['user_id']);
            if (! $user->is_active) {
                throw ValidationException::withMessages(['user_id' => 'المستخدم غير فعال']);
            }
            // The role reaches workflow decision steps too, so the technical role never receives it, nor does the assigner.
            if ((int) $user->id === (int) $r->user()->id) {
                throw ValidationException::withMessages(['user_id' => 'لا يُسنِد أحد لنفسه اختصاصًا أو صلاحية مالية.']);
            }
            if ($user->isSystemAdmin()) {
                throw ValidationException::withMessages(['user_id' => FinanceAccessGrants::ADMIN_REFUSED]);
            }
            // The treasurer is a Board member (owner decision 6, 2026-11-27): never an account that holds, or is
            // appointed to hold, the technical role (RoleSeparation; the database trigger is the backstop).
            RoleSeparation::assertAllowed((int) $user->id, 'finance_board_treasurer', 'user_id');
            DB::table('finance_board_approvers')->updateOrInsert(['user_id' => $d['user_id']], ['is_active' => true, 'assigned_by' => $r->user()->id, 'updated_at' => now(), 'created_at' => now()]);
            $role = DB::table('roles')->where('code', 'finance_board_treasurer')->value('id');
            if (! $role) {
                throw ValidationException::withMessages(['role' => 'دور أمين الصندوق غير موجود']);
            }
            if (! DB::table('department_user_roles')->where('user_id', $d['user_id'])->where('role_id', $role)->whereNull('department_id')->exists()) {
                DB::table('department_user_roles')->insert(['user_id' => $d['user_id'], 'role_id' => $role, 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('finance_audit_events')->insert(['entity_type' => 'board_treasurer', 'entity_id' => (string) $d['user_id'], 'actor_id' => $r->user()->id, 'action' => 'assign', 'detail' => json_encode(['decision_reference' => $d['decision_reference']]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'تم إسناد دور أمين صندوق مجلس الإدارة.');
    }

    public function threshold(Request $r)
    {
        $this->authorizeAdmin($r);
        $d = $r->validate(['amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'decision_reference' => 'required|string|min:5|max:1000']);
        $value = FinanceMoney::cents($d['amount']);
        if ($value < 0) {
            throw ValidationException::withMessages(['amount' => 'مبلغ غير صالح']);
        }DB::transaction(function () use ($r, $d) {
            $policy = DB::table('finance_purchase_policy')->where('code', 'board_treasurer_purchase_threshold')->lockForUpdate()->first();
            if (! $policy) {
                throw ValidationException::withMessages(['policy' => 'سياسة مجلس الإدارة غير موجودة']);
            }DB::table('finance_purchase_policy')->where('id', $policy->id)->update(['threshold_ils' => $d['amount'], 'is_active' => true, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
            DB::table('finance_audit_events')->insert(['entity_type' => 'finance_purchase_policy', 'entity_id' => (string) $policy->id, 'actor_id' => $r->user()->id, 'action' => 'threshold_update', 'detail' => json_encode(['previous' => $policy->threshold_ils, 'new' => $d['amount'], 'reference' => $d['decision_reference']]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'تم تحديث حد اعتماد أمين الصندوق.');
    }
}
