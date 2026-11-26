# V3.1 test results

## Replacement-edition increment — 2026-10-27

- Isolated server verified again: `aims_correspondence_test_20261022`, 127.0.0.1:55440. A full custom-format test-only dump was saved before schema testing. No live migration/write.
- New migration tested within rollback transactions. The older fixture lacks `workflow_definitions.is_confidential`; setup adds the existing 2026_11_15 migration's column shape inside the test transaction before applying the edition migration. After the suite: users=0, letters=0, `correspondence_editions` absent (DDL rolled back).
- Final post-format PHP regression: `AIMS_CORRESPONDENCE_TEST_PORT=55440 php vendor/bin/phpunit tests/Feature/OfficialCorrespondenceTest.php tests/Feature/CorrespondenceActionValidationTest.php --no-progress`: 45 tests passed, 597 assertions, exit 0, 111.554 s.
- Node: 12 tests passed, exit 0, including new edition-comparison SSR escaping/board-pending controls test.
- Vite: build succeeded, exit 0, 750 modules, 27.72 s. Existing runtime font-resolution/plugin-time notices remain; no physical/visual rendering claims.
- Pint: scoped `--dirty --format agent` completed successfully, formatting eight changed files. Existing concurrent presentation/visibility edits were preserved.
- Authenticated edition routes resolve through the existing correspondence route group. `git diff --check`: no errors, only pre-existing unrelated CRLF notice.

No board authority/quorum inferred, no digital signature claim, no production migration/deployment. The semantic claim that an implementation-letter edit leaves board meaning intact is a recorded proposer/executive attestation, not an automated text-analysis guarantee.

## Environment and safety

- Boost `application-info`, `search-docs`, read-only schema/policy metadata: succeeded this session.
- Isolated PostgreSQL database verified by actual `current_database()` and `inet_server_port()`: `aims_correspondence_test_20261022`, loopback port 55440. Existing 55439 startup failed socket binding; same isolated cluster restarted on 55440. No production fallback.
- Tests use `AIMS_CORRESPONDENCE_TEST_PORT=55440`; allowlist only 55439/55440 plus exact database/host assertions before writes. Synthetic users/letters rolled back.
- Post-suite read-only verification: users = 0, official_correspondences = 0, correspondence_tasks = 0, workflow_event_attachments = 0 in the isolated database.
- Test-only schema backup: local temporary schema-only pg_dump (exit 0). Middleware test adds missing legacy-fixture navigation columns inside its rollback transaction, not on the live schema.

## Executed checks

Initial regression: 30 tests passed, 371 assertions (stronger safety guard changes assertion count), exit 0.

New tests run individually: reopen/reassign/completion passed in suite; branching prevention, issue-year rollback, self-reopen/confidentiality, approved escalation, new mailboxes and middleware passed. Initial middleware failure exposed missing fixture `navigation_items.route_params`; resolved transactionally together with `audience_roles`. Inertia asset-version request header then corrected; representative integrated test passed (10 assertions).

`node --test tests/correspondencePrint.test.mjs tests/homePriorities.test.mjs tests/uiRecordActions.test.mjs`: 11 passed, exit 0. SSR escaping/source print assertions only; no browser.

Final checks:

| Command | Exit | Result |
|---|---|---|
| `$env:AIMS_CORRESPONDENCE_TEST_PORT='55440'; php vendor/bin/phpunit tests/Feature/OfficialCorrespondenceTest.php tests/Feature/CorrespondenceActionValidationTest.php --no-progress` | 0 | 39 passed, 496 assertions, 30.208 s |
| `php vendor/bin/pint --dirty --format agent` scoped to the seven changed PHP files listed in CHANGELOG | 0 | Passed after formatting |
| `npm run build` | 0 | Vite 8.3.4, 749 modules, 11.17 s |
| `git diff --check` | 0 | No whitespace errors; unrelated pre-existing CRLF notice for `toArray())` |

Build retained runtime font-resolution notices and plugin timing notices. Sample `public/fonts/noto-kufi-arabic-arabic.woff2` exists; physical font rendering was not tested. No frontend source changed after the successful build. Test and command outputs contained synthetic test identifiers only.

## Limits

No visual/physical print testing. No real external mail, production migration or employee data export. Parallel allocator test is not full concurrent HTTP issuance. Substantive board approvals and legal-hold lifecycle remain unimplemented; ordinary replacement-text coverage is listed in the latest increment above.
