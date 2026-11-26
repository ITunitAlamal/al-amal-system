<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class MaintenanceSubmissionService
{
    public function checkReadiness(
        User $user,
        string $publicId
    ): array {
        $errors = [];

        if (! $user->is_active) {
            return $this->result([
                'حساب المستخدم غير نشط.',
            ]);
        }

        $request = DB::table('aims_requests')
            ->where('public_id', $publicId)
            ->where('request_type', 'maintenance')
            ->where('requested_by', $user->id)
            ->first();

        if (! $request) {
            return $this->result([
                'طلب الصيانة غير موجود أو لا يخص المستخدم.',
            ]);
        }

        if ($request->status !== 'draft') {
            $errors[] = 'الطلب ليس في حالة مسودة.';
        }

        if (! $request->department_id) {
            $errors[] = 'الطلب غير مرتبط بقسم إداري.';
        }

        $detailsExist = DB::table('maintenance_request_details')
            ->where('aims_request_id', $request->id)
            ->exists();

        if (! $detailsExist) {
            $errors[] = 'بيانات تفاصيل الصيانة غير مكتملة.';
        }

        $department = $request->department_id
            ? DB::table('departments')
                ->where('id', $request->department_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first()
            : null;

        if (! $department) {
            $errors[] = 'القسم الإداري غير موجود أو غير نشط.';
        }

        $routing = null;

        if ($department) {
            $headId = $department->head_user_id;

            if (! $headId) {
                $errors[] = 'لم يتم تعيين رئيس للقسم.';
            } else {
                $head = User::find($headId);

                if (! $head || ! $head->is_active) {
                    $errors[] = 'رئيس القسم غير نشط.';
                } elseif (! $this->isValidDepartmentHead(
                    $head,
                    (int) $department->id
                )) {
                    $errors[] = 'المستخدم المعيّن لا يمتلك دور رئيس القسم.';
                } elseif ((int) $head->id === (int) $user->id) {
                    // يُمنع اعتماد المستخدم لطلبه الشخصي.
                    $routing = 'executive_director';
                } else {
                    $routing = 'department_head';
                }
            }
        }

        $version = DB::table('workflow_definitions as wd')
            ->join(
                'workflow_versions as wv',
                'wv.workflow_definition_id',
                '=',
                'wd.id'
            )
            ->where('wd.code', 'maintenance')
            ->where('wv.status', 'published')
            ->orderByDesc('wv.version_number')
            ->first(['wv.id']);

        if (! $version) {
            $errors[] = 'لا توجد نسخة منشورة من سير عمل الصيانة.';
        }

        if ($routing === 'executive_director') {
            $errors[] =
                'يلزم التحقق من تكليف المدير التنفيذي قبل توجيه طلب رئيس القسم.';
        }

        return [
            'ready' => count($errors) === 0,
            'routing' => $routing,
            'workflow_version_id' => $version?->id,
            'errors' => $errors,
        ];
    }

    private function isValidDepartmentHead(
        User $head,
        int $departmentId
    ): bool {
        return DB::table('department_user_roles as dur')
            ->join('roles as r', 'r.id', '=', 'dur.role_id')
            ->where('dur.user_id', $head->id)
            ->where('dur.department_id', $departmentId)
            ->where('r.code', 'department_head')
            ->exists();
    }

    private function result(array $errors): array
    {
        return [
            'ready' => false,
            'routing' => null,
            'workflow_version_id' => null,
            'errors' => $errors,
        ];
    }
}
