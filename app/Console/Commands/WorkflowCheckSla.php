<?php

namespace App\Console\Commands;

use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\WorkflowSlaMonitor;
use Illuminate\Console\Command;

final class WorkflowCheckSla extends Command
{
    protected $signature = 'workflow:check-sla';

    protected $description = 'Flag requests past their step deadline, add escalation assignees, and return requests held by delegations that ended; nothing is auto-approved';

    public function handle(WorkflowSlaMonitor $monitor, ApprovalDelegations $delegations): int
    {
        $count = $monitor->flagOverdue();
        $this->info("Flagged {$count} overdue request(s)");
        $closed = $delegations->closeLapsed();
        $this->info("Closed {$closed} lapsed delegation(s)");
        $reminded = $monitor->remindHeldReviews();
        $this->info("Reminded {$reminded} suspended request(s) at their review date");

        return self::SUCCESS;
    }
}
