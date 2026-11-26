# V3.1 RBAC evidence

## Replacement editions (2026-10-27)

Added feature evidence in `OfficialCorrespondenceTest`:

- `staff_edition_requires_head_then_executive_and_publishes_without_mutating_original`: staged approval, central task inbox, unauthorized/technical approval denial, immutable original and effective print.
- `head_and_executive_editions_require_secretariat_registration_before_approval`: both origin branches, registration not publication, explicit executive self-approval policy.
- `substantive_board_related_editions_remain_pending_and_non_substantive_letters_use_regular_approval`: substantive hold and non-substantive implementation-letter approval.
- `board_issued_text_cannot_bypass_board_authority_by_claiming_a_non_substantive_edit`: board source cannot use implementation-letter exception.
- `edition_submission_is_idempotent_private_and_rejected_text_never_becomes_effective`: HTTP submission/retry/conflict, private attachments, rejection, predecessor chain, historical text retained, published viewer receives public workflow status only.
- `edition_workflow_rejects_skipped_approval_and_expired_reviewer_authority`: even a synthetic shortcut transition cannot publish; expired authority loses edition/inbox access.

Node SSR test `replacement comparison escapes both editions and offers no approval control for a pending board text`: HTML escaping and RTL/status markup; no browser or screenshots.

Tests are in `tests/Feature/OfficialCorrespondenceTest.php` unless specified.

| Boundary | Evidence |
|---|---|
| Executive reopen allowed; author/head/admin forbidden; reason; idempotency/history | `executive_reopening_requires_reason_preserves_history_and_hands_off_once` |
| No executive self-reopen; no head delegation of executive matter; no confidential recipient expansion | `executive_cannot_reopen_own_letter_and_reassignment_cannot_expand_confidential_access` |
| Reassignment actor/scope/expiry/revision/task uniqueness | `reassignment_requires_authority_current_grant_and_fresh_revision` |
| Multi-file completion, unrelated download denied, authorized author download, archive UI | `completion_accepts_private_evidence_and_author_can_archive_from_the_screen` |
| Escalation settings admin-only, no visibility grants or private subject, dedupe | `approved_escalation_is_deduplicated_and_never_grants_executive_visibility` |
| Forwarded/returned/overdue record isolation | `returned_forwarded_and_overdue_boxes_follow_authorized_handoffs` |
| Shared middleware + authenticated identity + counter | `correspondence_inbox_runs_with_shared_navigation_middleware` |
| Attachment version conflict | `attachment_replacement_rejects_branching_from_a_superseded_version` |
| Issue-year boundary/rollback; parallel allocator | `numbering_uses_issue_year_and_rolls_back_failed_allocations`, existing `concurrent_number_allocations_serialize_the_same_authority_and_type_sequence` |
| Existing board workflow, print appendix, downloads, stale role access, private attachment histories | Existing correspondence regression tests retained |

Scope limits: one representative navigation request, not all navigation/RBAC permutations. Stale revision contention tested; simultaneous HTTP issuance/reassignment workers not tested. No test claims for disabled replacement-text editions or board reopening.
