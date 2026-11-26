<?php

use App\Http\Controllers\Admin\AttendanceScheduleManageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('admin/attendance/schedules')->name('admin.attendance.schedules.')->group(function () {
    Route::post('{schedule}/changes', [AttendanceScheduleManageController::class, 'requestChange'])->name('changes.store');
    Route::post('changes/{requestId}/decision', [AttendanceScheduleManageController::class, 'decide'])->name('changes.decide');
});
