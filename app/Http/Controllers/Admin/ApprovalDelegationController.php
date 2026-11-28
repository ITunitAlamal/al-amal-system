<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Workflow\ApprovalDelegations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «التفويضات الإدارية»: the executive director issues and revokes scoped delegations. The system admin and
 * the delegates see them (read only); nobody but the executive director can put one in force.
 */
final class ApprovalDelegationController extends Controller
{
    public function __construct(private readonly ApprovalDelegations $delegations) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isExecutive = $this->delegations->isExecutive($user);
        $isDelegate = DB::table('approval_delegations')->where('delegate_user_id', $user->id)->exists();
        abort_unless($user->is_active && ($isExecutive || $user->isSystemAdmin() || $isDelegate), 403);
        $this->delegations->closeLapsed();
        $today = now()->toDateString();

        return Inertia::render('Admin/ApprovalDelegations/Index', [
            'canIssue' => $isExecutive,
            'scopes' => ApprovalDelegations::SCOPES,
            'delegations' => $this->delegations->list($isExecutive || $user->isSystemAdmin() ? null : (int) $user->id)
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'scope' => ApprovalDelegations::SCOPES[$row->scope_code] ?? $row->scope_code,
                    'delegator' => $row->delegator_name,
                    'delegate' => $row->delegate_name,
                    'startsOn' => $row->starts_on,
                    'endsOn' => $row->ends_on,
                    'reference' => $row->decision_reference,
                    'note' => $row->note,
                    'status' => $row->status === 'revoked' ? 'revoked' : ($row->starts_on > $today ? 'scheduled' : 'in_force'),
                    'revokedBy' => $row->revoker_name,
                    'revokedAt' => $row->revoked_at,
                    'revokeReason' => $row->revoke_reason,
                    'canRevoke' => $isExecutive && $row->status === 'active',
                ])->all(),
            // Candidates: active users other than the executive director, with their department, heads first.
            'users' => $isExecutive ? DB::table('users as u')->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
                ->where('u.is_active', true)->where('u.id', '!=', $user->id)
                ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM departments h WHERE h.head_user_id = u.id AND h.is_active) THEN 0 ELSE 1 END')
                ->orderBy('u.name')->get(['u.id', 'u.name', 'd.name as department'])->all() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Authorize before validating: the rules check that the delegate account exists (AC-07).
        $this->assertExecutive($request, 'إصدار التفويض الإداري من صلاحية المدير التنفيذي فقط.');
        $data = $request->validate([
            'scope_code' => ['required', Rule::in(array_keys(ApprovalDelegations::SCOPES))],
            'delegate_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'decision_reference' => ['required', 'string', 'min:2', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->delegations->issue($request->user(), $data, $request->ip());

        return back()->with('success', 'صدر التفويض وسُجّل. يسري على المعاملات التي تصل إلى المرحلة المفوَّضة من تاريخ بدايته.');
    }

    public function revoke(Request $request, int $delegation): RedirectResponse
    {
        $this->assertExecutive($request, 'إلغاء التفويض الإداري من صلاحية المدير التنفيذي فقط.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $returned = $this->delegations->revoke($request->user(), $delegation, $data['reason'], $request->ip());

        return back()->with('success', 'أُلغي التفويض.'.($returned ? ' أُعيدت '.$returned.' معاملة مفتوحة إلى صاحب الصلاحية الأصلي.' : ''));
    }

    /** Same rule as ApprovalDelegations::issue/revoke, which repeat it inside the service. */
    private function assertExecutive(Request $request, string $message): void
    {
        abort_unless($this->delegations->isExecutive($request->user()), 403, $message);
    }
}
