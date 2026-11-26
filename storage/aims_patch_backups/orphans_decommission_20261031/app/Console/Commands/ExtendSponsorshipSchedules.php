<?php

namespace App\Console\Commands;

use App\Services\Orphans\Sponsorships;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExtendSponsorshipSchedules extends Command
{
    protected $signature = 'orphans:sponsorship-schedules';

    protected $description = 'Extend the dues of active sponsorships to the rolling horizon and refresh their regularity (ongoing / paused / irregular)';

    public function handle(Sponsorships $sponsorships): int
    {
        $created = 0;
        $checked = 0;
        DB::table('sponsorships')->whereIn('status', ['active', 'paused'])->orderBy('id')->chunkById(200, function ($rows) use ($sponsorships, &$created, &$checked): void {
            foreach ($rows as $row) {
                $last = DB::table('sponsorship_schedules')->where('sponsorship_id', $row->id)->max('period_start');
                $created += $sponsorships->extend($row, $last ? (string) $last : null);
                $sponsorships->refreshRegularity((int) $row->id);
                $checked++;
            }
        });
        $this->info(sprintf('Checked %d sponsorships, created %d dues', $checked, $created));

        return self::SUCCESS;
    }
}
