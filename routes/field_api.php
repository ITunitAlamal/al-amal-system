<?php

use App\Http\Controllers\Field\FieldApiController as Field;
use App\Http\Middleware\AuthenticateFieldDevice;
use Illuminate\Support\Facades\Route;

/*
 * API of the Windows field-distribution client (field_client/), stateless, token + device bound. Mounted under /api.
 */
Route::prefix('field/v1')->name('field.')->group(function (): void {
    Route::get('/server', [Field::class, 'server'])->middleware('throttle:30,1,field-server')->name('server');
    Route::post('/session', [Field::class, 'signIn'])->middleware('throttle:5,1,field-session')->name('session');
    Route::middleware([AuthenticateFieldDevice::class, 'throttle:120,1,field-api'])->group(function (): void {
        Route::delete('/session', [Field::class, 'signOut'])->name('session.end');
        Route::get('/status', [Field::class, 'status'])->name('status');
        Route::get('/batches', [Field::class, 'batches'])->name('batches');
        Route::post('/batches/{batch}/manifest', [Field::class, 'manifest'])->name('manifest');
        Route::post('/sync', [Field::class, 'sync'])->name('sync');
    });
});
