<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\OrphanDocuments;
use Illuminate\Console\Command;

/**
 * Daily alert about documents of the orphans files that expire soon or have expired (requirement Q29, option B).
 * Alerts only: each document notifies each recipient once before expiry and once after. Nothing else changes.
 */
final class OrphansDocumentExpiry extends Command
{
    protected $signature = 'orphans:document-expiry {--dry-run : Count only, send nothing}';

    protected $description = 'Alert the orphans department head and verifiers about expiring documents (alerts only)';

    public function handle(OrphanDocuments $documents, NotificationCenter $notifications): int
    {
        $counts = $documents->sendExpiryAlerts($notifications, (bool) $this->option('dry-run'));
        $this->info(sprintf('%sExpiring within %d days: %d, expired: %d, recipients: %d, notifications sent: %d',
            $this->option('dry-run') ? 'DRY RUN — ' : '', $documents->expiryAlertDays(), $counts['soon'], $counts['expired'], $counts['recipients'], $counts['notified']));

        return self::SUCCESS;
    }
}
