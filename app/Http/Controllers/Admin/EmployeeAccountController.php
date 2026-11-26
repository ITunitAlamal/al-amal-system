<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function destroy(Request $request, User $employee, AuditService $audit): RedirectResponse
    {
        $this->authorizeAdministrator($request, $employee);
        $request->validate(['confirm' => ['required', 'accepted']]);

        DB::transaction(function () use ($request, $employee, $audit): void {
            $account = User::query()->lockForUpdate()->findOrFail($employee->id);
            if ($account->roleAssignments()->whereHas('role', fn ($query) => $query->whereIn('code', ['system_admin', 'executive_director']))->exists()) {
                throw ValidationException::withMessages(['account' => 'هذا الحساب محمي بسبب دور مدير النظام أو المدير التنفيذي.']);
            }

            $this->ensureNoOperationalReferences($account);
            $audit->record($request, 'employee.deleted', $account, $account->only(['name', 'employee_number']), null);
            DB::table('employees')->where('user_id', $account->id)->update(['user_id' => null, 'updated_at' => now()]);
            $this->revokeSessions($account);
            foreach (['department_user_roles', 'employee_activation_codes', 'form_drafts', 'user_notification_preferences'] as $table) {
                DB::table($table)->where('user_id', $account->id)->delete();
            }
            DB::table('notifications')->where('notifiable_type', $account->getMorphClass())->where('notifiable_id', $account->id)->delete();
            DB::table('audit_logs')->where('actor_id', $account->id)->update(['actor_id' => null]);
            $account->delete();
        });

        return back()->with('success', 'تم حذف حساب الدخول نهائيًا. بقي ملف الموظف وسجلاته محفوظة.');
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

    /**
     * Only login-owned rows may be removed; business records must never cascade away with an account.
     */
    private function ensureNoOperationalReferences(User $account): void
    {
        $allowed = ['employees.user_id', 'audit_logs.actor_id', 'department_user_roles.user_id',
            'employee_activation_codes.user_id', 'form_drafts.user_id', 'user_notification_preferences.user_id', 'sessions.user_id'];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] !== 'users') {
                    continue;
                }
                foreach ($key['columns'] as $column) {
                    if (! in_array($table['name'].'.'.$column, $allowed, true)
                        && DB::table($table['name'])->where($column, $account->id)->exists()) {
                        throw ValidationException::withMessages(['account' => 'لا يمكن حذف الحساب لارتباطه بمعاملات أو تكليفات قائمة. انقل التكليفات أو عطّل الحساب من «تعديل» للحفاظ على السجلات.']);
                    }
                }
            }
        }
    }
}
