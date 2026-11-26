<?php

use App\Http\Controllers\Admin\GuardianReviewController as Review;
use App\Http\Controllers\Admin\OrphanDelegationController;
use App\Http\Controllers\Auth\GuardianPortalController as Portal;
use App\Http\Middleware\EnsureGuardianActive;
use App\Http\Middleware\ProtectOrphanResponses;
use Illuminate\Support\Facades\Route;

Route::middleware(ProtectOrphanResponses::class)->prefix('guardian')->name('guardian.')->group(function (): void {
    Route::get('/register', [Portal::class, 'form'])->name('register');
    Route::post('/register', [Portal::class, 'register'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/login', [Portal::class, 'form'])->name('login');
    Route::post('/login', [Portal::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::middleware(['auth:guardian', EnsureGuardianActive::class])->group(function (): void {
        Route::get('/', [Portal::class, 'home'])->name('home');
        Route::post('/logout', [Portal::class, 'logout'])->name('logout');
        Route::post('/applications', [Portal::class, 'createApplication'])->middleware('throttle:5,1')->name('applications.store');
        Route::post('/applications/{publicId}/verify', [Portal::class, 'retry'])->middleware('throttle:3,1')->name('applications.verify');
        Route::put('/applications/{publicId}', [Portal::class, 'resubmit'])->middleware('throttle:3,1')->name('applications.resubmit');
    });
});
Route::middleware(['auth:web', ProtectOrphanResponses::class])->prefix('admin/orphans/guardians')->name('admin.orphans.guardians.')->group(function (): void {
    Route::get('/', [Review::class, 'index'])->name('index');
    Route::get('/{publicId}', [Review::class, 'show'])->name('show');
    Route::post('/{publicId}/verify', [Review::class, 'verify'])->middleware('throttle:3,1')->name('verify');
    Route::post('/{publicId}/review', [Review::class, 'review'])->middleware('throttle:10,1')->name('review');
});
Route::middleware(['auth:web', ProtectOrphanResponses::class])->prefix('admin/orphans/delegations')->name('admin.orphans.delegations.')->group(function (): void {
    Route::get('/', [OrphanDelegationController::class, 'index'])->name('index');
    Route::post('/', [OrphanDelegationController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::post('/{accessRequest}/decision', [OrphanDelegationController::class, 'decide'])->whereNumber('accessRequest')->middleware('throttle:10,1')->name('decide');
});
