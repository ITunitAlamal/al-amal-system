<?php

namespace App\Console\Commands;

use App\Services\Rentals\RentalAlerts;
use Illuminate\Console\Command;

final class RentalsAlerts extends Command
{
    protected $signature = 'rentals:alerts';

    protected $description = 'Expire rental contracts past their end and send the renewal-window and overdue-due alerts (once each)';

    public function handle(RentalAlerts $alerts): int
    {
        $result = $alerts->run();
        $this->info(sprintf('Expired %d, renewed %d, renewal alerts %d, overdue alerts %d', $result['expired'], $result['renewed'], $result['expiring'], $result['overdue']));

        return self::SUCCESS;
    }
}
