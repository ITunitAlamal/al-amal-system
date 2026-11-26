# V3.1 workflow matrix

| Trigger | Preconditions | Result / integration |
|---|---|---|
| Draft -> issue | Existing author membership + authorized recipient | Immutable numbered original, private files, existing unique task and notice |
| Forward/reply/return/refuse | Current authorized owner + allowed route | Existing atomic task handoff; authorized original + handoff files |
| Complete | Current authorized owner, ordinary letter | Completion evidence files accepted; task completed; existing linked-request behavior retained |
| Archive | Author membership + completed task/workflow | Archived retained record; action now available on closed-record screen |
| Reopen | Executive, not author; closed ordinary letter; visible record; cause and future deadline | `processing`, closed_at cleared; existing task reopened for selected authorized recipient; old state/owner in immutable action metadata |
| Reassign | Scoped head or executive; existing current action access; previous owner grant still valid | Same task row updated atomically, previous and new owner notices, expected revision protects stale writes |
| Board decision | Existing formal transitions only | Register -> allocate -> execute -> review -> file -> authorized close preserved; generic reopen/reassign disabled |
| Overdue | Active owner grant | Existing generic owner reminder; optionally approved head/executive thresholds; no new visibility |
| Replacement staff text | Original author proposes, scoped head reviews, executive approves | Existing edition Workflow; publish only on final approval |
| Replacement head/executive text | Secretariat registers; executive approves (issuing executive for own text) | Registration distinct from approval; existing central task inbox and Stepper |
| Substantive board replacement | No documented board amendment authority | Separate pending Workflow; no approval transition |
| Non-substantive board implementation letter | Ordinary origin chain plus executive attestation | Approved replacement only; board-issued source text cannot use this exception |

Existing `TransactionTracker`, workflow inbox, `CorrespondenceWorkflow` projection and StatusStepper are reused; no second task engine or sidebar. Ordinary reopen/reassignment creates correspondence history events, not a new central workflow instance. Formal board attachments remain on workflow actions.
