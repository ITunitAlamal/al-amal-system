# V3 outcome and remaining gaps

| Area | Result | Evidence |
| --- | --- | --- |
| Simplified composition | Preserved: Send/Save Draft, multiple files, urgent, collapsed advanced fields | Form.vue; Registry::draft; HTTP one-step send test |
| Inbox/outbox | Improved: incoming history, sent including referrals/replies, drafts, needs action, in progress, archive/all; scoped counts, stable 25-row pagination | Access::mailbox; Controller::index |
| Search/filtering | Added dates, status, current-user unread/overdue, subject/reference/authorized action actor search | Controller::index; mailbox filter/pagination tests |
| Current deadlines | Fixed list to use task deadline after referral; urgent and overdue indicators | Controller::index; Index.vue |
| Membership revocation | Fixed stale home-department fallback when a scoped assignment moves elsewhere; read receipt rechecks current action access | Access::member; Registry::markOpened; revocation test |
| Personal dashboard/header | Fixed cancelled/expired counts; overdue counter and filtered links; no sidebar redesign | PersonalWorkspace::correspondenceTasks; HandleInertiaRequests::share; Home.vue |
| Return/refusal/attachments | Exposed existing backend return/refusal actions and multiple attachments; correction entry also labels attachment addition | Controller::show; Show.vue |
| Board/secretariat/executive | Preserved actual WorkflowEngine path and StatusStepper; complete board cycle passes | CorrespondenceWorkflow; OfficialCorrespondenceTest |
| Private files | Existing provenance, checksum, revisions, private disk, scoped downloads and failure cleanup preserved and tested | CorrespondenceDocuments; download tests |
| Official printing | Added authorized original-letter A4 RTL template; separate admin audit appendix, no default routing history | Controller::print; Print.vue; HTTP and SSR tests |
| Notifications | Existing dedupe/retry retained; overdue notification now explicit and generic; expired recipient receives none | CorrespondenceReconcile; reminder tests |
| Sequence contention | Existing row-lock allocator verified with two independent PHP/PostgreSQL processes | concurrent_number_allocations test |
| External registry/Gmail | Not implemented or enabled; requires authoritative institutional channels and separate integration | OPEN_DECISIONS.md |
| Reopen/text reissue/legal holds | Partial: immutable original, action corrections and attachment revisions exist; closed-letter reopening and replacement official-text issuance require approved policy | Registry::act; OPEN_DECISIONS.md |
| Malware scanning/digital signature | No real service available; not simulated or claimed | PRINT_TEMPLATE_SPEC.md |
| Deployment/load/printed appearance | Not production validated; no browser/printer testing; no high-load HTTP concurrency benchmark | TEST_RESULTS.md |

No completion percentage is asserted. The initial audit is preserved in `CORRESPONDENCE_V3_GAP_MATRIX.md` at repository root.
