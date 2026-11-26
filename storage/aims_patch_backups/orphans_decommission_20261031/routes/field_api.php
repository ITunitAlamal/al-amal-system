<?php

use App\Http\Controllers\Field\FieldApiController as Field;
use App\Http\Middleware\AuthenticateFieldDevice;
use Illuminate\Support\Facades\Route;

/*
 * API of the Windows field-distribution client (stateless, token + device bound). Mounted under /api by bootstrap.
 */
Route::prefix('field/v1')->name('field.')->group(function () {
    Route::get('/server', [Field::class, 'server'])->middleware('throttle:30,1')->name('server');
    Route::post('/session', [Field::class, 'signIn'])->middleware('throttle:5,1')->name('session');
    Route::middleware([AuthenticateFieldDevice::class, 'throttle:120,1'])->group(function () {
        Route::delete('/session', [Field::class, 'signOut'])->name('session.end');
        Route::get('/status', [Field::class, 'status'])->name('status');
        Route::get('/batches', [Field::class, 'batches'])->name('batches');
        Route::post('/batches/{batch}/manifest', [Field::class, 'manifest'])->name('manifest');
        Route::post('/sync', [Field::class, 'sync'])->name('sync');
    });
});
