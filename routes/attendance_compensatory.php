<?php

use App\Http\Controllers\Admin\AttendanceCompensatoryController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin/attendance/compensatory')->name('admin.attendance.compensatory.')->group(function () {
    Route::get('/', [C::class, 'index'])->name('index');
    Route::post('/holidays', [C::class, 'holiday'])->name('holidays.store');
    Route::post('/holidays/{id}/decision', [C::class, 'holidayDecision'])->name('holidays.decide');
    Route::post('/work', [C::class, 'work'])->name('work.store');
    Route::post('/work/{id}/decision', [C::class, 'workDecision'])->name('work.decide');
    Route::post('/work/{id}/credit', [C::class, 'credit'])->name('work.credit');
});
