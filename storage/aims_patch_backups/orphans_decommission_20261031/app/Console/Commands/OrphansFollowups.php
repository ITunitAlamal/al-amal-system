<?php

namespace App\Console\Commands;

use App\Services\Orphans\OrphanFollowups;
use Illuminate\Console\Command;

/**
 * Daily reminders and escalation of the case follow-ups of the orphans files (requirements Q10 and Q27). Alerts
 * only: the responsible member once before the due date and once when late, the head once when overdue by the
 * escalation period. No file, visit or payment changes here.
 */
final class OrphansFollowups extends Command
{
    protected $signature = 'orphans:followups {--dry-run : Count only, send nothing}';

    protected $description = 'Remind staff of due orphans case follow-ups and escalate overdue ones to the head (alerts only)';

    public function handle(OrphanFollowups $followups): int
    {
        $settings = $followups->settings();
        $counts = $followups->sendAlerts((bool) $this->option('dry-run'));
        $this->info(sprintf('%sDue within %d days: %d, overdue: %d, to escalate (after %d days): %d, heads: %d, notifications sent: %d',
            $this->option('dry-run') ? 'DRY RUN — ' : '', $settings['reminder'], $counts['soon'], $counts['overdue'], $settings['escalation'], $counts['escalated'],
            $counts['heads'], $counts['notified']));

        return self::SUCCESS;
    }
}
