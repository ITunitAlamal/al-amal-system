<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAccountController extends Controller
{
    public function updatePassword(Request $request, User $employee, AuditService $audit): RedirectResponse
    {
        $this->authorizeAdministrator($request, $employee);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
        ], [], ['password' => 'كلمة المرور الجديدة']);

        DB::transaction(function () use ($request, $employee, $data, $audit): void {
            $account = User::query()->lockForUpdate()->findOrFail($employee->id);
            $account->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
                'must_change_password' => true,
            ])->save();
            $this->revokeSessions($account);
            DB::table('employee_activation_codes')->where('user_id', $account->id)->delete();
            $audit->record($request, 'employee.password_reset', $account, null, ['must_change_password' => true]);
        });

        return back()->with('success', 'تم تغيير كلمة المرور وإنهاء الجلسات السابقة. يجب تغيير الكلمة المؤقتة عند الدخول؛ حالة تفعيل الحساب كما هي.');
    }

    /**
     * «إيقاف الحساب»: an account is never erased (approved HR requirements §4.1 «منع الحذف النهائي… الإيقاف أو الحذف
     * المنطقي»; TRANSFORM §20). It is deactivated and its sessions, remember token and activation codes end; its role
     * assignments, the audit trail and the employee file stay as they are, so past decisions keep their author.
     */
    public function destroy(Request $request, User $employee, AuditService $audit): RedirectResponse
    {
        $this->authorizeAdministrator($request, $employee);
        $request->validate(['confirm' => ['required', 'accepted']]);

        DB::transaction(function () use ($request, $employee, $audit): void {
            $account = User::query()->lockForUpdate()->findOrFail($employee->id);
            if ($account->roleAssignments()->whereHas('role', fn ($query) => $query->whereIn('code', ['system_admin', 'executive_director']))->exists()) {
                throw ValidationException::withMessages(['account' => 'هذا الحساب محمي بسبب دور مدير النظام أو المدير التنفيذي.']);
            }
            if (DB::table('departments')->where('head_user_id', $account->id)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['account' => 'صاحب الحساب رئيس قسم حاليًا؛ عيّن رئيسًا بديلًا من صفحة «الأقسام» أولًا.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(['account' => 'الحساب موقوف من قبل.']);
            }

            $account->forceFill(['is_active' => false, 'remember_token' => Str::random(60)])->save();
            $this->revokeSessions($account);
            DB::table('employee_activation_codes')->where('user_id', $account->id)->delete();
            $audit->record($request, 'employee.account.deactivated', $account, ['is_active' => true], ['is_active' => false]);
        });

        return back()->with('success', 'أُوقف حساب الدخول وأُنهيت جلساته. بقيت تكليفاته وسجلاته وملف الموظف محفوظة، ويُعاد تفعيله من «تعديل» عند الحاجة.');
    }

    private function authorizeAdministrator(Request $request, User $employee): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403);
        if ((int) $request->user()->id === (int) $employee->id) {
            throw ValidationException::withMessages(['account' => 'لا يمكن تنفيذ هذا الإجراء على حسابك من إدارة المستخدمين.']);
        }
    }

    private function revokeSessions(User $account): void
    {
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
        if ($account->email !== null) {
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();
        }
    }
}
