<?php

namespace App\Console\Commands;

use App\Services\Hr\EmployeeDocumentAlerts;
use Illuminate\Console\Command;

final class HrDocumentAlerts extends Command
{
    protected $signature = 'hr:document-alerts';

    protected $description = 'Tell the HR head about staff documents expired or near expiry and files missing a mandatory document (each digest once per change)';

    public function handle(EmployeeDocumentAlerts $alerts): int
    {
        $result = $alerts->notify();
        $this->info(sprintf('Expired %d, expiring %s, files missing documents %s, notices sent %d', $result['expired'],
            $result['expiring'] ?? 'n/a (no alert days set)', $result['missing'] ?? 'n/a (no mandatory type)', $result['sent']));

        return self::SUCCESS;
    }
}
