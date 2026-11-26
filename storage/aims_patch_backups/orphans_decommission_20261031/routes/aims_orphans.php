<?php

use App\Http\Controllers\Orphans\ApplicationController as Applications;
use App\Http\Controllers\Orphans\CaseAlertController as CaseAlerts;
use App\Http\Controllers\Orphans\FollowupController as Followups;
use App\Http\Controllers\Orphans\GuardianRegistryController as GuardianRegistry;
use App\Http\Controllers\Orphans\RegistryController as Registry;
use App\Http\Controllers\Orphans\RegistryImportController as RegistryImports;
use App\Http\Controllers\Orphans\SettingsController as Settings;
use App\Http\Controllers\Orphans\SponsorProgramController as Programs;
use App\Http\Controllers\Orphans\SponsorshipController as Sponsorships;
use App\Http\Controllers\Orphans\VisitController as Visits;
use App\Http\Middleware\ProtectOrphanResponses;
use Illuminate\Support\Facades\Route;

/*
 * Sponsorships & Orphans department: family files, children, documents, first-time activation (executive decision per
 * child), change requests, social visits and the general social score. Every service repeats its own authorization;
 * the menu only hides. Responses are marked private and never cached.
 */
Route::middleware(['web', 'auth', ProtectOrphanResponses::class])->prefix('orphans')->name('orphans.')->group(function () {
    Route::get('/', [Registry::class, 'index'])->name('index');

    // «استيراد الكشوف» and «تدقيق البيانات»: staging tier only (provisional source claims).
    Route::get('/imports', [RegistryImports::class, 'imports'])->name('imports.index');
    Route::get('/imports/batches', [RegistryImports::class, 'batches'])->name('imports.batches');
    Route::post('/imports', [RegistryImports::class, 'upload'])->middleware('throttle:5,1,orphan-imports')->name('imports.upload');
    Route::post('/imports/{publicId}/approve', [RegistryImports::class, 'approve'])->whereUuid('publicId')->middleware('throttle:10,1,orphan-imports')->name('imports.approve');
    Route::post('/imports/{publicId}/cancel', [RegistryImports::class, 'cancel'])->whereUuid('publicId')->middleware('throttle:10,1,orphan-imports')->name('imports.cancel');
    Route::post('/imports/{publicId}/retry', [RegistryImports::class, 'retry'])->whereUuid('publicId')->middleware('throttle:10,1,orphan-imports')->name('imports.retry');
    Route::get('/records', [RegistryImports::class, 'records'])->name('records');
    // «الأيتام والأوصياء» on the official registry (guardian-centred).
    Route::prefix('registry')->name('registry.')->group(function (): void {
        Route::get('/children', [GuardianRegistry::class, 'children'])->middleware('throttle:240,1,orphan-registry-read')->name('children');
        Route::get('/children/{id}', [GuardianRegistry::class, 'child'])->whereNumber('id')->middleware('throttle:120,1,orphan-registry-read')->name('child');
        Route::get('/guardians', [GuardianRegistry::class, 'guardians'])->middleware('throttle:240,1,orphan-registry-read')->name('guardians');
        Route::get('/guardians/{id}', [GuardianRegistry::class, 'guardian'])->whereNumber('id')->middleware('throttle:120,1,orphan-registry-read')->name('guardian');
        Route::get('/lookup', [GuardianRegistry::class, 'lookup'])->middleware('throttle:60,1,orphan-registry-lookup')->name('lookup');
        Route::post('/guardians', [GuardianRegistry::class, 'storeGuardian'])->middleware('throttle:30,1,orphan-registry-write')->name('guardians.store');
        Route::post('/children', [GuardianRegistry::class, 'storeChild'])->middleware('throttle:30,1,orphan-registry-write')->name('children.store');
        Route::post('/guardians/{id}/links', [GuardianRegistry::class, 'link'])->whereNumber('id')->middleware('throttle:30,1,orphan-registry-write')->name('links.store');
        Route::post('/links/{id}/decision', [GuardianRegistry::class, 'decide'])->whereNumber('id')->middleware('throttle:60,1,orphan-registry-write')->name('links.decide');
    });
    Route::get('/reports', [RegistryImports::class, 'reports'])->name('reports');
    Route::get('/aid', [RegistryImports::class, 'aid'])->name('aid');
    Route::get('/staging', [RegistryImports::class, 'staging'])->name('staging.index');
    Route::get('/staging/data/{section}', [RegistryImports::class, 'section'])->whereIn('section', ['dashboard', 'children', 'households', 'guardians', 'issues', 'categories', 'options'])->middleware('throttle:240,1,orphan-staging-read')->name('staging.section');
    Route::get('/staging/households/{ref}', [RegistryImports::class, 'household'])->where('ref', '[A-Za-z0-9_-]+')->name('staging.household');
    Route::get('/staging/guardians/{ref}', [RegistryImports::class, 'guardian'])->where('ref', '[A-Za-z0-9_-]+')->name('staging.guardian');
    Route::get('/staging/rows/{id}', [RegistryImports::class, 'row'])->whereNumber('id')->middleware('throttle:120,1,orphan-staging-read')->name('staging.row');
    Route::put('/staging/rows/{id}', [RegistryImports::class, 'correct'])->whereNumber('id')->middleware('throttle:60,1,orphan-staging-write')->name('staging.correct');
    Route::post('/staging/rows/{id}/decision', [RegistryImports::class, 'decide'])->whereNumber('id')->middleware('throttle:60,1,orphan-staging-write')->name('staging.decide');
    Route::post('/staging/issues/assign', [RegistryImports::class, 'assign'])->middleware('throttle:20,1,orphan-staging-write')->name('staging.assign');
    Route::post('/staging/issues/{issue}', [RegistryImports::class, 'settle'])->whereNumber('issue')->middleware('throttle:120,1,orphan-staging-write')->name('staging.settle');
    Route::post('/staging/aliases', [RegistryImports::class, 'alias'])->middleware('throttle:60,1,orphan-staging-write')->name('staging.alias');
    Route::get('/staging/export/{format}', [RegistryImports::class, 'export'])->whereIn('format', ['xlsx', 'csv', 'pdf', 'print'])->middleware('throttle:10,1,orphan-staging-export')->name('staging.export');
    Route::post('/households', [Registry::class, 'storeHousehold'])->middleware('throttle:20,1')->name('households.store');
    Route::get('/households/{publicId}', [Registry::class, 'showHousehold'])->name('households.show');
    Route::put('/households/{publicId}', [Registry::class, 'updateHousehold'])->name('households.update');
    Route::post('/households/{publicId}/guardian', [Registry::class, 'saveGuardian'])->name('households.guardian');
    Route::post('/households/{publicId}/parents/{role}', [Registry::class, 'saveParent'])->whereIn('role', ['father', 'mother'])->name('households.parents');
    Route::post('/households/{publicId}/children', [Registry::class, 'saveChild'])->name('households.children');
    Route::post('/households/{publicId}/payments', [Registry::class, 'storePayment'])->name('households.payments');
    Route::post('/households/{publicId}/application', [Registry::class, 'openApplication'])->name('households.application');
    Route::post('/households/{publicId}/visits', [Visits::class, 'plan'])->name('households.visits');
    Route::post('/households/{publicId}/emergency', [CaseAlerts::class, 'raiseEmergency'])->middleware('throttle:10,1')->name('households.emergency');
    Route::post('/emergencies/{flag}/clear', [CaseAlerts::class, 'clearEmergency'])->whereNumber('flag')->middleware('throttle:10,1')->name('emergencies.clear');
    Route::post('/households/{publicId}/referrals', [CaseAlerts::class, 'storeReferral'])->middleware('throttle:20,1')->name('households.referrals');
    Route::post('/referrals/{referral}/close', [CaseAlerts::class, 'closeReferral'])->whereNumber('referral')->middleware('throttle:20,1')->name('referrals.close');

    Route::get('/children/{publicId}', [Registry::class, 'showChild'])->name('children.show');
    Route::post('/children/{publicId}/education', [Registry::class, 'saveEducation'])->name('children.education');
    Route::post('/children/{publicId}/health', [Registry::class, 'saveHealth'])->name('children.health');
    Route::post('/children/{publicId}/score', [Visits::class, 'computeScore'])->middleware('throttle:20,1')->name('children.score');
    Route::post('/scores/{score}/override', [Visits::class, 'overrideScore'])->whereNumber('score')->name('scores.override');

    Route::post('/documents', [Registry::class, 'uploadDocument'])->middleware('throttle:30,1')->name('documents.store');
    Route::post('/documents/{document}/review', [Registry::class, 'reviewDocument'])->whereNumber('document')->name('documents.review');
    Route::get('/documents/{document}/link', [Registry::class, 'documentLink'])->whereNumber('document')->middleware('throttle:60,1')->name('documents.link');
    Route::get('/documents/file/{document}', [Registry::class, 'downloadDocument'])->middleware('signed')->name('documents.download');

    Route::get('/applications/{application}', [Applications::class, 'show'])->whereNumber('application')->name('applications.show');
    Route::post('/applications/{application}/submit', [Applications::class, 'submit'])->whereNumber('application')->name('applications.submit');
    Route::post('/application-children/{row}/{action}', [Applications::class, 'childAction'])->whereNumber('row')->whereIn('action', ['verify', 'recommend', 'decide'])->name('applications.child');

    Route::post('/changes', [Applications::class, 'storeChange'])->middleware('throttle:20,1')->name('changes.store');
    Route::get('/changes/{change}', [Applications::class, 'showChange'])->whereNumber('change')->name('changes.show');
    Route::post('/changes/{change}/start', [Applications::class, 'startChange'])->whereNumber('change')->name('changes.start');

    Route::get('/visits/{visit}', [Visits::class, 'show'])->whereNumber('visit')->name('visits.show');
    Route::put('/visits/{visit}', [Visits::class, 'report'])->whereNumber('visit')->name('visits.report');
    Route::post('/visits/{visit}/submit', [Visits::class, 'submit'])->whereNumber('visit')->name('visits.submit');
    Route::post('/visits/{visit}/reassign', [Visits::class, 'reassign'])->whereNumber('visit')->middleware('throttle:30,1')->name('visits.reassign');
    Route::post('/visits/{visit}/cancel', [Visits::class, 'cancel'])->whereNumber('visit')->middleware('throttle:20,1')->name('visits.cancel');

    Route::post('/households/{publicId}/followups', [Followups::class, 'schedule'])->middleware('throttle:20,1')->name('households.followups');
    Route::put('/households/{publicId}/followup-interval', [Followups::class, 'interval'])->middleware('throttle:20,1')->name('households.followup-interval');
    Route::post('/followups/{followup}/complete', [Followups::class, 'complete'])->whereNumber('followup')->middleware('throttle:30,1')->name('followups.complete');
    Route::post('/followups/{followup}/cancel', [Followups::class, 'cancel'])->whereNumber('followup')->middleware('throttle:20,1')->name('followups.cancel');
    Route::post('/followups/{followup}/reassign', [Followups::class, 'reassign'])->whereNumber('followup')->middleware('throttle:30,1')->name('followups.reassign');
    Route::get('/visit-plans', [Followups::class, 'plans'])->name('visit-plans.index');
    Route::post('/visit-plans', [Followups::class, 'storePlan'])->middleware('throttle:10,1')->name('visit-plans.store');
    Route::get('/visit-plans/{publicId}', [Followups::class, 'showPlan'])->name('visit-plans.show');
    Route::post('/visit-plans/{publicId}/visits', [Followups::class, 'assignPlan'])->middleware('throttle:10,1')->name('visit-plans.assign');

    Route::get('/scoring', [Visits::class, 'rulesets'])->name('scoring.index');
    Route::post('/scoring', [Visits::class, 'saveRuleset'])->name('scoring.save');
    Route::get('/scoring/{ruleset}', [Visits::class, 'ruleset'])->whereNumber('ruleset')->name('scoring.show');
    Route::post('/scoring/{ruleset}/submit', [Visits::class, 'submitRuleset'])->whereNumber('ruleset')->name('scoring.submit');

    Route::get('/sponsorships', [Sponsorships::class, 'index'])->name('sponsorships.index');
    Route::post('/sponsorships', [Sponsorships::class, 'store'])->middleware('throttle:30,1')->name('sponsorships.store');
    Route::get('/sponsorships/{publicId}', [Sponsorships::class, 'show'])->name('sponsorships.show');
    Route::put('/sponsorships/{publicId}', [Sponsorships::class, 'update'])->name('sponsorships.update');
    Route::post('/sponsorships/{publicId}/{action}', [Sponsorships::class, 'action'])->whereIn('action', ['submit', 'cancel', 'amend', 'pause', 'resume', 'end'])->name('sponsorships.action');
    Route::post('/sponsors', [Sponsorships::class, 'storeSponsor'])->middleware('throttle:30,1')->name('sponsors.store');
    Route::put('/sponsors/{sponsor}', [Sponsorships::class, 'updateSponsor'])->whereNumber('sponsor')->name('sponsors.update');
    Route::post('/contributions', [Sponsorships::class, 'storeContribution'])->middleware('throttle:30,1')->name('contributions.store');
    Route::post('/contributions/{contribution}/cancel', [Sponsorships::class, 'cancelContribution'])->whereNumber('contribution')->name('contributions.cancel');
    Route::post('/households/{publicId}/assistance', [Sponsorships::class, 'storeAssistance'])->middleware('throttle:20,1')->name('households.assistance');
    Route::get('/assistance/{publicId}', [Sponsorships::class, 'showAssistance'])->name('assistance.show');
    Route::put('/assistance/{publicId}', [Sponsorships::class, 'updateAssistance'])->name('assistance.update');

    Route::post('/households/{publicId}/waitlist', [Programs::class, 'addToWaitlist'])->middleware('throttle:20,1')->name('households.waitlist');
    Route::post('/waitlist/{entry}/remove', [Programs::class, 'removeFromWaitlist'])->whereNumber('entry')->middleware('throttle:20,1')->name('waitlist.remove');
    Route::post('/households/{publicId}/need', [Programs::class, 'assessNeed'])->middleware('throttle:20,1')->name('households.need');
    Route::post('/agreement-templates', [Programs::class, 'storeTemplate'])->middleware('throttle:20,1')->name('agreement-templates.store');
    Route::put('/agreement-templates/{template}', [Programs::class, 'updateTemplate'])->whereNumber('template')->name('agreement-templates.update');
    Route::post('/agreement-templates/{template}/{action}', [Programs::class, 'templateAction'])->whereNumber('template')->whereIn('action', ['version', 'activate', 'retire'])->name('agreement-templates.action');
    Route::post('/agreements', [Programs::class, 'storeAgreement'])->middleware('throttle:20,1')->name('agreements.store');
    Route::get('/agreements/{publicId}', [Programs::class, 'showAgreement'])->name('agreements.show');
    Route::get('/agreements/{publicId}/print', [Programs::class, 'printAgreement'])->middleware('throttle:30,1')->name('agreements.print');
    Route::put('/agreements/{publicId}', [Programs::class, 'updateAgreement'])->name('agreements.update');
    Route::post('/agreements/{publicId}/{action}', [Programs::class, 'agreementAction'])->whereIn('action', ['lines', 'submit', 'sign', 'renew', 'close'])->middleware('throttle:20,1')->name('agreements.action');
    Route::post('/funds', [Programs::class, 'storeFund'])->middleware('throttle:10,1')->name('funds.store');
    Route::post('/funds/{fund}/close', [Programs::class, 'closeFund'])->whereNumber('fund')->middleware('throttle:10,1')->name('funds.close');
    Route::get('/sponsors/{publicId}', [Programs::class, 'showSponsor'])->name('sponsors.show');
    Route::post('/sponsors/{publicId}/contacts', [Programs::class, 'storeContact'])->middleware('throttle:30,1')->name('sponsors.contacts');
    Route::post('/sponsor-contacts/{contact}/done', [Programs::class, 'closeContactFollowUp'])->whereNumber('contact')->name('sponsor-contacts.done');

    Route::get('/settings', [Settings::class, 'show'])->name('settings');
    Route::put('/settings/policy', [Settings::class, 'updatePolicy'])->name('settings.policy');
    Route::post('/settings/forms', [Settings::class, 'publishFormVersion'])->name('settings.forms');
    Route::put('/settings/requirements/{requirement}', [Settings::class, 'updateRequirement'])->whereNumber('requirement')->name('settings.requirements');
    Route::put('/settings/retention/{policy}', [Settings::class, 'updateRetention'])->whereNumber('policy')->name('settings.retention');
});
