# Permissions

| Operation | Authorization |
| --- | --- |
| List/search/count | Start with CorrespondenceAccess::visible; incoming/sent action searches additionally restrict action grants |
| Needs-action/unread/overdue | Current user owns an open task and has effective action access |
| Create/issue | Effective nontechnical membership, approved route and active constants |
| Forward/return/refuse | Current task owner, effective action access, destination policy, required reason |
| Executive/board decision | Existing workflow stage role, assigned actor and self-review prohibition |
| Download | Record visible AND exact action visible; private forced download with nosniff/no-store |
| Official print | Visible issued, nonwithdrawn letter; original attachments only; print does not mark read or complete |
| Internal print appendix | Additional system-admin authorization, retaining existing audited technical inspection authority |
| Settings | Existing system-admin versioned policy endpoint; no executive signing authority conferred |

The pre-existing audited technical-admin access to records/files is preserved, not expanded to executive actions. A scoped assignment in another department no longer implicitly grants access through a stale `users.department_id`. Global assignments retain the existing home-department fallback.

Changing the institutional extent of technical-admin inspection requires a separate approved permission decision. No broad organization RBAC refactor was made.
