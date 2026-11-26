# V3.1 governance decisions

## Replacement-edition policy adopted 2026-10-27

The user's explicit policy supersedes the earlier replacement-text approval blocker below. Implemented in `CorrespondenceEditions`, `CorrespondenceEditionWorkflow`, and migration `2026_11_21_000001_create_correspondence_editions_table.php` using existing versioned Workflow definitions/instances/events/participants and RBAC.

- Staff origin: department-head review -> executive approval.
- Department-head origin: Secretariat registration/formal check -> executive approval.
- Executive origin: Secretariat registration/formal check -> approval by the issuing executive. This explicit self-approval exception does not apply to other origins.
- Origin is taken from the historical issue action's authority snapshot, never a submitted role string. Missing historical authority fails closed.
- Board-issued text, and a substantive replacement of a board-linked implementation letter: separate pending board workflow with no approval transition. The checkbox cannot downgrade a board-issued text.
- Non-substantive board-linked implementation letter: ordinary origin-based chain; final approval transition explicitly records the executive's attestation that board substance is unchanged. This is an accountable human judgment, not automated semantic verification.
- Required stages cannot be skipped through another controller or a shortcut workflow transition; subject hooks enforce the policy. Secretariat registration is never final approval.
- Every proposal is an immutable text snapshot, reason and before/after differences, linked to the original and last approved predecessor. Rejected versions, approvals and private files remain retained. Only a completed approval publishes the replacement; original body/reference remain unchanged.

Available DOCX files were inspected for board amendment, delegation and quorum provisions: executive document, SRS, HR, accounts, rentals/assets, sponsorship/orphans and residential care. No specific authorized substantive board-amendment process was found. No quorum/delegate/signing authority was invented.

Deployment: additive migration prepared, not applied to production. Test-only full dump taken before schema testing. Migration runs inside guarded disposable-test transactions and rolls back with fixtures. Production rollout needs a verified database backup and application of this single reviewed migration after its existing prerequisite migrations. Code rollback must retain the new records/workflow histories; `down()` refuses destructive removal. Until migration is deployed, proposal UI stays unavailable and proposal POST returns 503.

Authority: current V3.1 execution request; existing correspondence access matrix; `references_documents/المدير التنفيذي.docx` (reopen, task redistribution and secretariat follow-up). The inspected executive document does not supply escalation hours, replacement-edition approval chain or external registry codes. Existing approved policy metadata was inspected read-only; existing settings and expired cross-head exception are preserved.

| Topic | Implemented decision / boundary |
|---|---|
| Reopen | Executive role, active existing record access, not the author, closed ordinary letter only, reason and future due date mandatory. Old number/body/history retained. Linked workflows and archived/withdrawn records remain closed to this operation. |
| Reassign | Existing action access and unexpired owner grant required. Relevant head within same department; executive can use existing routing rules. Heads cannot delegate executive-authored/current executive handoffs. No cross-department confidential expansion or silent revival of expired authority. |
| Escalate | Versioned `escalation` policy, explicit approval reference and ordered positive hours. No seeded timing, inactive UI default. Generic daily-deduplicated notices only to existing authorized head/executive; no access grants. |
| Numbering | Preserve existing stable numeric department/type identifiers, issue-year sequence and unique reference constraint. Year uses configured app timezone (default Asia/Gaza), not backdated letter date. No reserved preview; draft has no official number. External incoming/outgoing registry keys await canonical issuer/channel policy. |
| Evidence | Completion accepts multiple private validated files; authorized author can inspect completion evidence. Replacing an already superseded attachment returns 409, preventing version forks. |
| Retention | Existing retention category, immutable issue and restrictive foreign keys preserved. Archive UI exposed to authorized author after completion. No purge/delete API added; legal-hold lifecycle remains unimplemented. |
| Official text | No in-place amendment. Replacement text now uses the origin-based Workflow chains described above; only final approval publishes. Substantive board text remains pending authority. Recorded approval is not a qualified digital signature. |
| Technical admin | Audited inspection retained, no executive signing/reopen/reassignment authority from technical role alone. |
| External services | No external mail sends, OAuth setup, AV or digital-signature claim. |

No production migration/data change. The replacement-edition increment adds the reviewed migration described above; earlier V3.1 changes required no new schema. For rollback, revert only the relevant code hunks listed in CHANGELOG; retain all action/policy/edition records. Do not drop history. Isolated-test backups were taken before fixture changes; test schema changes roll back with the test transactions.
