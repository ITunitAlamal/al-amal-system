<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    /**
     * إدارة الأقسام:
     * مدير النظام أو رئيس الموارد البشرية فقط.
     */
    private function authorizeManagement(): void
    {
        abort_unless(
            auth()->user()?->canManageOrganization(),
            403,
            'إدارة الهيكل الإداري متاحة لمدير النظام ورئيس الموارد البشرية فقط.'
        );
    }

    /**
     * The old departments pages were replaced by «الهيكل التنظيمي». Structural changes now go through a proposal the
     * executive director decides (workflow ORG_STRUCTURE_CHANGE), so the direct create, edit and activate actions
     * below refuse; heads are appointed from the new page.
     */
    private function refuseDirectChange(): never
    {
        throw ValidationException::withMessages([
            'department' => 'تعديل الهيكل صار بمقترح يعتمده المدير التنفيذي من صفحة «الهيكل التنظيمي»، وتعيين الرؤساء من الصفحة نفسها.',
        ]);
    }

    /**
     * عرض واجهة الأقسام القديمة: تحوّل إلى «الهيكل التنظيمي».
     */
    public function index()
    {
        $this->authorizeManagement();

        return redirect()->route('admin.organization.index');
    }

    /**
     * إضافة قسم جديد.
     * يُعيّن رئيس القسم بعد إنشاء القسم وإسناد دوره.
     */
    public function store(Request $request)
    {
        $this->authorizeManagement();
        $this->refuseDirectChange();

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'head_user_id' => [
                'prohibited',
            ],
        ]);

        DB::transaction(function () use ($data, $request) {
            $department = Department::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $data['parent_id'] ?? null,
                'head_user_id' => null,
                'is_active' => true,
            ]);

            $this->recordAudit(
                $request,
                'department.created',
                $department,
                null,
                $department->getAttributes()
            );
        });

        return $this->successRedirect(
            $request,
            'تمت إضافة القسم بنجاح.'
        );
    }

    /**
     * تعديل القسم وتعيين رئيسه.
     */
    public function update(
        Request $request,
        Department $department
    ) {
        $this->authorizeManagement();
        $this->refuseDirectChange();

        $headFieldSubmitted = $request->exists('head_user_id');
        $currentParentId = (int) ($department->parent_id ?? 0);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code')
                    ->ignore($department->id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            // The department may keep its current parent even if that parent was deactivated since.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentParentId)),
            ],
            'head_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('is_active', true),
            ],
        ]);

        $parentId = isset($data['parent_id'])
            ? (int) $data['parent_id']
            : null;

        if ($parentId === (int) $department->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'لا يمكن أن يتبع القسم نفسه.',
            ]);
        }

        // منع العلاقات الدائرية.
        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if (
                $currentId === (int) $department->id ||
                isset($visited[$currentId])
            ) {
                throw ValidationException::withMessages([
                    'parent_id' => 'هذه العلاقة ستنشئ تسلسلاً تنظيميًا دائريًا.',
                ]);
            }

            $visited[$currentId] = true;

            $nextId = Department::whereKey($currentId)
                ->value('parent_id');

            $currentId = $nextId !== null
                ? (int) $nextId
                : null;
        }

        DB::transaction(function () use (
            $department,
            $data,
            $parentId,
            $headFieldSubmitted,
            $request
        ) {
            $lockedDepartment = Department::query()
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            // فحص أهلية رئيس القسم داخل المعاملة.
            if (
                $headFieldSubmitted &&
                isset($data['head_user_id'])
            ) {
                $headUserId = (int) $data['head_user_id'];

                $candidate = User::query()
                    ->whereKey($headUserId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                // Owner decision: one person may head several departments. The head need not belong to this
                // department, but must hold the head role assigned in it (an additional assignment if needed).
                if (! $candidate) {
                    throw ValidationException::withMessages([
                        'head_user_id' => 'يجب اختيار مستخدم نشط.',
                    ]);
                }

                $hasHeadRole = DB::table('department_user_roles')
                    ->join(
                        'roles',
                        'roles.id',
                        '=',
                        'department_user_roles.role_id'
                    )
                    ->where(
                        'department_user_roles.user_id',
                        $headUserId
                    )
                    ->where(
                        'department_user_roles.department_id',
                        $lockedDepartment->id
                    )
                    ->where('roles.code', 'department_head')
                    ->exists();

                if (! $hasHeadRole) {
                    throw ValidationException::withMessages([
                        'head_user_id' => 'يجب إسناد دور «رئيس قسم» لهذا المستخدم في هذا القسم أولًا؛ لمن يتبع قسمًا آخر يضيفه مدير النظام من «التكليفات الإضافية» في حسابات المستخدمين.',
                    ]);
                }
            }

            $before = $lockedDepartment->getAttributes();

            $changes = [
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $parentId,
            ];

            if ($headFieldSubmitted) {
                $changes['head_user_id'] =
                    $data['head_user_id'] ?? null;
            }

            $lockedDepartment->update($changes);

            $this->recordAudit(
                $request,
                'department.updated',
                $lockedDepartment,
                $before,
                $lockedDepartment->fresh()->getAttributes()
            );
        }, 3);

        return $this->successRedirect(
            $request,
            'تم تعديل بيانات القسم بنجاح.'
        );
    }

    /**
     * تفعيل أو تعطيل قسم.
     */
    public function toggle(
        Request $request,
        Department $department
    ) {
        $this->authorizeManagement();
        $this->refuseDirectChange();

        if ($department->is_active) {
            $hasActiveChildren = Department::query()
                ->where('parent_id', $department->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveChildren) {
                return back()->withErrors([
                    'department' => 'لا يمكن إيقاف قسم لديه أقسام فرعية نشطة.',
                ]);
            }
        } elseif ($department->parent_id !== null) {
            $parent = Department::find($department->parent_id);

            if (! $parent || ! $parent->is_active) {
                return back()->withErrors([
                    'department' => 'يجب تفعيل الإدارة التابعة لها أولاً.',
                ]);
            }
        }

        DB::transaction(function () use ($department, $request) {
            $before = $department->getAttributes();

            $department->update([
                'is_active' => ! $department->is_active,
            ]);

            $this->recordAudit(
                $request,
                $department->is_active
                    ? 'department.activated'
                    : 'department.deactivated',
                $department,
                $before,
                $department->fresh()->getAttributes()
            );
        });

        return back()->with(
            'success',
            'تم تحديث حالة القسم بنجاح.'
        );
    }

    /**
     * العودة إلى الواجهة المناسبة.
     */
    private function successRedirect(
        Request $request,
        string $message
    ) {
        $destination = $request->header('X-Inertia')
            ? 'admin.departments.vue'
            : 'admin.departments.index';

        return redirect()
            ->route($destination)
            ->with('success', $message);
    }

    /**
     * تسجيل تغييرات الأقسام مركزيًا.
     */
    private function recordAudit(
        Request $request,
        string $action,
        Department $department,
        ?array $before,
        array $after
    ): void {
        app(AuditService::class)->record(
            $request,
            $action,
            $department,
            $before,
            $after
        );
    }
}
