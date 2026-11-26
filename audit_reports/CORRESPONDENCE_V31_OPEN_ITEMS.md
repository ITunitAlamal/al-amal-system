# V3.1 open items and acceptance limits

V3.1 is PARTIAL, not fully accepted against the execution prompt.

## Policy-dependent / disabled

1. Incoming/outgoing external registry: supply canonical institution/issuer codes, direction taxonomy, effective date and mapping to historical sequences. Existing internal numbering retained without renumbering; no fictitious external channel.
2. Replacement text policy resolved by the user's 2026-10-27 instruction. Staff/head/executive chains are implemented using the existing Workflow engine, with immutable linked editions and approved-only effective print. Substantive board replacements remain pending because the available governance documents do not define their authorized approval process. The new additive migration is prepared but not deployed to production.
3. Reopen/reassign linked formal workflows: ordinary correspondence governance is implemented. Board workflow reset stage, owner/delegation rules and history projection require explicit governance; generic operations reject linked workflows.
4. Escalation: configurable and tested but no policy enabled in production. Institution supplies hours and approval reference. Recipients without existing record access receive nothing; do not grant blanket visibility to make escalation deliver.
5. Legal hold metadata and release authority: no lifecycle implemented. No issued-record deletion/purge exists; retention category/archive preserved. Destruction stays out of scope.

## Untested / external dependencies

- Full concurrent multi-request HTTP issuance and reassign races; process-kill/disk-crash recovery; production scale. Two-process allocator and stale revision conflicts are covered separately.
- Signed PDF archive, qualified signature, malware scanning/sanitization and Gmail/Workspace integration are absent. No external sends attempted.
- Physical print, visual pagination, screenshots and browser automation were not performed, as instructed.
- Full organization navigation regression: only representative middleware-integrated correspondence request. Legacy isolated schema needed two navigation column fixtures, rolled back after that test.
- Per-user sound behavior was not changed or newly validated in this increment.

`workflow_versions.change_note` is absent and unused by current code/migrations; no speculative repair. All changes are local; no production deployment or schema/data mutation.
