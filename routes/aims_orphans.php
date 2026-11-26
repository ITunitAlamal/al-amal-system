<?php

use App\Http\Controllers\Orphans\AidController as Aid;
use App\Http\Controllers\Orphans\CampaignController as Campaign;
use App\Http\Controllers\Orphans\CaseStudyController as CaseStudy;
use App\Http\Controllers\Orphans\HomeController;
use App\Http\Controllers\Orphans\ImportController as Import;
use App\Http\Controllers\Orphans\RegistrationsController as Registrations;
use App\Http\Controllers\Orphans\RegistryController as Registry;
use App\Http\Controllers\Orphans\ReportsController as Reports;
use App\Http\Controllers\Orphans\ReviewsController as Reviews;
use App\Http\Controllers\Orphans\SettingsController as Settings;
use App\Http\Middleware\ProtectOrphanResponses;
use Illuminate\Support\Facades\Route;

/*
 * «شؤون الأيتام والكفالات» (AIMS 2.0). Six menu entries; every action authorizes in the controller/service
 * (OrphanAccess), never by a hidden button. Responses carry no-store headers (sensitive child data).
 */
Route::middleware(['auth', ProtectOrphanResponses::class])->prefix('orphans')->name('orphans.')->group(function (): void {
    Route::get('/', HomeController::class)->name('home');

    Route::prefix('registry')->name('registry.')->controller(Registry::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/preview/{type}/{publicId}', 'preview')->name('preview');
        Route::get('/duplicates', 'duplicates')->middleware('throttle:60,1,orphans-dup')->name('duplicates');
        Route::get('/find-orphans', 'findOrphans')->middleware('throttle:90,1,orphans-find')->name('find-orphans');
        Route::get('/find-guardians', 'findGuardians')->middleware('throttle:90,1,orphans-find')->name('find-guardians');
        Route::get('/guardians/create', 'createGuardian')->name('guardians.create');
        Route::post('/guardians', 'storeGuardian')->name('guardians.store');
        Route::get('/guardians/{publicId}', 'guardian')->name('guardian');
        Route::put('/guardians/{publicId}', 'updateGuardianRecord')->name('guardian.update');
        Route::get('/orphans/create', 'createOrphan')->name('orphans.create');
        Route::post('/orphans', 'storeOrphan')->name('orphans.store');
        Route::get('/orphans/{publicId}', 'orphan')->name('orphan');
        Route::put('/orphans/{publicId}/file', 'updateFile')->name('orphan.file');
        Route::post('/orphans/{publicId}/guardianships', 'addGuardianship')->name('orphan.guardianships');
        Route::post('/orphans/{publicId}/parents/{relation}', 'linkParent')->whereIn('relation', ['father', 'mother'])->name('orphan.parent');
        Route::post('/orphans/{publicId}/education', 'addEducation')->name('orphan.education');
        Route::post('/orphans/{publicId}/assessments', 'addAssessment')->name('orphan.assessments');
        Route::post('/guardianships/{guardianship}/decide', 'decideGuardianship')->whereNumber('guardianship')->name('guardianship.decide');
        Route::post('/guardianships/{guardianship}/replace', 'replaceGuardianship')->whereNumber('guardianship')->name('guardianship.replace');
        Route::post('/guardianships/{guardianship}/end', 'endGuardianship')->whereNumber('guardianship')->name('guardianship.end');
        Route::put('/persons/{publicId}', 'updatePerson')->name('person.update');
        Route::post('/persons/{publicId}/reveal-id', 'revealId')->middleware('throttle:30,1,orphans-reveal')->name('person.reveal');
        Route::post('/persons/{publicId}/verify-id', 'verifyId')->name('person.verify');
        Route::post('/persons/{publicId}/status', 'recordStatus')->name('person.status');
        Route::post('/persons/{publicId}/addresses', 'addAddress')->name('person.addresses');
        Route::post('/persons/{publicId}/economic', 'addEconomic')->name('person.economic');
        Route::post('/persons/{publicId}/care', 'addCare')->name('person.care');
        Route::post('/persons/{publicId}/household', 'household')->name('person.household');
        Route::post('/persons/{publicId}/documents', 'uploadDocument')->name('person.documents');
        Route::post('/addresses/{address}/close', 'closeAddress')->whereNumber('address')->name('address.close');
        Route::post('/care/{record}/close', 'closeCare')->whereNumber('record')->name('care.close');
        Route::post('/assessments/{assessment}', 'completeAssessment')->whereNumber('assessment')->name('assessment.complete');
        Route::get('/documents/{publicId}', 'document')->name('document');
        Route::post('/documents/{publicId}/review', 'reviewDocument')->name('document.review');
    });

    // «دراسة الحالة» of a household (follow-up stage, no menu entry: opened from the household in the orphan profile).
    Route::controller(CaseStudy::class)->name('case-studies.')->group(function (): void {
        Route::get('/households/{household}/case-study/{study?}', 'show')->name('show');
        Route::post('/households/{household}/case-study', 'store')->name('store');
        Route::put('/case-studies/{study}', 'update')->name('update');
        Route::post('/case-studies/{study}/submit', 'submit')->name('submit');
        Route::post('/case-studies/{study}/{decision}', 'decide')->whereIn('decision', ['review', 'return'])->middleware('throttle:30,1')->name('decide');
    });

    Route::prefix('registrations')->name('registrations.')->controller(Registrations::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{publicId}', 'edit')->name('edit');
        Route::put('/{publicId}', 'update')->name('update');
        Route::post('/{publicId}/submit', 'submit')->name('submit');
        Route::post('/{publicId}/documents', 'upload')->name('documents');
        Route::delete('/{publicId}/documents/{document}', 'removeDocument')->name('documents.remove');
        Route::post('/{publicId}/discard', 'discard')->name('discard');
    });

    Route::prefix('reviews')->name('reviews.')->controller(Reviews::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/appeals', 'storeAppeal')->name('appeals.store');
        Route::post('/appeals/{publicId}', 'decideAppeal')->name('appeals.decide');
        Route::get('/changes', 'changes')->name('changes');
        Route::post('/changes/{publicId}', 'decideChange')->name('changes.decide');
        Route::post('/{publicId}/account-tools', 'accountTools')->middleware('throttle:10,1,orphans-account-tools')->name('account-tools');
        Route::get('/{publicId}', 'show')->name('show');
        Route::post('/{publicId}/assign', 'assign')->name('assign');
        Route::post('/{publicId}/checklist', 'checklist')->name('checklist');
        Route::post('/{publicId}/account', 'account')->name('account');
        Route::post('/{publicId}/children/{child}', 'child')->whereNumber('child')->name('child');
        Route::post('/{publicId}/completion', 'completion')->name('completion');
        Route::post('/{publicId}/reject', 'reject')->name('reject');
        Route::post('/{publicId}/reveal-id', 'revealApplicantId')->middleware('throttle:30,1,orphans-reveal')->name('reveal');
    });

    Route::prefix('aid')->name('aid.')->controller(Aid::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/sponsors', 'storeSponsor')->name('sponsors.store');
        Route::post('/sponsorships', 'storeSponsorship')->name('sponsorships.store');
        Route::get('/sponsorships/{publicId}', 'show')->name('sponsorship');
        Route::post('/sponsorships/{publicId}', 'sponsorshipAction')->name('sponsorship.action');
        Route::post('/assistance', 'storeAssistance')->name('assistance.store');
        Route::post('/assistance/{publicId}', 'decideAssistance')->name('assistance.decide');
        Route::post('/benefits', 'storeBenefit')->name('benefits.store');
        Route::post('/benefits/{publicId}/reverse', 'reverseBenefit')->name('benefits.reverse');
    });

    Route::prefix('campaigns')->name('campaigns.')->controller(Campaign::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'wizard')->name('create');
        Route::post('/', 'saveBasics')->name('store');
        Route::get('/{publicId}', 'show')->name('show');
        Route::get('/{publicId}/wizard', 'wizard')->name('wizard');
        Route::put('/{publicId}/basics', 'saveBasics')->name('basics');
        Route::put('/{publicId}/targeting', 'saveTargeting')->name('targeting');
        Route::put('/{publicId}/rules', 'saveRules')->name('rules');
        Route::post('/{publicId}/simulate', 'simulate')->middleware('throttle:10,1,orphans-simulate')->name('simulate');
        Route::post('/{publicId}/draft', 'draft')->name('draft');
        Route::get('/{publicId}/candidates/{candidate}', 'candidate')->whereNumber('candidate')->name('candidate');
        Route::post('/{publicId}/lists/{list}/edit', 'editList')->name('list.edit');
        Route::post('/{publicId}/lists/{list}', 'listAction')->name('list.action');
        Route::post('/{publicId}/changes', 'requestChange')->name('changes');
        Route::post('/{publicId}/members', 'member')->name('members');
        Route::delete('/{publicId}/members/{member}', 'revokeMember')->whereNumber('member')->name('members.revoke');
        Route::post('/{publicId}/stock', 'stock')->name('stock');
        Route::post('/{publicId}/deliveries', 'deliver')->name('deliver');
        Route::post('/{publicId}/deliveries/{delivery}/correct', 'correct')->name('delivery.correct');
        Route::post('/{publicId}/duplicates/{alert}', 'duplicate')->whereNumber('alert')->name('duplicate');
        Route::post('/{publicId}/batches', 'batch')->name('batches');
        Route::post('/{publicId}/batches/{batch}/revoke', 'revokeBatch')->name('batches.revoke');
        Route::post('/{publicId}/field-events/{event}', 'resolveEvent')->whereNumber('event')->name('field-events.resolve');
        Route::post('/{publicId}/reconcile', 'reconcile')->name('reconcile');
        Route::post('/{publicId}/close', 'close')->name('close');
        Route::post('/{publicId}/portal', 'portal')->name('portal');
    });

    Route::prefix('import')->name('import.')->controller(Import::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:20,1,orphans-import')->name('store');
        Route::get('/{publicId}', 'show')->name('show');
        Route::post('/{publicId}/mapping', 'mapping')->name('mapping');
        Route::post('/{publicId}/step', 'step')->name('step');
        Route::post('/{publicId}/rows/{row}/correct', 'correct')->whereNumber('row')->name('correct');
        Route::post('/{publicId}/decide', 'decide')->name('decide');
        Route::post('/{publicId}/approve', 'approve')->name('approve');
        Route::post('/{publicId}/cancel', 'cancel')->name('cancel');
        Route::post('/{publicId}/resume', 'resume')->name('resume');
        Route::post('/{publicId}/reanalyze', 'reanalyze')->name('reanalyze');
        Route::get('/{publicId}/rows/{row}', 'row')->whereNumber('row')->name('row');
        Route::get('/{publicId}/issues.csv', 'errors')->name('errors');
    });

    Route::get('/reports', [Reports::class, 'index'])->name('reports');
    Route::get('/reports/export', [Reports::class, 'export'])->middleware('throttle:20,1,orphans-export')->name('reports.export');

    Route::prefix('settings')->name('settings.')->controller(Settings::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::put('/', 'update')->name('update');
        Route::put('/import-policy', 'importPolicy')->name('import_policy');
        Route::post('/capabilities', 'grant')->name('grant');
        Route::delete('/capabilities/{grant}', 'revoke')->whereNumber('grant')->name('revoke');
        Route::post('/devices/{device}', 'device')->whereNumber('device')->name('device');
    });
});
