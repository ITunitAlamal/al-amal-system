<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceAccessGrants;
use Illuminate\Console\Command;

/** Ends finance assignments and per-user grants whose end date has passed (FinanceGuard already ignores them). */
final class FinanceExpireAccess extends Command
{
    protected $signature = 'finance:expire-access';

    protected $description = 'إنهاء الاختصاصات والصلاحيات المالية المؤقتة بعد تاريخ انتهائها';

    public function handle(FinanceAccessGrants $grants): int
    {
        $this->info('ended: '.$grants->expire());

        return self::SUCCESS;
    }
}
