<?php

use App\Http\Controllers\Auth\GuardianPortalController as Portal;
use App\Http\Controllers\Portal\PortalController as Area;
use App\Http\Middleware\EnsureGuardianActive;
use App\Http\Middleware\ProtectOrphanResponses;
use Illuminate\Support\Facades\Route;

/*
 * Beneficiary portal (portal.al-amal.ps; see config/portal.php for the host boundary). Five areas:
 * الرئيسية، أسرتي، طلباتي، مساعداتي وكفالاتي، الإشعارات. Account approval and each child's guardianship are decided
 * separately by the orphans department (App\Services\Orphans\PortalApplications); reads are scoped by
 * App\Services\Portal\PortalScope. No staff or approval capability is reachable from here.
 */
Route::middleware(ProtectOrphanResponses::class)->prefix('guardian')->name('guardian.')->group(function (): void {
    Route::get('/welcome', [Portal::class, 'welcome'])->name('welcome');
    Route::get('/login', [Portal::class, 'form'])->name('login');
    Route::post('/login', [Portal::class, 'login'])->middleware('throttle:5,1,guardian-login')->name('login.store');
    Route::get('/register', [Portal::class, 'form'])->name('register');
    Route::post('/register', [Portal::class, 'register'])->middleware('throttle:5,1,guardian-register')->name('register.store');
    Route::post('/register/verify', [Portal::class, 'verifySignup'])->middleware('throttle:10,10,guardian-signup-verify')->name('register.verify');
    Route::post('/register/restart', [Portal::class, 'restartSignup'])->name('register.restart');
    Route::get('/forgot-password', [Portal::class, 'forgotForm'])->name('password.forgot');
    Route::post('/forgot-password', [Portal::class, 'forgotSend'])->middleware('throttle:3,10,guardian-forgot')->name('password.send');
    Route::post('/reset-password', [Portal::class, 'resetPassword'])->middleware('throttle:6,10,guardian-reset')->name('password.reset');
    Route::post('/forgot-password/restart', [Portal::class, 'restartRecovery'])->name('password.restart');

    Route::middleware(['auth:guardian', EnsureGuardianActive::class])->group(function (): void {
        Route::get('/', [Area::class, 'home'])->name('home');
        Route::post('/logout', [Portal::class, 'logout'])->name('logout');
        Route::get('/password', [Portal::class, 'passwordForm'])->name('password');
        Route::put('/password', [Portal::class, 'updatePassword'])->middleware('throttle:6,1,guardian-password')->name('password.update');
        Route::get('/reference/geography', [Area::class, 'geography'])->middleware('throttle:120,1,guardian-geo')->name('geography');

        Route::get('/family', [Area::class, 'family'])->name('family');
        Route::get('/family/children/{publicId}', [Area::class, 'child'])->name('family.child');
        Route::get('/changes/new', [Area::class, 'changeForm'])->name('changes.create');
        Route::post('/changes', [Area::class, 'storeChange'])->middleware('throttle:10,1,guardian-change')->name('changes.store');
        Route::post('/changes/{publicId}/respond', [Area::class, 'respondChange'])->middleware('throttle:10,1,guardian-change')->name('changes.respond');

        Route::get('/requests', [Area::class, 'requests'])->name('requests');
        Route::get('/requests/{publicId}', [Area::class, 'request'])->name('requests.show');
        Route::post('/appeals', [Area::class, 'storeAppeal'])->middleware('throttle:5,1,guardian-appeal')->name('appeals.store');

        Route::get('/aid', [Area::class, 'aid'])->name('aid');

        Route::get('/notifications', [Area::class, 'notifications'])->name('notifications');
        Route::get('/notifications/{id}/open', [Area::class, 'openNotification'])->whereNumber('id')->name('notifications.open');
        Route::post('/notifications/read-all', [Area::class, 'readAll'])->name('notifications.read-all');

        Route::get('/profile', [Area::class, 'profile'])->name('profile');
        Route::post('/profile/phone-code', [Area::class, 'sendPhoneCode'])->middleware('throttle:3,10,guardian-otp')->name('profile.phone-code');
        Route::post('/profile/verify-phone', [Area::class, 'verifyPhone'])->middleware('throttle:10,10,guardian-otp-verify')->name('profile.verify-phone');
        Route::put('/profile/preferences', [Area::class, 'preferences'])->name('profile.preferences');

        // Family registration request: draft, autosave, attachments, submission (Registration\FamilyRegistrations).
        Route::post('/applications', [Portal::class, 'storeApplication'])->middleware('throttle:5,1,guardian-apply')->name('applications.store');
        Route::get('/registrations/{publicId}', [Portal::class, 'editRegistration'])->name('registrations.edit');
        Route::put('/registrations/{publicId}', [Portal::class, 'updateRegistration'])->middleware('throttle:40,1,guardian-save')->name('registrations.update');
        Route::post('/registrations/{publicId}/submit', [Portal::class, 'submitRegistration'])->middleware('throttle:5,1,guardian-submit')->name('registrations.submit');
        Route::post('/registrations/{publicId}/documents', [Portal::class, 'uploadDocument'])->middleware('throttle:30,1,guardian-upload')->name('registrations.documents');
        Route::delete('/registrations/{publicId}/documents/{document}', [Portal::class, 'removeDocument'])->name('registrations.documents.remove');
        Route::post('/registrations/{publicId}/discard', [Portal::class, 'discardRegistration'])->name('registrations.discard');
    });
});
