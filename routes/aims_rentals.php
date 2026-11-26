<?php

use App\Http\Controllers\Admin\PropertyRentalsController as C;
use App\Http\Controllers\Admin\RentalRecordController as R;
use Illuminate\Support\Facades\Route;

/*
 * Assets, buildings and rentals. Decisions are not routes of this file: applications, contracts, terminations and
 * settlements move through the central workflow (/workflows/{publicId}/act) from their record page.
 */
Route::middleware(['web', 'auth'])->prefix('admin/rentals')->name('admin.rentals.')->group(function () {
    Route::controller(C::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/tenants', 'tenant')->name('tenants.store');
        Route::put('/tenants/{id}', 'tenantUpdate')->whereNumber('id')->name('tenants.update');
        Route::post('/applications', 'application')->name('applications.store');
        Route::post('/contracts', 'contract')->name('contracts.store');
        Route::post('/contracts/{id}/dues', 'customDue')->whereNumber('id')->name('contracts.custom-dues');
        Route::delete('/dues/{id}', 'removeCustomDue')->whereNumber('id')->name('dues.remove');
        Route::post('/terminations', 'termination')->name('terminations.store');
        Route::post('/settlements', 'settlement')->name('settlements.store');
        Route::post('/maintenance', 'maintenance')->name('maintenance.store');
        Route::post('/readings', 'reading')->name('readings.store');
        Route::post('/notices', 'notice')->name('notices.store');
        Route::post('/notices/{id}/issue', 'noticeIssue')->whereNumber('id')->name('notices.issue');
        Route::put('/properties/{id}', 'propertyUpdate')->whereNumber('id')->name('properties.update');
        Route::put('/units/{id}', 'unitUpdate')->whereNumber('id')->name('units.update');
        Route::post('/units/{id}/status', 'unitStatus')->whereNumber('id')->name('units.status');
        Route::put('/settings', 'settings')->name('settings');
        Route::get('/report.csv', 'report')->name('report');
    });

    $types = ['application', 'contract', 'case', 'settlement'];
    Route::controller(R::class)->group(function () use ($types) {
        Route::get('/records/{type}/{id}', 'show')->whereIn('type', $types)->whereNumber('id')->name('records.show');
        Route::put('/records/{type}/{id}', 'update')->whereIn('type', $types)->whereNumber('id')->name('records.update');
        Route::post('/records/{type}/{id}/submit', 'submit')->whereIn('type', $types)->whereNumber('id')->name('records.submit');
        Route::post('/files/{owner}/{id}', 'document')->whereIn('owner', ['property', 'unit', 'tenant', ...$types, 'reading'])->whereNumber('id')->name('documents.store');
        Route::get('/documents/{document}', 'download')->whereNumber('document')->name('documents.download');
        Route::post('/cases/{id}/outcome', 'outcome')->whereNumber('id')->name('cases.outcome');
        Route::post('/settlements/{id}/post', 'post')->whereNumber('id')->name('settlements.post');
    });
});

Route::middleware(['web', 'auth'])->prefix('admin/master-data/buildings')->name('admin.master-data.buildings.')->controller(C::class)->group(function () {
    Route::get('/', 'buildings')->name('index');
    Route::post('/properties', 'property')->name('properties.store');
    Route::post('/floors', 'floor')->name('floors.store');
    Route::post('/units', 'unit')->name('units.store');
});
