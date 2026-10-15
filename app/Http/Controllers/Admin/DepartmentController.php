<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    private function authorizeManagement(): void
    {
        abort_unless(
            auth()->user()?->hasPermission('system.departments.manage'),
            403,
            'ليس لديك صلاحية إدارة الهيكل التنظيمي.'
        );
    }

    public function index()
    {
        $this->authorizeManagement();

        $departments = Department::orderBy('name')->get();

        return view('admin.departments.index', compact('departments'));
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code')
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                Rule::exists('departments', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ]);

        DB::transaction(function () use ($data, $request) {
            $department = Department::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $data['parent_id'] ?? null,
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

        return redirect()
            ->route('admin.departments.index')
            ->with('success', 'تمت إضافة القسم بنجاح.');
    }

    public function update(Request $request, Department $department)
    {
        $this->authorizeManagement();

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code')
                    ->ignore($department->id)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                Rule::exists('departments', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $parentId = isset($data['parent_id'])
            ? (int) $data['parent_id']
            : null;

        if ($parentId === $department->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'لا يمكن أن يتبع القسم نفسه.',
            ]);
        }

        // Prevent circular relationships in the hierarchy.
        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if ($currentId === $department->id ||
                isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'هذه العلاقة ستنشئ تسلسلاً تنظيميًا دائريًا.',
                ]);
            }

            $visited[$currentId] = true;

            $currentId = Department::whereKey($currentId)
                ->value('parent_id');

            $currentId = $currentId !== null
                ? (int) $currentId
                : null;
        }

        if (!$department->is_active && $parentId !== null) {
            // Inactive departments can retain an active parent.
            // Activation will still require an active parent.
        }

        DB::transaction(function () use ($department, $data, $parentId, $request) {
            $before = $department->getAttributes();

            $department->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $parentId,
            ]);

            $this->recordAudit(
                $request,
                'department.updated',
                $department,
                $before,
                $department->fresh()->getAttributes()
            );
        });

        return redirect()
            ->route('admin.departments.index')
            ->with('success', 'تم تعديل بيانات القسم بنجاح.');
    }

    public function toggle(Request $request, Department $department)
    {
        $this->authorizeManagement();

        if ($department->is_active) {
            $hasActiveChildren = Department::where(
                'parent_id', $department->id
            )->where('is_active', true)->exists();

            if ($hasActiveChildren) {
                return back()->withErrors([
                    'department' => 'لا يمكن إيقاف قسم لديه أقسام فرعية نشطة.',
                ]);
            }
        } else {
            if ($department->parent_id !== null) {
                $parent = Department::find($department->parent_id);

                if (!$parent || !$parent->is_active) {
                    return back()->withErrors([
                        'department' => 'يجب تفعيل الإدارة التابعة لها أولاً.',
                    ]);
                }
            }
        }

        DB::transaction(function () use ($department, $request) {
            $before = $department->getAttributes();

            $department->update([
                'is_active' => !$department->is_active,
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

        return back()->with('success', 'تم تحديث حالة القسم بنجاح.');
    }

    private function recordAudit(
        Request $request,
        string $action,
        Department $department,
        ?array $before,
        array $after
    ): void {
        DB::table('audit_logs')->insert([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => Department::class,
            'subject_id' => (string) $department->id,
            'metadata' => json_encode([
                'before' => $before,
                'after' => $after,
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
