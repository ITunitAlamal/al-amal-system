# Correspondence V3.1 baseline

Inspected 2026-10-26, active checkout. Existing work is predominantly untracked; Git HEAD is not a reliable V3 comparison. Preserve all pre-existing files. File inventory is recorded below before implementation.

| Requirement | Baseline evidence | Status / concrete gap |
|---|---|---|
| Authenticated endpoints | `routes/web.php`, `php artisan route:list --path=correspondence` (14 routes) | Existing controller/service system retained |
| Record and step access | `CorrespondenceAccess::visible`, `accessQuery`, `actionVisible` | Scoped grants, expiry and audited technical inspection exist |
| Immutable issuance / numbering | `CorrespondenceRegistry::draft`, `act`, `number`; registry migration | Unique reference, locked year/department/type sequence; issued body cannot be edited; no document editions |
| Handoffs / tasks | `CorrespondenceRegistry::assign`, `CorrespondenceWorkflow`, `TransactionTracker` | Atomic single task projection and real board workflow; no independent reassignment/reopen |
| Attachments | `CorrespondenceDocuments::validate/store`, `CorrespondenceController::download` | Private per-action immutable files, hash/version; `complete` explicitly rejects files |
| Archive | `CorrespondenceRegistry::act`, `CorrespondenceController::show` | Service supports archive after completion; UI does not expose archive |
| UI / print | `resources/js/Pages/Correspondence/{Show,Print,Index}.vue` | Existing RTL screens, official A4 print; no visual verification |
| Notifications | `CorrespondenceReconcile`, `NotificationCenter` | Generic deduplicated overdue notice; no approved escalation timing |
| Middleware | `OfficialCorrespondenceTest::setUp` | Most HTTP tests bypass HandleInertiaRequests; integration gap |
| Schema anomaly | Boost read-only information_schema query; `rg change_note app database resources tests` | Column absent; zero runtime/migration references, no repair justified |
| Live policy metadata | Boost read-only policy-key/version/date query | Settings v2 has approval; cross-head exception expired 2026-10-24; no governance policy kinds |

Boost application-info and search-docs succeeded this session. Laravel 13.36.0, Inertia Laravel 3, Vue adapter declared ^3.9.1. AGENTS and Inertia/Laravel/testing skills loaded; `.ai/rules` absent. No production writes or schema changes authorized by this execution.

## Governance boundaries

V3.1 request supplies executive-only reopening and scoped reassignment design. Existing formal board workflow must not be bypassed by generic actions. Existing confidential cross-department expansion denial remains. No global visibility is granted to executive/head roles. Existing technical-admin audited inspection is retained without signing authority. External numbering/channel mapping and escalation timings are not supplied. No destructive retention operation is introduced.

## Test environment

Existing isolated cluster: PostgreSQL 18, database `aims_correspondence_test_20261022`. Initial connection on 127.0.0.1:55439 failed; server startup reported socket permission denied. No production fallback. Test outcomes and final connection verification belong in TEST_RESULTS.

Resolved for this run by starting the same isolated cluster on 127.0.0.1:55440, verifying server-reported database/port, and using a strict test-only port allowlist. Final regression: 39 tests / 496 assertions passed. Detailed limits remain in OPEN_ITEMS.

## Pre-existing tracked changes

AGENTS.md, CLAUDE.md, IMPLEMENTATION_PLAN.md; CreateSystemAdmin; Admin/DepartmentController; AuthController; DashboardController; Department/User models; AppServiceProvider; bootstrap/app.php; composer.json/lock; config/app.php, auth.php, services.php; package.json; resources/js/app.js; auth/login.blade.php; routes/console.php, web.php; vite.config.js; deleted default tests/TestCase.php and example tests; pre-existing `toArray())`. Correspondence services/controllers/pages/tests and prior reports are already untracked. No unrelated baseline files will be reset or reformatted.
