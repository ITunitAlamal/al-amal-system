# Orphans and sponsorships UX audit — initial implementation batch

This records the initial batch. The [V2 simplification report](orphans-ux-v2.md) supersedes its navigation layout and validation status.

## Scope and implementation plan

Read-only code and route audit, followed by a zero-schema navigation batch. No database inspection of beneficiary records, imports, migrations, seeds, organization changes, or financial writes. Existing uncommitted work was preserved. Installed backend versions: Laravel 13.36.0, Inertia Laravel 3.0.0; package.json declares Inertia Vue ^3.9.1, Vue ^3.5.44, Tailwind ^4.3.3. No `.ai/rules` directory exists in this checkout.

1. Map screens, access services, data sources and reuse points.
2. Group the existing registry queues, persist selection in the URL, explain bounded queue counts, and preserve results context between household and child pages.
3. Verify URL behavior and production compilation. Backend search, relationship policy, scoped dashboard and export work remain separate batches.

## Current screen inventory and route mapping

These are existing routes, not proposed endpoints. `php artisan route:list --path=orphans --method=GET --except-vendor` reports 30 GET routes, including document links, staging data and export endpoints.

| Screen / function | Existing route | Main controller / source |
| --- | --- | --- |
| Registry and nine queues | `/orphans?tab=…` | RegistryController@index; household paginator, applications, changes, visits, alert/followup/document services |
| Household / child | `/orphans/households/{publicId}`, `/orphans/children/{publicId}` | RegistryController; OrphanHousehold, OrphanChild, parents, documents, guardianships |
| Guardian registration reviews | `/admin/orphans/guardians`, `/{publicId}` | GuardianReviewController; guardian applications and per-child verification |
| Delegations | `/admin/orphans/delegations` | OrphanDelegationController; OrphanAccess grants |
| Import batches | `/orphans/imports`, `/orphans/imports/batches` | RegistryImportController; RegistryImports |
| Staging and quality review | `/orphans/staging`, `/data/{section}`, `/rows/{id}`, `/guardians/{ref}`, `/households/{ref}` | RegistryStaging; source/normalized data and issue queues |
| Staging exports | `/orphans/staging/export/{format}` | RegistryImportController; existing throttled export path |
| Application / change | `/orphans/applications/{application}`, `/orphans/changes/{change}` | ApplicationController; separate workflow services |
| Visits / visit plans | `/orphans/visits/{visit}`, `/orphans/visit-plans`, `/{publicId}` | VisitController, FollowupController |
| Social scoring | `/orphans/scoring`, `/{ruleset}` | VisitController; ScoringRulesets / ScoringEngine |
| Sponsorships | `/orphans/sponsorships`, `/{publicId}` | SponsorshipController; Sponsorships, Contributions, Accounts |
| Sponsors / agreements | `/orphans/sponsors/{publicId}`, `/orphans/agreements/{publicId}`, `/print` | SponsorProgramController; SponsorAgreements; existing print Blade |
| Assistance | `/orphans/assistance/{publicId}` | SponsorshipController; OrphanAssistance |
| Settings | `/orphans/settings` | SettingsController; OrphanForms, master data |
| Relief | `/relief/campaigns`, `/{publicId}`, `/{publicId}/rulesets`, `/relief/exceptions/{exception}`, `/relief/devices` | CampaignController; ReliefAccess, CampaignRosters, ReliefBatches, FieldSync |
| Beneficiary portal | `/guardian`, application creation/retry/resubmission | GuardianPortalController; separate guardian auth and own applications |

## Access matrix from existing code

| Actor | Record access | Actions / boundaries |
| --- | --- | --- |
| Active system admin | All department capabilities, audited detail reads | Administrative workflow decisions retain separate restrictions |
| Appointed / currently acting department head | All department capabilities | Acting appointment must have valid dates |
| Executive director | `view`, `sensitive` | Decision paths are separate; no general records editing grant |
| Active department staff | Explicit `view` plus requested capability | `records`, `verify`, `review`, `visits`, `sponsorships`, `campaigns`, `imports`, etc. |
| Campaign delegate | Named, dated, non-revoked membership | Campaign scope; pseudonyms only if permitted; membership grants no child-file capability |
| Guardian | Own portal applications and children through guardianship relation | Staff guard remains separate; inactive guardian rejected |
| Other / inactive user | No OrphanAccess capability | Server denies department operations |

Sources: OrphanAccess, ReliefAccess, routes/guardians.php, GuardianPortalController, Guardian::children. This is a code-derived matrix, not a completed endpoint penetration test. Registry `view` currently means department-wide read access; do not describe it as caseworker assignment scoping. Portal `Guardian::children` filters end dates; stronger invariants concerning approval and future start dates need dedicated security tests before claiming full policy compliance.

## Data model and query observations

- Individual children reference a household. Household parents are linked independently. `primary_guardian_id` supplies a primary contact, while child-level `orphan_guardianships` preserve dated relationships/history. The primary guardian must not be presented as proof of legal authority.
- The current household editor exposes one primary guardian, not a complete competing-claims explorer. Existing registration child verification and guardianship history should be audited before adding any relationship schema.
- Registry list uses server pagination (25) and stable descending household ID ordering. Per-row child and emergency counts are correlated SQL subqueries, not browser-side totals.
- Applications, changes and visits are capped at 50; assigned visits at 100. Loaded queue lengths were displayed like totals. The new UI removes those misleading total labels and explicitly describes bounded work queues.
- Registry search covers household/guardian references, child file numbers and an exact child national-ID hash. It does **not** currently search guardian national IDs or normalized Arabic names. Household names are encrypted; a database name-search design needs privacy/index review. Do not decrypt the whole registry per keystroke.
- Staging already has ArabicText normalization and source/normalized review infrastructure. Reuse it where appropriate; do not silently replace canonical names or merge people.
- HouseholdNeeds, SponsorshipWaitlist, ScoringEngine, sponsorship accounts, assistance and relief workflows already exist. Do not implement parallel scoring, ledger, inventory or delivery engines.

## Navigation before and after

Before: sidebar `الأيتام والأوصياء` has flat links for registry, staging, imports, guardian requests, delegations, sponsorships, relief campaigns, field devices and settings. Registry has nine local-state tabs. Household has seven sections; child has six conditional tabs. Opening a household loses the explicit list return context.

After this batch: sidebar and all bookmarked routes remain unchanged. Registry work queues have three visible groups:

- `السجلات`: `ملفات الأسر`.
- `المتابعة الاجتماعية`: `مهامي الميدانية`, `متابعة الحالات`, `الزيارات`, `الإحالات المفتوحة`.
- `المراجعات`: `طلبات الاعتماد`, `طلبات التعديل`, `حالات الطوارئ`, document renewals.

Selection uses existing `?tab=` URLs and survives reload/back navigation. Search/status/page are retained when switching queues. Household list → household → child carries an allowlisted `return_to`; child and household show `العودة إلى النتائج`. Child → household is one action. Exact scroll restoration is not asserted: filters/page are preserved, while browser/Inertia scroll behavior still needs interactive verification. No redirects are needed because no existing routes were removed.

Longer-term sidebar target: overview, records, sponsorships, relief, social followup, reviews, reports. Add groups only when real authorized destinations exist. Settings/delegations remain administration. Do not introduce empty dashboards or dead report links.

## Changed files and reuse

- `resources/js/Pages/Orphans/Index.vue`: grouped navigation, URL selection, accessible filter labels/reset, accurate queue-count explanation and list results count.
- `resources/js/Pages/Orphans/Household.vue`, `Child.vue`: shared record context and propagation through household/child links.
- `resources/js/Components/Orphans/RecordContext.vue`: labeled breadcrumb/return navigation.
- `resources/js/Support/orphanNavigation.js`: allowlisted registry query state; rejects external/unrelated return destinations and strips unknown parameters.
- `tests/orphanNavigation.test.mjs`: return context, leading zeros, Arabic query preservation, queue switching and hostile destination cases.
- This report.

Reused AppLayout, PageHeader, StatusBadge, AppIcon and existing icon paths, Inertia links/router, current Tailwind utilities and controller authorization. No new endpoint, dependency, policy, status, score or database column.

## Verification and remaining batches

Validation: `node --test tests/orphanNavigation.test.mjs tests/navigationAccessibility.test.mjs tests/listExperience.test.mjs` passed all 13 tests (six new navigation cases). `npm run build` passed; Vite reports unresolved existing font URLs, left for runtime resolution. These verify navigation logic and Vue compilation, not backend search correctness, endpoint authorization or browser accessibility. Arabic query preservation is not normalized name matching. Nine-digit ID preservation is not proof that all entity ID searches work. No PHP files changed, so PHP formatting and database tests were not run for this batch.

Next: test existing staff/guardian/campaign negative access boundaries with isolated synthetic fixtures; fix any confirmed disclosure before expanding search. Then implement authorized entity search and separately paginated queues, followed by relationship explorer, approved-rules decision panels and filtered audited exports. No backend permission or export tests were added in this frontend batch.

No production-like dataset was accessed; there are no measured 48k-record timings, query plans or browser screenshots. Responsive groups use one column on small screens and three above the existing `sm` breakpoint; navigation uses semantic links, visible Arabic labels, current-page state and focus outlines. Keyboard, RTL/LTR, screen-reader and mobile viewport checks remain browser acceptance work.

No migration is proposed in this batch. If searchable indexes or claim schema are needed, first provide exact table/column/index impact, backup procedure, deployment locks, rollback and approval checkpoint. No live migration, import, merge or data rewrite is authorized by this implementation.
