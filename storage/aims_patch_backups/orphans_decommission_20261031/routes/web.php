<?php

use App\Http\Controllers\Admin\ApprovalDelegationController;
use App\Http\Controllers\Admin\AttendanceAutoCorrectionController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AttendanceEmployeeLedgerController;
use App\Http\Controllers\Admin\AttendanceOperationsController;
use App\Http\Controllers\Admin\AttendancePunchCorrectionController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CareerScaleController;
use App\Http\Controllers\Admin\CorrespondencePolicyController;
use App\Http\Controllers\Admin\CustomFieldController;
use App\Http\Controllers\Admin\DashboardLayoutController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EmployeeAccountController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeProfileController;
use App\Http\Controllers\Admin\FinancialSettingsController;
use App\Http\Controllers\Admin\JobCatalogController;
use App\Http\Controllers\Admin\KpiDefinitionController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\LeaveEntitlementRuleController;
use App\Http\Controllers\Admin\LeavePolicyCorrectionController;
use App\Http\Controllers\Admin\LeaveReportController;
use App\Http\Controllers\Admin\LeaveSettingsController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\NavigationController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\WorkflowDesignerController;
use App\Http\Controllers\Auth\EmployeeActivationController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CorrespondenceController;
use App\Http\Controllers\CorrespondenceEditionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentWorkspaceController;
use App\Http\Controllers\EmployeeLeaveRequestController;
use App\Http\Controllers\FormDraftController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HrDashboardController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PersonalTaskController;
use App\Http\Controllers\QuickSearchController;
use App\Http\Controllers\WorkflowDetailsController;
use App\Http\Controllers\WorkflowRequestController;
use App\Http\Controllers\WorkflowTaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    Route::get('/reference/geography', [MasterDataController::class, 'geography'])->name('reference.geography');

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

    Route::get('/ui-preview', [HomeController::class, 'index'])->name('ui.preview');

    // «قسمي»: لرئيس القسم المعيّن في الهيكل ويحمل صلاحيات رئيس القسم فيه؛ النطاق يُحسب في كل طلب.
    Route::get('/my-department', [DepartmentWorkspaceController::class, 'index'])->name('department.workspace');
    Route::get('/hr-services', [HrDashboardController::class, 'index'])->name('hr.dashboard');

    // مؤشرات الأداء: النطاق يُحسب من الهيكل الحالي في كل طلب؛ الإدارة لمدير النظام فقط.
    Route::prefix('kpi')->name('kpi.')->controller(KpiController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/snapshot', 'snapshot')->middleware('throttle:6,1')->name('snapshot');
        Route::get('/{definition}/details', 'details')->whereNumber('definition')->middleware('throttle:60,1')->name('details');
        Route::get('/{definition}/breakdown', 'breakdown')->whereNumber('definition')->middleware('throttle:60,1')->name('breakdown');
        Route::get('/{definition}/trend', 'trend')->whereNumber('definition')->middleware('throttle:30,1')->name('trend');
        Route::get('/{definition}/history', 'history')->whereNumber('definition')->middleware('throttle:60,1')->name('history');
    });
    // تخصيص اللوحات: قوالب ترتيب وإظهار فقط (لا تمنح صلاحية)، بإصدارات واسترجاع ومعاينة بلا بيانات؛ لمدير النظام.
    Route::prefix('admin/dashboard-layouts')->name('admin.dashboard-layouts.')->controller(DashboardLayoutController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::get('/preview', 'preview')->middleware('throttle:60,1')->name('preview');
        Route::post('/{layout}/restore/{version}', 'restore')->whereNumber(['layout', 'version'])->name('restore');
        Route::put('/{layout}/status', 'status')->whereNumber('layout')->name('status');
    });
    Route::prefix('admin/kpi')->name('admin.kpi.')->controller(KpiDefinitionController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/preview', 'preview')->middleware('throttle:30,1')->name('preview');
        Route::put('/{definition}/settings', 'settings')->whereNumber('definition')->name('settings');
        Route::post('/{definition}/versions', 'version')->whereNumber('definition')->name('version');
    });

    // -------------------------------------------------
    // الإشعارات الشخصية والإعدادات المركزية للإشعارات
    // -------------------------------------------------

    Route::get('/search', QuickSearchController::class)->middleware('throttle:60,1')->name('search');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('/correspondence/recent', [CorrespondenceController::class, 'recent'])->name('correspondence.recent');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])
        ->whereUuid('notification')->name('notifications.open');
    Route::get('/admin/notification-settings', [NotificationSettingsController::class, 'index'])->name('admin.notification-settings.index');
    Route::put('/admin/notification-settings/{setting}', [NotificationSettingsController::class, 'update'])
        ->whereNumber('setting')->name('admin.notification-settings.update');

    // -------------------------------------------------
    // 3.5 إدارة الأقسام - Vue + Inertia
    // -------------------------------------------------

    // Replaced by «الهيكل التنظيمي» (structure, units, heads, proposals decided by the executive director).
    Route::get('/admin/departments-vue', fn () => redirect()->route('admin.organization.index'))->name('admin.departments.vue');

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

            // تكليفات إضافية (رئاسة قسم ثانٍ، دور في قسم آخر): مدير النظام فقط، بسبب مسجل
            Route::put('/{employee}/password', [EmployeeAccountController::class, 'updatePassword'])
                ->whereNumber('employee')->middleware('throttle:10,1')->name('password.update');
            Route::delete('/{employee}', [EmployeeAccountController::class, 'destroy'])
                ->whereNumber('employee')->middleware('throttle:10,1')->name('destroy');

            Route::post('/{employee}/assignments', 'addAssignment')
                ->middleware('throttle:20,1')->name('assignments.store');
            Route::delete('/{employee}/assignments/{assignment}', 'removeAssignment')
                ->whereNumber('assignment')->middleware('throttle:20,1')->name('assignments.destroy');

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

    Route::get('/workflows/{publicId}', [WorkflowTaskController::class, 'show'])
        ->whereUuid('publicId')
        ->name('workflows.show');

    Route::post('/workflows/{publicId}/act', [WorkflowTaskController::class, 'act'])
        ->whereUuid('publicId')
        ->middleware('throttle:30,1')
        ->name('workflows.act');

    Route::post('/workflows/{publicId}/hold', [WorkflowTaskController::class, 'hold'])
        ->whereUuid('publicId')->middleware('throttle:30,1')->name('workflows.hold');
    Route::post('/workflows/{publicId}/resume', [WorkflowTaskController::class, 'resume'])
        ->whereUuid('publicId')->middleware('throttle:30,1')->name('workflows.resume');
    Route::post('/workflows/{publicId}/reopen', [WorkflowTaskController::class, 'reopen'])
        ->whereUuid('publicId')->middleware('throttle:30,1')->name('workflows.reopen');

    // -------------------------------------------------
    // 3.13.1 طلباتي ومهامي - محرك سير العمل الديناميكي
    // -------------------------------------------------

    Route::get('/tasks', [WorkflowTaskController::class, 'index'])->name('tasks.index');

    // «قائمة مهامي الشخصية»: private to its owner; never approves or closes official work.
    Route::prefix('my/todo')->name('todo.')->controller(PersonalTaskController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:60,1')->name('store');
        Route::put('/{task}', 'update')->whereNumber('task')->name('update');
        Route::post('/{task}/complete', 'complete')->whereNumber('task')->name('complete');
        Route::post('/{task}/reopen', 'reopen')->whereNumber('task')->name('reopen');
        Route::post('/{task}/archive', 'archive')->whereNumber('task')->name('archive');
        Route::delete('/{task}', 'destroy')->whereNumber('task')->name('destroy');
        Route::get('/{task}/history', 'history')->whereNumber('task')->name('history');
    });
    Route::put('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');

    Route::prefix('requests')->name('requests.')->controller(WorkflowRequestController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/new/{code}', 'create')->where('code', '[A-Za-z][A-Za-z0-9_]*')->name('create');
            Route::post('/new/{code}', 'store')->where('code', '[A-Za-z][A-Za-z0-9_]*')
                ->middleware('throttle:20,1')->name('store');
        });

    // -------------------------------------------------
    // 3.13.2 مركز الإعدادات: القوائم والحقول ومسارات العمل - مدير النظام فقط
    // -------------------------------------------------

    Route::prefix('admin/navigation')->name('admin.navigation.')->controller(NavigationController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('/layout', 'saveLayout')->name('layout');
            Route::post('/groups', 'storeGroup')->name('groups.store');
            Route::delete('/groups/{item}', 'destroyGroup')->whereNumber('item')->name('groups.destroy');
            Route::post('/reset', 'reset')->name('reset');
            Route::get('/preview/{user}', 'preview')->whereNumber('user')->name('preview');
        });

    Route::prefix('admin/custom-fields')->name('admin.custom-fields.')->controller(CustomFieldController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('/{field}', 'update')->whereNumber('field')->name('update');
        });

    Route::prefix('admin/workflows')->name('admin.workflows.')->controller(WorkflowDesignerController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/definitions', 'storeDefinition')->name('definitions.store');
            Route::put('/definitions/{definition}', 'updateDefinition')->whereNumber('definition')->name('definitions.update');
            Route::post('/definitions/{definition}/draft', 'draft')->whereNumber('definition')->name('definitions.draft');
            Route::get('/versions/{version}', 'edit')->whereNumber('version')->name('versions.edit');
            Route::post('/versions/{version}/publish', 'publish')->whereNumber('version')->name('versions.publish');
            Route::post('/versions/{version}/steps', 'storeStep')->whereNumber('version')->name('steps.store');
            Route::put('/versions/{version}/steps/{step}', 'updateStep')->whereNumber(['version', 'step'])->name('steps.update');
            Route::delete('/versions/{version}/steps/{step}', 'destroyStep')->whereNumber(['version', 'step'])->name('steps.destroy');
            Route::post('/versions/{version}/transitions', 'storeTransition')->whereNumber('version')->name('transitions.store');
            Route::put('/versions/{version}/transitions/{transition}', 'updateTransition')->whereNumber(['version', 'transition'])->name('transitions.update');
            Route::delete('/versions/{version}/transitions/{transition}', 'destroyTransition')->whereNumber(['version', 'transition'])->name('transitions.destroy');
        });

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
            Route::post('/{employee}/activation-code', 'sendActivationCode')
                ->whereNumber('employee')->middleware('throttle:6,1')->name('activation-code');
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
            Route::put('/prorata', 'proposeProrata')->name('prorata.propose');
            Route::post('/prorata/decision', 'decideProrata')->name('prorata.decide');
        });
    Route::post('admin/leaves/grant', [LeaveBalanceController::class, 'grant'])->name('admin.leaves.grant');
    Route::post('admin/leaves/adjust', [LeaveBalanceController::class, 'adjust'])->name('admin.leaves.adjust');
    Route::post('admin/leaves/opening', [LeaveBalanceController::class, 'recordOpening'])->name('admin.leaves.opening');
    Route::get('leaves/balances', [LeaveBalanceController::class, 'index'])->name('leaves.balances');
    Route::get('admin/leaves/report.csv', [LeaveReportController::class, 'download'])->name('admin.leaves.report');

    // Administrative delegations: issued and revoked by the executive director only.
    Route::prefix('admin/approval-delegations')->name('admin.approval-delegations.')
        ->controller(ApprovalDelegationController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
            Route::post('/{delegation}/revoke', 'revoke')->whereNumber('delegation')->middleware('throttle:20,1')->name('revoke');
        });

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
        ->controller(AttendanceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/devices/status', 'deviceStatuses')->name('devices.status');
            Route::post('/devices', 'device')->name('devices.store');
            Route::post('/devices/{device}/probe', 'requestDeviceProbe')->whereNumber('device')->name('devices.probe');
            Route::put('/devices/{device}', 'updateDevice')->whereNumber('device')->name('devices.update');
            Route::post('/schedules', 'schedule')->name('schedules.store');
            Route::post('/assignments', 'assignment')->name('assignments.store');
            Route::post('/overrides', [AttendanceController::class, 'override'])->name('overrides.store');
            Route::post('/decisions/{type}/{id}', 'decide')->whereNumber('id')->name('decisions.store');
            Route::post('/manual', 'manual')->name('manual.store');
        });
});

// AIMS_ATTENDANCE_BACKEND_PHASE2_ROUTES
Route::middleware(['web', 'auth'])->prefix('admin/attendance')->name('admin.attendance.')->group(function () {
    Route::get('/operations', [AttendanceOperationsController::class, 'overview'])->name('operations');
    Route::post('/punches/{punch}/correction', [AttendancePunchCorrectionController::class, 'requestCorrection'])->whereNumber('punch')->middleware('throttle:10,1')->name('punches.correction.request');
    Route::post('/corrections/{correction}/decision', [AttendancePunchCorrectionController::class, 'decide'])->whereNumber('correction')->middleware('throttle:10,1')->name('punches.correction.decision');
    Route::post('/corrections/{correction}/revoke', [AttendancePunchCorrectionController::class, 'revoke'])->whereNumber('correction')->middleware('throttle:10,1')->name('punches.correction.revoke');
    Route::get('/report.csv', [AttendanceOperationsController::class, 'report'])->name('report');
    Route::get('/employee-ledger', [AttendanceEmployeeLedgerController::class, 'index'])->name('employee-ledger');
    // AIMS_LEDGER_EXCEL_V1
    Route::get('/employee-ledger.xlsx', [AttendanceEmployeeLedgerController::class, 'exportXlsx'])->middleware('throttle:10,1')->name('employee-ledger.xlsx');
    Route::get('/employee-ledger/{employee}/{date}', [AttendanceEmployeeLedgerController::class, 'day'])->whereNumber('employee')->where('date', '\d{4}-\d{2}-\d{2}')->name('employee-ledger.day');
    Route::post('/devices/{device}/connection', [AttendanceOperationsController::class, 'activateDevice'])->name('devices.connection');
    Route::post('/raw-punches/{punch}/resolve', [AttendanceOperationsController::class, 'resolveUnmatched'])->whereNumber('punch')->name('raw-punches.resolve');
    Route::post('/devices/{device}/approve-import', [AttendanceOperationsController::class, 'approveImport'])->whereNumber('device')->name('devices.approve-import');
    Route::post('/devices/{device}/sync', [AttendanceOperationsController::class, 'syncDevice'])->whereNumber('device')->middleware('throttle:3,1')->name('devices.sync');
    Route::post('/mappings', [AttendanceOperationsController::class, 'mapping'])->name('mappings.store');
    Route::post('/schedules/{schedule}/default', [AttendanceOperationsController::class, 'setDefaultSchedule'])->name('schedules.default');
});
// AIMS attendance schedule management routes (authorized by controller)
require __DIR__.'/attendance_schedule_management.php';

// AIMS attendance holidays + compensatory leave
require __DIR__.'/attendance_compensatory.php';

// AIMS attendance roster, exemptions, permission and swap requests, administrative actions
require __DIR__.'/attendance_requests.php';

// AIMS_FINANCE_ROUTES_V1
require __DIR__.'/finance.php';

require __DIR__.'/aims_rentals.php';

require __DIR__.'/aims_employees.php';
require __DIR__.'/aims_organization.php';

require __DIR__.'/guardians.php';
require __DIR__.'/aims_orphans.php';
require __DIR__.'/aims_relief.php';

Route::middleware('auth')->prefix('correspondence')->name('correspondence.')->group(function () {
    Route::post('/{publicId}/editions', [CorrespondenceEditionController::class, 'store'])->whereUuid('publicId')->name('editions.store');
    Route::get('/editions/{edition}', [CorrespondenceEditionController::class, 'show'])->whereUuid('edition')->name('editions.show');
    Route::post('/editions/{edition}/actions', [CorrespondenceEditionController::class, 'act'])->whereUuid('edition')->name('editions.act');
    Route::get('/{publicId}/print', [CorrespondenceController::class, 'print'])->name('print');
    Route::get('/records/{id}', [CorrespondenceController::class, 'record'])->whereNumber('id')->name('record');
    Route::get('/', [CorrespondenceController::class, 'index'])->name('index');
    Route::get('/create', [CorrespondenceController::class, 'create'])->name('create');
    Route::get('/external/create', [CorrespondenceController::class, 'createExternal'])->name('external.create');
    Route::post('/external', [CorrespondenceController::class, 'storeExternal'])->name('external.store');
    Route::get('/recipients', [CorrespondenceController::class, 'recipients'])->name('recipients');
    Route::get('/policies', [CorrespondencePolicyController::class, 'index'])->name('policies');
    Route::post('/policies', [CorrespondencePolicyController::class, 'store'])->name('policies.store');
    Route::put('/appointments/{assignment}', [CorrespondencePolicyController::class, 'appointment'])->whereNumber('assignment')->name('appointments');
    Route::post('/', [CorrespondenceController::class, 'store'])->name('store');
    Route::get('/files/{attachment}', [CorrespondenceController::class, 'download'])->whereUuid('attachment')->name('download');
    Route::get('/{publicId}', [CorrespondenceController::class, 'show'])->whereUuid('publicId')->name('show');
    Route::get('/{publicId}/edit', [CorrespondenceController::class, 'edit'])->whereUuid('publicId')->name('edit');
    Route::post('/{publicId}/draft', [CorrespondenceController::class, 'update'])->whereUuid('publicId')->name('update');
    Route::post('/{publicId}/actions', [CorrespondenceController::class, 'action'])->whereUuid('publicId')->name('action');
});

// HR auto-direction correction review; controller enforces HR/admin RBAC.
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/attendance/auto-corrections', [AttendanceAutoCorrectionController::class, 'index'])->name('attendance.auto-corrections.index');
    Route::post('/admin/attendance/auto-corrections/{correction}/revert', [AttendanceAutoCorrectionController::class, 'revert'])->whereNumber('correction')->middleware('throttle:10,1')->name('attendance.auto-corrections.revert');
});
