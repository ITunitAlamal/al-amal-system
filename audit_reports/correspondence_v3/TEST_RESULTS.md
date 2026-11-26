# Verification — 2026-10-26

## Final results

| Command | Result |
| --- | --- |
| `php vendor/bin/phpunit tests/Feature/OfficialCorrespondenceTest.php tests/Feature/CorrespondenceActionValidationTest.php --no-progress` | 30 passed, 290 assertions; 21.612 seconds |
| `node --test tests/correspondencePrint.test.mjs tests/homePriorities.test.mjs tests/uiRecordActions.test.mjs` | 11 passed |
| `npm run build` | Passed; latest build 12.60 seconds |
| `php vendor/bin/pint --dirty --format agent app/Http/Controllers/CorrespondenceController.php app/Http/Middleware/HandleInertiaRequests.php app/Services/Correspondence/CorrespondenceAccess.php app/Services/Correspondence/CorrespondenceRegistry.php app/Services/Dashboard/PersonalWorkspace.php app/Console/Commands/CorrespondenceReconcile.php routes/web.php tests/Feature/OfficialCorrespondenceTest.php` | Passed/fixed scoped formatting; feature suite rerun afterward |
| `git diff --check` | No whitespace errors; an existing unrelated CRLF notice for `toArray())` remains |
| `php artisan route:list --path=correspondence --except-vendor --json` | 14 authenticated routes, including print |
| `php artisan migrate:status --no-interaction` | Read-only; current migrations reported applied; no production migration run |

## Isolation

PostgreSQL 18 on `127.0.0.1:55439`, database `aims_correspondence_test_20261022`; explicit test database, host and port checks before schema/data writes. Initial user/correspondence counts were zero. Synthetic factories and transaction rollback; `Storage::fake('local')`; HTTP stray-request prevention; PHPUnit array mail configuration. The sequence-contention test uses two independent PHP processes, explicit test connection settings, a synthetic sequence scope and independent cleanup. It exercises the existing allocator under a held database lock, not the entire HTTP issuance flow.

## Covered behavior

Existing tests: provenance and per-action file visibility through handoffs; idempotency and changed-payload conflict; route hierarchy/admin signing restrictions; read versus completion; MIME/extension/count/size rejection; failed second-upload rollback/cleanup; effective-dated assignment revocation; immutable issued body; confidential routing restrictions; lost-alert reconciliation; complete board-secretary/secretariat/executive/head workflow; bounded cross-head policy; acting-head expiry; correction file versions; draft file preservation and refusal.

New regression cases: mailbox date/status/actor/unread/overdue filtering, task deadline after forwarding, sent/referral and received history, pagination/scoped counts, official print without private action payloads, appendix denial and technical audit, drafts/withdrawals excluded from official print, no read side effect from print, dashboard/header expiry and cancelled-task counters, stale home-department denial and denied file guessing, one-step multi-file HTTP send and multi-file return, private deduplicated overdue reminders and expiry, and concurrent sequence allocation.

Vue tests render the official template through Vue SSR and assert escaped malicious text. CSS contract checks cover A4, pagination, hidden controls and page-number declaration. These are not UI snapshots or visual/browser tests.

## Failures encountered and resolved

- Baseline validation: 3 tests/20 assertions passed. Baseline correspondence: 15 of 18 passed; three errors due to missing `workflow_instances.held_at` in the historical isolated schema. Conditional setup of the existing hold migration fixed this; original 18 then passed.
- Initial new return test incorrectly used a JSON fragment assertion for array membership; corrected to a predicate and reran successfully.
- Existing JS cancellation test referenced obsolete `submit`; updated to current `send('withdraw')`, preserving cancellation/confirmation assertions.
- Direct header-counter test initially resolved the suite's middleware bypass double; instantiated the real middleware for that targeted closure test, then passed.
- Vite emits unresolved-at-build-time `/fonts/...` warnings and plugin timing notices. Build succeeds; a sampled referenced public font exists. Runtime font delivery and physical printing were not visually verified.

## Not claimed

No production smoke/write tests, real SMTP/Gmail/SMS, antivirus, digital signing, crash recovery, full HTTP load/concurrency benchmark, printer appearance or full-application test suite. Policy-dependent reopen/external registry/retention features remain open in OPEN_DECISIONS.md.

Continuation check: native Boost `application-info` and `search-docs` now succeed directly. The isolated PostgreSQL server is currently stopped; rerunning the database suite requires starting and verifying that same isolated environment. No application code changed after the passing final run, and no production fallback was used.
