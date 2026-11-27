<?php

use App\Http\Controllers\Admin\AttendanceRequestsController;
use App\Http\Controllers\Admin\AttendanceRosterController;
use App\Http\Controllers\Admin\HrActionController;
use App\Http\Controllers\AttendanceSelfServiceController;
use App\Http\Controllers\AttendanceShiftsController;
use Illuminate\Support\Facades\Route;

/*
 * AIMS attendance completion (2026-10-29): roster, exemptions, permission and swap requests, administrative actions.
 * Every controller authorizes by role and record ownership; the HR screens stay inside /admin/attendance.
 */
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::prefix('admin/attendance')->name('admin.attendance.')->group(function (): void {
        Route::get('/roster', [AttendanceRosterController::class, 'grid'])->name('roster');
        Route::post('/roster/preview', [AttendanceRosterController::class, 'preview'])->middleware('throttle:30,1')->name('roster.preview');
        Route::post('/assignments/bulk', [AttendanceRosterController::class, 'bulkAssign'])->middleware('throttle:10,1')->name('assignments.bulk');
        Route::post('/exemptions', [AttendanceRosterController::class, 'exemption'])->middleware('throttle:10,1')->name('exemptions.store');
        Route::post('/emergency-changes', [AttendanceRosterController::class, 'emergency'])->middleware('throttle:10,1')->name('emergency.store');
        Route::post('/overrides/{override}/revoke', [AttendanceRosterController::class, 'revoke'])->whereNumber('override')->middleware('throttle:10,1')->name('overrides.revoke');

        Route::get('/requests', [AttendanceRequestsController::class, 'inbox'])->name('requests');
        Route::post('/permissions', [AttendanceRequestsController::class, 'registerPermission'])->middleware('throttle:10,1')->name('permissions.register');
        Route::post('/permissions/{permission}/revoke', [AttendanceRequestsController::class, 'revokePermission'])->whereNumber('permission')->middleware('throttle:10,1')->name('permissions.revoke');
        Route::post('/swaps/{swap}/reverse', [AttendanceRequestsController::class, 'reverseSwap'])->whereNumber('swap')->middleware('throttle:10,1')->name('swaps.reverse');
        Route::post('/constants/{value}/rules', [AttendanceRequestsController::class, 'rules'])->whereNumber('value')->middleware('throttle:20,1')->name('constants.rules');
        Route::post('/settings', [AttendanceRequestsController::class, 'settings'])->middleware('throttle:10,1')->name('settings.update');

        Route::get('/actions', [HrActionController::class, 'index'])->name('actions.index');
        Route::post('/actions', [HrActionController::class, 'store'])->middleware('throttle:20,1')->name('actions.store');
        Route::post('/actions/{action}', [HrActionController::class, 'update'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.update');
        Route::post('/actions/{action}/submit', [HrActionController::class, 'submit'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.submit');
        Route::post('/actions/{action}/decide', [HrActionController::class, 'decide'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.decide');
        Route::post('/actions/{action}/cancel', [HrActionController::class, 'cancel'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.cancel');
        Route::post('/actions/{action}/cancel-request', [HrActionController::class, 'requestCancel'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.cancel-request');
        Route::post('/actions/{action}/cancel-request/decline', [HrActionController::class, 'declineCancel'])->whereNumber('action')->middleware('throttle:20,1')->name('actions.cancel-request.decline');
        Route::get('/actions/{action}/attachment', [HrActionController::class, 'attachment'])->whereNumber('action')->name('actions.attachment');
    });

    Route::prefix('attendance')->name('attendance.')->group(function (): void {
        // «جدول المناوبات» (department heads) and «دوامي الشهري» (2026-11-02).
        Route::get('/shifts', [AttendanceShiftsController::class, 'shifts'])->name('shifts');
        Route::post('/shifts/{department}', [AttendanceShiftsController::class, 'save'])->whereNumber('department')->middleware('throttle:30,1')->name('shifts.save');
        Route::post('/shift-types', [AttendanceShiftsController::class, 'storeType'])->middleware('throttle:20,1')->name('shift-types.store');
        Route::put('/shift-types/{type}', [AttendanceShiftsController::class, 'updateType'])->whereNumber('type')->middleware('throttle:20,1')->name('shift-types.update');
        Route::get('/monthly', [AttendanceShiftsController::class, 'monthly'])->name('monthly');

        Route::get('/requests/{kind}/{id}', [AttendanceSelfServiceController::class, 'show'])->where('kind', 'permission|swap')->whereNumber('id')->name('requests.show');
        Route::post('/permissions', [AttendanceSelfServiceController::class, 'submitPermission'])->middleware('throttle:10,1')->name('permissions.store');
        Route::post('/permissions/{permission}/cancel', [AttendanceSelfServiceController::class, 'cancelPermission'])->whereNumber('permission')->middleware('throttle:10,1')->name('permissions.cancel');
        Route::get('/permissions/{permission}/attachment', [AttendanceSelfServiceController::class, 'permissionAttachment'])->whereNumber('permission')->name('permissions.attachment');
        Route::post('/swaps', [AttendanceSelfServiceController::class, 'proposeSwap'])->middleware('throttle:10,1')->name('swaps.store');
        Route::post('/swaps/{swap}/respond', [AttendanceSelfServiceController::class, 'respondSwap'])->whereNumber('swap')->middleware('throttle:10,1')->name('swaps.respond');
        Route::post('/swaps/{swap}/cancel', [AttendanceSelfServiceController::class, 'cancelSwap'])->whereNumber('swap')->middleware('throttle:10,1')->name('swaps.cancel');
        Route::post('/actions/{action}/acknowledge', [AttendanceSelfServiceController::class, 'acknowledgeAction'])->whereNumber('action')->middleware('throttle:10,1')->name('actions.acknowledge');
        Route::post('/actions/{action}/respond', [AttendanceSelfServiceController::class, 'respondAction'])->whereNumber('action')->middleware('throttle:10,1')->name('actions.respond');
    });
});
