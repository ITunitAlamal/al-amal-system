# Workflow and handoffs

| Path/action | Ownership and completion |
| --- | --- |
| Employee → colleague/direct head | Current effective membership and existing canRoute hierarchy required |
| Head → own staff/executive/secretariat | Existing institutional routing; cross-head routing needs bounded directional policy |
| Executive direction/circular/report/task | Only an actual executive assignment may issue; technical admin role alone cannot |
| Board secretary → secretariat | Actual BOARD_CORRESPONDENCE start and registration, with board reference |
| Secretariat → executive → responsible head | Register/transmit, allocate/assign, then execute/report in WorkflowEngine |
| Head → executive → secretariat → board secretary | Executive review, filing, authorized board-secretary close; executive cannot review own execution |
| Reply/forward/return/refuse | Transaction creates an immutable action, transfers the one current task, retains prior assignment metadata, scopes files, and notifies |
| Read/open | Receipt/read timestamps only; never completes a task; access rechecked inside transaction |
| Complete/close | Explicit authorized action; linked workflow dependencies remain enforced; board path cannot be bypassed |
| Corrections | Append a reason/action and optional versioned attachments; issued body remains unchanged |

`StatusStepper` remains backed by `WorkflowProgress` for actual workflow instances. Ordinary letters do not invent a parallel workflow. Sequence scope remains issuance year + source department + type. Existing issued references are unchanged.

Closed-letter reopening, absence reassignment beyond existing effective-dated acting-head support, and additional urgent escalation destinations require approved policy; they are not automatically authorized by this patch.
