# Open decisions and limits

Historical V3 snapshot. The later V3.1 implementation resolves parts of items 2 and 9. The user supplied replacement-edition policy on 2026-10-27; ordinary replacement approval is now implemented in code using the existing Workflow engine. Substantive board replacements remain pending documented authority; the additive schema migration is not deployed to production. See `../CORRESPONDENCE_V31_GOVERNANCE_DECISIONS.md` and `../CORRESPONDENCE_V31_TEST_RESULTS.md` for current evidence.

1. External incoming/outgoing channels and authority/year/type numbering: no authoritative external-registry policy was supplied. External registry remains unavailable; no Gmail/Workspace integration is claimed.
2. Closed-letter reopening, reassignment after expiry, legal holds/retention destruction, and urgent escalation recipients need approved authority rules. Existing records are retained; no destruction or silent role grants were introduced.
3. Original official text remains immutable. Corrections are action notes and attachment versions; a new signed replacement-text edition and its approval chain are not implemented.
4. Technical admins retain existing audited inspection rights, including the new internal print appendix; they gain no official decision/signing authority. Restricting that existing access further needs institutional approval.
5. No malware-scanning service, digital-signature provider or real external mail service is configured/tested by this work. MIME/extension/size validation and private forced downloads are not a claim of antivirus scanning or PDF sanitization.
6. Print is code-managed with versioned letterhead policy. Browser page numbering, exact pagination and physical printer output are unverified because visual/browser tests are prohibited.
7. Two independent processes test the actual number allocator under contention. Full concurrent HTTP issuance, process-kill/filesystem-crash recovery and production-scale performance are not tested.
8. `workflow_versions.change_note` is absent in the live metadata inspected. No current migration was pending in migrate:status; avoid assuming historical reports still describe the active schema.
9. Existing tests bypass HandleInertiaRequests for most HTTP flows. The changed correspondence counter closure is tested directly; this is not full navigation or organization-wide regression coverage.

The former missing-skill, search-docs and stopped-test-database blockers were resolved for this implementation. Outstanding items above are not represented as completed V3 functionality.
