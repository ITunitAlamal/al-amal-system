<?php

namespace App\Console\Commands;

use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\OpenStepRerouter;
use App\Services\Workflow\WorkflowSlaMonitor;
use Illuminate\Console\Command;

final class WorkflowCheckSla extends Command
{
    protected $signature = 'workflow:check-sla';

    protected $description = 'Flag requests past their step deadline, add escalation assignees, return requests held by delegations that ended, and reroute requests whose holders lost their authority; nothing is auto-approved';

    public function handle(WorkflowSlaMonitor $monitor, ApprovalDelegations $delegations, OpenStepRerouter $rerouter): int
    {
        $count = $monitor->flagOverdue();
        $this->info("Flagged {$count} overdue request(s)");
        $closed = $delegations->closeLapsed();
        $this->info("Closed {$closed} lapsed delegation(s)");
        // After the delegations: a request they could not return is recorded as waiting with no holder.
        $rerouted = $rerouter->rerouteStale('تغيّرت صلاحية صاحب المرحلة (دور أو تعيين أو رئاسة قسم أو تفويض)');
        $this->info("Rerouted {$rerouted} request(s) whose holders no longer qualify");
        $reminded = $monitor->remindHeldReviews();
        $this->info("Reminded {$reminded} suspended request(s) at their review date");

        return self::SUCCESS;
    }
}
