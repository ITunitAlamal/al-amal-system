<?php

use App\Http\Controllers\Relief\CampaignController as Campaigns;
use App\Http\Middleware\ProtectOrphanResponses;
use Illuminate\Support\Facades\Route;

/*
 * Relief campaigns of the Sponsorships & Orphans department and the field devices used to distribute them. Each
 * service repeats its authorization (department capability, head office, executive role or a named-campaign
 * membership); the database enforces ownership, frozen rosters, executive-only exceptions and head-only assignment.
 */
Route::middleware(['web', 'auth', ProtectOrphanResponses::class])->prefix('relief')->name('relief.')->group(function () {
    Route::get('/campaigns', [Campaigns::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [Campaigns::class, 'store'])->middleware('throttle:20,1')->name('campaigns.store');
    Route::get('/campaigns/{publicId}', [Campaigns::class, 'show'])->name('campaigns.show');
    Route::put('/campaigns/{publicId}', [Campaigns::class, 'update'])->name('campaigns.update');
    Route::get('/campaigns/{publicId}/rulesets', [Campaigns::class, 'rulesets'])->name('campaigns.rulesets');
    Route::post('/campaigns/{publicId}/{action}', [Campaigns::class, 'action'])->middleware('throttle:60,1')
        ->whereIn('action', ['item', 'remove-item', 'submit', 'activate', 'suspend', 'resume', 'roster', 'roster-submit', 'exception', 'member', 'member-revoke',
            'batch', 'batch-revoke', 'batch-close', 'requeue', 'resolve', 'reconcile', 'close'])->name('campaigns.action');
    Route::get('/exceptions/{exception}', [Campaigns::class, 'exception'])->whereNumber('exception')->name('exceptions.show');
    Route::get('/devices', [Campaigns::class, 'devices'])->name('devices');
    Route::post('/devices/{device}/{action}', [Campaigns::class, 'deviceAction'])->whereNumber('device')->whereIn('action', ['approve', 'revoke', 'lost'])->name('devices.action');
});
