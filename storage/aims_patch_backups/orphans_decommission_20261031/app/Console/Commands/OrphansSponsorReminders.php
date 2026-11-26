<?php

namespace App\Console\Commands;

use App\Services\Orphans\SponsorAgreements;
use App\Services\Orphans\SponsorContacts;
use Illuminate\Console\Command;

/**
 * Daily sponsorship reminders (requirements Q35 and Q36): signed agreements nearing or past their end date alert the
 * head and the sponsorships staff once per stage; due follow-ups of sponsor contacts remind whoever recorded them.
 * Alerts only: nothing is ended, renewed or sent to a sponsor.
 */
final class OrphansSponsorReminders extends Command
{
    protected $signature = 'orphans:sponsor-reminders {--dry-run : Count only, send nothing}';

    protected $description = 'Alert about sponsorship agreements to renew and sponsor contact follow-ups (alerts only)';

    public function handle(SponsorAgreements $agreements, SponsorContacts $contacts): int
    {
        $dry = (bool) $this->option('dry-run');
        $renewals = $agreements->sendRenewalAlerts($dry);
        $followUps = $contacts->sendFollowUpReminders($dry);
        $this->info(sprintf('%sAgreements ending soon: %d, ended without renewal: %d, recipients: %d; contact follow-ups due: %d; notifications sent: %d',
            $dry ? 'DRY RUN — ' : '', $renewals['soon'], $renewals['ended'], $renewals['recipients'], $followUps['due'], $renewals['notified'] + $followUps['notified']));

        return self::SUCCESS;
    }
}
