<?php

use App\Http\Controllers\Admin\OrganizationController as Organization;
use App\Http\Controllers\DepartmentUnitsController as Units;
use Illuminate\Support\Facades\Route;

/*
 * Organizational structure: the approved tree with functional units, structural proposals decided by the executive
 * director (workflow ORG_STRUCTURE_CHANGE), staff distribution over units by department heads, and time-limited
 * scoped delegations. Every controller repeats its own authorization; the menu only hides.
 */
Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('admin/organization')->name('admin.organization.')->controller(Organization::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/nodes/{department}/details', 'updateDetails')->whereNumber('department')->name('details');
        Route::put('/nodes/{department}/head', 'updateHead')->whereNumber('department')->name('head');
        Route::post('/changes', 'propose')->middleware('throttle:20,1')->name('changes.store');
        Route::post('/suggestions', 'suggest')->name('suggestions');
        Route::get('/changes/{change}', 'showChange')->whereNumber('change')->name('changes.show');
        Route::post('/changes/{change}/submit', 'submitSuggestion')->whereNumber('change')->name('changes.submit');
        Route::post('/changes/{change}/dismiss', 'dismissSuggestion')->whereNumber('change')->name('changes.dismiss');
        Route::post('/changes/{change}/withdraw', 'withdraw')->whereNumber('change')->name('changes.withdraw');
        Route::put('/delegation-policy/{policy}', 'updatePolicy')->whereNumber('policy')->name('policy');
    });
    Route::prefix('my-department')->name('department.')->controller(Units::class)->group(function () {
        Route::post('/units/assignments', 'assign')->name('units.assign');
        Route::post('/units/assignments/{assignment}/end', 'end')->whereNumber('assignment')->name('units.end');
        Route::post('/delegations/preview', 'preview')->middleware('throttle:60,1')->name('delegations.preview');
        Route::post('/delegations', 'delegate')->middleware('throttle:20,1')->name('delegations.store');
        Route::post('/delegations/{delegation}/revoke', 'revoke')->whereNumber('delegation')->name('delegations.revoke');
    });
});
