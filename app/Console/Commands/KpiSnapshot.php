<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Services\Kpi\KpiEngine;
use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiScope;
use App\Services\Kpi\KpiSnapshots;
use Illuminate\Console\Command;

class KpiSnapshot extends Command
{
    protected $signature = 'kpi:snapshot
        {--previous-month : Period figures for the previous calendar month instead of the current month to date}
        {--reason= : Reason stored with every reading}';

    protected $description = 'حفظ قراءات مؤشرات الأداء للمؤسسة ولكل قسم فعال؛ تُضاف قراءات جديدة ولا تُعدَّل السابقة';

    public function handle(KpiEngine $engine, KpiSnapshots $snapshots, AuditService $audit): int
    {
        $reason = trim((string) $this->option('reason')) ?: 'قراءة دورية مجدولة';
        $scope = KpiScope::system();
        $period = $this->option('previous-month') ? KpiPeriod::month(KpiPeriod::now()->subMonthNoOverflow()) : KpiPeriod::fromInput(null, null);
        $today = KpiPeriod::today();
        $definitions = $engine->definitions($scope);
        $periodCards = [];
        $stateCards = [];
        foreach ([null, ...$scope->departments] as $department) {
            foreach ($definitions as $definition) {
                if ($department !== null && $definition->audience !== 'shared') {
                    continue;
                }
                $card = $engine->evaluate($definition, $scope, $period, [], $department, true);
                if ($card['snapshot']) {
                    $stateCards[] = $engine->evaluate($definition, $scope, $today, [], $department, true);
                } else {
                    $periodCards[] = $card;
                }
            }
        }
        $saved = $snapshots->save($periodCards, $period, $reason, null) + $snapshots->save($stateCards, $today, $reason, null);
        $audit->recordRow('kpi.snapshot.scheduled', 'kpi_results', 'scheduled', null,
            ['saved' => $saved, 'period' => $period->toArray(), 'state_date' => $today->from->toDateString(), 'reason' => $reason], null);
        $this->info('حُفظت '.$saved.' قراءة جديدة (الفترة '.$period->toArray()['from'].' — '.$period->toArray()['to'].').');

        return self::SUCCESS;
    }
}
