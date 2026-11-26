<?php

use App\Http\Controllers\Admin\EmployeeFileController as Files;
use App\Http\Controllers\Admin\EmployeeImportController as Imports;
use App\Http\Controllers\Admin\EmployeePayChangeController as PayChanges;
use App\Http\Controllers\Admin\EmployeeProfileController as Profiles;
use App\Http\Controllers\MyProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Employee master file: staged staff import, photo and documents, pay and bank change requests, account linking and
 * the employee's own file. Each controller checks the viewer against the employee (App\Services\Hr\EmployeeAccess).
 */
Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('admin/employee-imports')->name('admin.employee-imports.')->controller(Imports::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/template', 'template')->name('template');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::put('/settings', 'settings')->name('settings');
        Route::get('/{batch}', 'show')->whereNumber('batch')->name('show');
        Route::put('/{batch}/columns', 'columns')->whereNumber('batch')->name('columns');
        Route::put('/{batch}/options', 'options')->whereNumber('batch')->name('options');
        Route::put('/{batch}/values', 'values')->whereNumber('batch')->name('values');
        Route::post('/{batch}/values/create', 'createValue')->whereNumber('batch')->name('values.create');
        Route::put('/{batch}/rows/{row}', 'row')->whereNumber(['batch', 'row'])->name('rows.update');
        Route::post('/{batch}/commit', 'commit')->whereNumber('batch')->name('commit');
        Route::post('/{batch}/revert', 'revert')->whereNumber('batch')->name('revert');
        Route::post('/{batch}/cancel', 'cancel')->whereNumber('batch')->name('cancel');
        Route::get('/{batch}/issues.csv', 'issues')->whereNumber('batch')->name('issues');
    });

    Route::prefix('employee-files/{employee}')->whereNumber('employee')->name('admin.employee-files.')->controller(Files::class)->group(function () {
        Route::get('/photos/{photo}/{variant}', 'photo')->whereNumber('photo')->whereIn('variant', ['display', 'original'])->name('photo');
        Route::post('/photo', 'storePhoto')->middleware('throttle:20,1')->name('photo.store');
        Route::post('/photos/{photo}/review', 'reviewPhoto')->whereNumber('photo')->name('photo.review');
        Route::delete('/photo', 'removePhoto')->name('photo.remove');
        Route::post('/documents', 'storeDocument')->middleware('throttle:60,1')->name('documents.store');
        Route::post('/documents/{document}/verify', 'verify')->whereNumber('document')->name('documents.verify');
        Route::post('/documents/{document}/archive', 'archive')->whereNumber('document')->name('documents.archive');
        Route::get('/documents/{document}/download', 'download')->whereNumber('document')->name('documents.download');
    });

    Route::get('/admin/employee-profiles/export.csv', [Profiles::class, 'export'])->name('admin.employee-profiles.export');
    Route::post('/admin/employee-profiles/{employee}/link-account', [Profiles::class, 'linkAccount'])->whereNumber('employee')->name('admin.employee-profiles.link-account');
    Route::post('/admin/employee-profiles/{employee}/create-account', [Profiles::class, 'createAccount'])->whereNumber('employee')
        ->middleware('throttle:6,1')->name('admin.employee-profiles.create-account');
    Route::post('/admin/employee-profiles/{employee}/activate-direct', [Profiles::class, 'activateWithoutSms'])->whereNumber('employee')
        ->middleware('throttle:6,1')->name('admin.employee-profiles.activate-direct');

    Route::post('/admin/employee-profiles/{employee}/pay-changes', [PayChanges::class, 'store'])->whereNumber('employee')->name('admin.employee-pay-changes.store');
    Route::get('/admin/employee-pay-changes/{change}', [PayChanges::class, 'show'])->whereNumber('change')->name('admin.employee-pay-changes.show');
    Route::put('/admin/employee-pay-changes/{change}', [PayChanges::class, 'update'])->whereNumber('change')->name('admin.employee-pay-changes.update');
    Route::post('/admin/employee-pay-changes/{change}/withdraw', [PayChanges::class, 'withdraw'])->whereNumber('change')->name('admin.employee-pay-changes.withdraw');

    Route::get('/my/profile', [MyProfileController::class, 'show'])->name('my.profile');
});
