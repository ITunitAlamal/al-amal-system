<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormDraftController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeProfileController;
use App\Http\Controllers\Admin\LeaveEntitlementRuleController;
use App\Http\Controllers\Admin\LeavePolicyCorrectionController;
use App\Http\Controllers\Admin\LeaveSettingsController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\LeaveReportController;
use App\Http\Controllers\EmployeeLeaveRequestController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\JobCatalogController;
use App\Http\Controllers\Admin\FinancialSettingsController;
use App\Http\Controllers\WorkflowDetailsController;
use App\Http\Controllers\Admin\CareerScaleController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\EmployeeActivationController;
use App\Models\Department;
use App\Models\User;
use App\Http\Controllers\MaintenanceRequestController;
/*
|--------------------------------------------------------------------------
| AIMS - Al Amal Institute Management System
|--------------------------------------------------------------------------
| جمعية معهد الأمل للأيتام
| Laravel 13 + Vue 3 + Inertia.js
| SRS v3.0 + Approved Requirements
|--------------------------------------------------------------------------
*/

// =====================================================
// 1. الصفحة الرئيسية
// =====================================================

Route::redirect('/', '/dashboard');


// =====================================================
// 2. تسجيل الدخول
// =====================================================

Route::middleware('guest')->group(function () {

    Route::get('/login', [
        AuthController::class,
        'showLogin',
    ])->name('login');

    Route::post('/login', [
        AuthController::class,
        'login',
    ])
        ->middleware('throttle:5,1')
        ->name('login.submit');

    // -------------------------------------------------
    // تفعيل حساب الموظف عبر رمز SMS - مسارات الزوار
    // -------------------------------------------------
    Route::prefix('activate-account')
        ->name('employee.activation.')
        ->controller(EmployeeActivationController::class)
        ->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/', 'activate')
                ->middleware('throttle:10,1')->name('activate');
            Route::post('/resend', 'resend')
                ->middleware('throttle:5,1')->name('resend');
        });

});


// =====================================================
// 3. المسارات المحمية
// =====================================================

Route::middleware('auth')->group(function () {

    // -------------------------------------------------
    // تغيير كلمة المرور (اختياري أو إجباري عند أول دخول)
    // -------------------------------------------------

    Route::get('/change-password',
        [ForcePasswordChangeController::class, 'show']
    )->name('password.change');

    Route::put('/change-password',
        [ForcePasswordChangeController::class, 'update']
    )->middleware('throttle:5,1')->name('password.change.update');

    // -------------------------------------------------
    // 3.1 لوحة التحكم الحالية - Blade
    // -------------------------------------------------

    Route::get('/dashboard', [
        DashboardController::class,
        'index',
    ])->name('dashboard');


    // -------------------------------------------------
    // 3.2 تسجيل الخروج
    // -------------------------------------------------

    Route::post('/logout', [
        AuthController::class,
        'logout',
    ])->name('logout');


    // -------------------------------------------------
    // 3.3 إدارة الأقسام - Blade والعمليات الأساسية
    // -------------------------------------------------

    Route::prefix('admin/departments')
        ->name('admin.departments.')
        ->controller(DepartmentController::class)
        ->group(function () {

            Route::get('/', 'index')
                ->name('index');

            Route::post('/', 'store')
                ->name('store');

            Route::put('/{department}', 'update')
                ->name('update');

            Route::patch('/{department}/toggle', 'toggle')
                ->name('toggle');

        });


    // -------------------------------------------------
    // 3.4 لوحة التحكم الجديدة - Vue + Inertia
    // -------------------------------------------------

    Route::get('/ui-preview', function () {

        $user = auth()->user();

        $navigation = [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => true,
            ],
        ];

        if ($user->canManageOrganization()) {
            $navigation[] = [
                'label' => 'إدارة الأقسام',
                'url' => route('admin.departments.vue'),
                'icon' => '🏢',
                'active' => false,
            ];
        }

        if ($user->canManageEmployees()) {
            $navigation[] = [
                'label' => 'إدارة الموظفين',
                'url' => route('admin.employees.index'),
                'icon' => '👥',
                'active' => false,
            ];
        }

        if ($user->isSystemAdmin()) {
            $navigation[] = [
                'label' => 'ثوابت النظام',
                'url' => route('admin.master-data.index'),
                'icon' => '⚙️',
                'active' => false,
            ];

            $navigation[] = [
                'label' => 'سجل نشاط النظام',
                'url' => route('admin.audit-logs.index'),
                'icon' => '📋',
                'active' => false,
            ];

            $navigation[] = [
                'label' => 'تجربة الحفظ التلقائي',
                'url' => route('demo.maintenance-draft'),
                'icon' => '💾',
                'active' => false,
            ];

            $navigation[] = [
                'label' => 'إعدادات العملات والصلاحيات المالية',
                'url' => route('admin.financial-settings.ui'),
                'icon' => '💰',
                'active' => false,
            ];
        }

        return Inertia::render('Preview', [
            'username' => $user->name,
            'navigation' => $navigation,
            'dashboard' => app(\App\Services\DashboardService::class)->getData($user),
        ]);

    })->name('ui.preview');


    // -------------------------------------------------
    // 3.5 إدارة الأقسام - Vue + Inertia
    // -------------------------------------------------

    Route::get('/admin/departments-vue', function () {

        $user = auth()->user();

        abort_unless(
            $user->canManageOrganization(),
            403,
            'إدارة الهيكل الإداري متاحة لمدير النظام ورئيس الموارد البشرية فقط.'
        );

        $departments = Department::query()
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'parent_id',
                'head_user_id',
                'is_active',
            ]);

        $departmentNames = $departments->pluck('name', 'id');

        $headIds = $departments
            ->pluck('head_user_id')
            ->filter()
            ->unique()
            ->values();

        $headNames = User::query()
            ->whereIn('id', $headIds)
            ->pluck('name', 'id');

        $departmentData = $departments
            ->map(function ($department) use (
                $departmentNames,
                $headNames
            ) {
                return [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'parent_id' => $department->parent_id,
                    'parent_name' => $department->parent_id
                        ? $departmentNames->get(
                            $department->parent_id
                        )
                        : null,
                    'head_user_id' => $department->head_user_id,
                    'head_name' => $department->head_user_id
                        ? $headNames->get(
                            $department->head_user_id
                        )
                        : null,
                    'is_active' => (bool) $department->is_active,
                ];
            })
            ->values()
            ->all();

        $eligibleHeads = DB::table('users')
            ->join(
                'departments',
                'departments.id',
                '=',
                'users.department_id'
            )
            ->join(
                'department_user_roles',
                function ($join) {
                    $join->on(
                        'department_user_roles.user_id',
                        '=',
                        'users.id'
                    )->on(
                        'department_user_roles.department_id',
                        '=',
                        'departments.id'
                    );
                }
            )
            ->join(
                'roles',
                'roles.id',
                '=',
                'department_user_roles.role_id'
            )
            ->where('users.is_active', true)
            ->where('departments.is_active', true)
            ->whereNull('departments.deleted_at')
            ->where('roles.code', 'department_head')
            ->select([
                'users.id',
                'users.name',
                'users.employee_number',
                'users.department_id',
            ])
            ->distinct()
            ->orderBy('users.name')
            ->get();

        $navigation = [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => false,
            ],
            [
                'label' => 'إدارة الأقسام',
                'url' => route('admin.departments.vue'),
                'icon' => '🏢',
                'active' => true,
            ],
            [
                'label' => 'إدارة الموظفين',
                'url' => route('admin.employees.index'),
                'icon' => '👥',
                'active' => false,
            ],
        ];

        if ($user->isSystemAdmin()) {
            $navigation[] = [
                'label' => 'سجل نشاط النظام',
                'url' => route('admin.audit-logs.index'),
                'icon' => '📋',
                'active' => false,
            ];

            $navigation[] = [
                'label' => 'تجربة الحفظ التلقائي',
                'url' => route('demo.maintenance-draft'),
                'icon' => '💾',
                'active' => false,
            ];

            $navigation[] = [
                'label' => 'إعدادات العملات والصلاحيات المالية',
                'url' => route('admin.financial-settings.ui'),
                'icon' => '💰',
                'active' => false,
            ];
        }

        return Inertia::render('Admin/Departments/Index', [
            'username' => $user->name,
            'departments' => $departmentData,
            'eligibleHeads' => $eligibleHeads,
            'navigation' => $navigation,
        ]);

    })->name('admin.departments.vue');


    // -------------------------------------------------
    // 3.6 إدارة الموظفين - Vue + Inertia
    // -------------------------------------------------

    Route::prefix('admin/employees')
        ->name('admin.employees.')
        ->controller(EmployeeController::class)
        ->group(function () {

            // عرض قائمة الموظفين
            Route::get('/', 'index')
                ->name('index');

            // إنشاء حساب موظف
            Route::post('/', 'store')
                ->name('store');

            // تعديل بيانات الموظف ودوره وحالته
            Route::put('/{employee}', 'update')
                ->name('update');

        });


    // -------------------------------------------------
    // 3.7 سجل نشاط النظام - مدير النظام فقط
    // -------------------------------------------------

    Route::get('/admin/audit-logs', [
        AuditLogController::class,
        'index',
    ])->name('admin.audit-logs.index');


    // -------------------------------------------------
    // 3.8 فحص الاتصال بالخادم - AIMS v0.9
    // -------------------------------------------------

    Route::get('/connection-status', function () {
        return response()
            ->json([
                'status' => 'online',
            ])
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate'
            );
    })->name('connection.status');


    // -------------------------------------------------
    // 3.9 حفظ واسترجاع المسودات - AIMS v1.0
    // -------------------------------------------------

    Route::get(
        '/form-drafts/{draftKey}',
        [FormDraftController::class, 'show']
    )
        ->whereUuid('draftKey')
        ->name('form-drafts.show');

    Route::put(
        '/form-drafts/{draftKey}',
        [FormDraftController::class, 'save']
    )
        ->whereUuid('draftKey')
        ->name('form-drafts.save');


    // -------------------------------------------------
    // 3.10 صفحة تجربة الحفظ التلقائي - AIMS v1.1
    // -------------------------------------------------

    Route::get('/demo/maintenance-draft', function () {

        $user = auth()->user();

        abort_unless(
            $user->isSystemAdmin(),
            403,
            'هذه الصفحة التجريبية متاحة لمدير النظام فقط.'
        );

        $navigation = [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => false,
            ],
            [
                'label' => 'إدارة الأقسام',
                'url' => route('admin.departments.vue'),
                'icon' => '🏢',
                'active' => false,
            ],
            [
                'label' => 'إدارة الموظفين',
                'url' => route('admin.employees.index'),
                'icon' => '👥',
                'active' => false,
            ],
            [
                'label' => 'سجل نشاط النظام',
                'url' => route('admin.audit-logs.index'),
                'icon' => '📋',
                'active' => false,
            ],
            [
                'label' => 'تجربة الحفظ التلقائي',
                'url' => route('demo.maintenance-draft'),
                'icon' => '💾',
                'active' => true,
            ],
            [
                'label' => 'إعدادات العملات والصلاحيات المالية',
                'url' => route('admin.financial-settings.ui'),
                'icon' => '💰',
                'active' => false,
            ],
        ];

        return Inertia::render('Demo/MaintenanceDraft', [
            'username' => $user->name,
            'navigation' => $navigation,
        ]);

    })->name('demo.maintenance-draft');


    // -------------------------------------------------
    // 3.11 واجهة إعدادات العملات والصلاحيات المالية - Vue
    // -------------------------------------------------

    Route::get('/admin/financial-settings-ui', function () {
        $user = auth()->user();

        abort_unless(
            $user->isSystemAdmin(),
            403,
            'هذه الصفحة متاحة لمدير النظام فقط.'
        );

        $navigation = [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => false,
            ],
            [
                'label' => 'إدارة الأقسام',
                'url' => route('admin.departments.vue'),
                'icon' => '🏢',
                'active' => false,
            ],
            [
                'label' => 'إدارة الموظفين',
                'url' => route('admin.employees.index'),
                'icon' => '👥',
                'active' => false,
            ],
            [
                'label' => 'سجل نشاط النظام',
                'url' => route('admin.audit-logs.index'),
                'icon' => '📋',
                'active' => false,
            ],
            [
                'label' => 'إعدادات العملات والصلاحيات المالية',
                'url' => route('admin.financial-settings.ui'),
                'icon' => '💰',
                'active' => true,
            ],
        ];

        return Inertia::render('Admin/FinancialSettings/Index', [
            'username' => $user->name,
            'navigation' => $navigation,
        ]);
    })->name('admin.financial-settings.ui');


    // -------------------------------------------------
    // 3.12 إعدادات العملات والصلاحيات المالية - API
    // -------------------------------------------------

    Route::prefix('admin/financial-settings')
        ->name('admin.financial-settings.')
        ->controller(FinancialSettingsController::class)
        ->group(function () {

            Route::get('/', 'index')
                ->name('index');

            Route::put('/base-currency', 'updateBaseCurrency')
                ->name('base-currency.update');

            Route::put('/limits', 'saveLimit')
                ->name('limits.save');

        });


    // -------------------------------------------------
    // 3.13 تفاصيل المعاملة وسجل الإحالات - قراءة فقط
    // -------------------------------------------------

    Route::get(
        '/workflows/{publicId}/details',
        [WorkflowDetailsController::class, 'show']
    )
        ->whereUuid('publicId')
        ->name('workflows.details');

Route::get('/workflows/{publicId}', function (
    string $publicId,
    \App\Services\WorkflowVisibilityService $visibility
) {
    $user = auth()->user();

    abort_unless(
        $user && $user->is_active,
        403
    );

    $instance = DB::table('workflow_instances')
        ->where('public_id', $publicId)
        ->first(['id']);

    abort_unless($instance, 404);

    abort_unless(
        $visibility->accessLevel($user, (int) $instance->id) !== null,
        403
    );

    return Inertia::render('Workflows/Show', [
        'username' => $user->name,
        'publicId' => $publicId,
        'navigation' => [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => false,
            ],
        ],
    ]);
})
    ->whereUuid('publicId')
    ->name('workflows.show');




    // -------------------------------------------------
    // 3.14 طلبات الصيانة - المسودات فقط
    // -------------------------------------------------

    Route::prefix('maintenance/requests')
        ->name('maintenance.requests.')
        ->controller(MaintenanceRequestController::class)
        ->group(function () {
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{publicId}/edit', 'edit')
                ->whereUuid('publicId')->name('edit');
            Route::put('/{publicId}', 'update')
                ->whereUuid('publicId')->name('update');
        });

    // -------------------------------------------------
    // 3.15 ثوابت النظام المركزية - مدير النظام فقط
    // -------------------------------------------------

    Route::prefix('admin/master-data')
        ->name('admin.master-data.')
        ->controller(MasterDataController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');

            Route::post('/groups', 'storeGroup')->name('groups.store');
            Route::put('/groups/{group}', 'updateGroup')
                ->whereNumber('group')->name('groups.update');

            Route::post('/types', 'storeType')->name('types.store');
            Route::put('/types/{type}', 'updateType')
                ->whereNumber('type')->name('types.update');

            Route::post('/values', 'storeValue')->name('values.store');
            Route::put('/values/{value}', 'updateValue')
                ->whereNumber('value')->name('values.update');
        });

    // -------------------------------------------------
    // 3.16 ثوابت العمل — الفئات والمسميات الوظيفية
    // -------------------------------------------------

    Route::prefix('admin/job-catalog')
        ->name('admin.job-catalog.')
        ->controller(JobCatalogController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');

            Route::get('/categories/create', 'createCategory')
                ->name('categories.create');
            Route::post('/categories', 'storeCategory')
                ->name('categories.store');
            Route::get('/categories/{category}/edit', 'editCategory')
                ->whereNumber('category')->name('categories.edit');
            Route::put('/categories/{category}', 'updateCategory')
                ->whereNumber('category')->name('categories.update');

            Route::get('/titles/create', 'createTitle')
                ->name('titles.create');
            Route::post('/titles', 'storeTitle')
                ->name('titles.store');
            Route::get('/titles/{title}/edit', 'editTitle')
                ->whereNumber('title')->name('titles.edit');
            Route::put('/titles/{title}', 'updateTitle')
                ->whereNumber('title')->name('titles.update');
        });


    // -------------------------------------------------
    // 3.17 ثوابت العمل — السلالم والدرجات الوظيفية
    // -------------------------------------------------

    Route::prefix('admin/career-scales')
        ->name('admin.career-scales.')
        ->controller(CareerScaleController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');

            Route::get('/create', 'createScale')->name('create');
            Route::post('/', 'storeScale')->name('store');
            Route::get('/{scale}/edit', 'editScale')
                ->whereNumber('scale')->name('edit');
            Route::put('/{scale}', 'updateScale')
                ->whereNumber('scale')->name('update');

            Route::get('/grades/create', 'createGrade')
                ->name('grades.create');
            Route::post('/grades', 'storeGrade')
                ->name('grades.store');
            Route::get('/grades/{grade}/edit', 'editGrade')
                ->whereNumber('grade')->name('grades.edit');
            Route::put('/grades/{grade}', 'updateGrade')
                ->whereNumber('grade')->name('grades.update');
        });


    // -------------------------------------------------
    // 3.18 الموارد البشرية — ملفات الموظفين الشاملة
    // -------------------------------------------------

    Route::prefix('admin/employee-profiles')
        ->name('admin.employee-profiles.')
        ->controller(EmployeeProfileController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/lookup', 'lookup')->name('lookup');
            Route::get('/{employee}', 'show')
                ->whereNumber('employee')->name('show');
            Route::get('/{employee}/edit', 'edit')
                ->whereNumber('employee')->name('edit');
            Route::put('/{employee}', 'update')
                ->whereNumber('employee')->name('update');
        });


    // -------------------------------------------------
    // 3.19 ثوابت الموارد البشرية — سياسات استحقاق الإجازات
    // -------------------------------------------------

    Route::prefix('admin/leave-entitlement-rules')
        ->name('admin.leave-entitlement-rules.')
        ->controller(LeaveEntitlementRuleController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::post('/bulk', 'storeBulk')->name('bulk.store');
            Route::get('/{leaveEntitlementRule}/edit', 'edit')
                ->whereNumber('leaveEntitlementRule')->name('edit');
            Route::put('/{leaveEntitlementRule}', 'update')
                ->whereNumber('leaveEntitlementRule')->name('update');
        });

    // =====================================================
    // 3.20 AIMS - employee leave operations (protected by auth)
    // =====================================================
    Route::prefix('admin/leave-policy-corrections')->name('admin.leave-policy-corrections.')
        ->controller(LeavePolicyCorrectionController::class)->group(function () {
            Route::get('/{correction}', 'show')->whereNumber('correction')->name('show');
            Route::post('/{correction}/apply', 'apply')->whereNumber('correction')->name('apply');
        });

    Route::prefix('admin/leaves')->name('admin.leaves.')
        ->controller(LeaveSettingsController::class)->group(function () {
            Route::get('/settings', 'show')->name('settings');
            Route::put('/settings', 'update')->name('settings.update');
            Route::post('/holidays', 'addHoliday')->name('holidays.store');
            Route::delete('/holidays/{holiday}', 'removeHoliday')->whereNumber('holiday')->name('holidays.destroy');
        });
    Route::post('admin/leaves/grant', [LeaveBalanceController::class, 'grant'])->name('admin.leaves.grant');
    Route::post('admin/leaves/adjust', [LeaveBalanceController::class, 'adjust'])->name('admin.leaves.adjust');
    Route::get('leaves/balances', [LeaveBalanceController::class, 'index'])->name('leaves.balances');
    Route::get('admin/leaves/report.csv', [LeaveReportController::class, 'download'])->name('admin.leaves.report');

    Route::prefix('leaves')->name('leaves.')->controller(EmployeeLeaveRequestController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{leave}', 'show')->whereNumber('leave')->name('show');
            Route::get('/{leave}/edit', 'edit')->whereNumber('leave')->name('edit');
            Route::put('/{leave}', 'update')->whereNumber('leave')->name('update');
            Route::post('/{leave}/submit', 'submit')->whereNumber('leave')->name('submit');
            Route::post('/{leave}/decision', 'decide')->whereNumber('leave')->name('decision');
            Route::post('/{leave}/cancel', 'cancel')->whereNumber('leave')->name('cancel');
        });

    // AIMS_ATTENDANCE_ROUTES_V1
    Route::prefix('admin/attendance')->name('admin.attendance.')
        ->controller(\App\Http\Controllers\Admin\AttendanceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/devices', 'device')->name('devices.store');
            Route::put('/devices/{device}', 'updateDevice')->whereNumber('device')->name('devices.update');
            Route::post('/schedules', 'schedule')->name('schedules.store');
            Route::post('/assignments', 'assignment')->name('assignments.store');
            Route::post('/overrides', [\App\Http\Controllers\Admin\AttendanceController::class, 'override'])->name('overrides.store');
            Route::post('/decisions/{type}/{id}', 'decide')->whereNumber('id')->name('decisions.store');
            Route::post('/manual', 'manual')->name('manual.store');
        });
});

// AIMS_ATTENDANCE_BACKEND_PHASE2_ROUTES
\Illuminate\Support\Facades\Route::middleware(['web','auth'])->prefix('admin/attendance')->name('admin.attendance.')->group(function () {
    \Illuminate\Support\Facades\Route::get('/operations', [\App\Http\Controllers\Admin\AttendanceOperationsController::class, 'overview'])->name('operations');
    \Illuminate\Support\Facades\Route::get('/report.csv', [\App\Http\Controllers\Admin\AttendanceOperationsController::class, 'report'])->name('report');
    \Illuminate\Support\Facades\Route::post('/devices/{device}/connection', [\App\Http\Controllers\Admin\AttendanceOperationsController::class, 'activateDevice'])->name('devices.connection');
    \Illuminate\Support\Facades\Route::post('/mappings', [\App\Http\Controllers\Admin\AttendanceOperationsController::class, 'mapping'])->name('mappings.store');
    \Illuminate\Support\Facades\Route::post('/schedules/{schedule}/default', [\App\Http\Controllers\Admin\AttendanceOperationsController::class, 'setDefaultSchedule'])->name('schedules.default');
});
// AIMS attendance schedule management routes (authorized by controller)
require __DIR__.'/attendance_schedule_management.php';
