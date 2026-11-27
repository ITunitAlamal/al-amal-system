<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Kpi\Sources\RentalContractsSource;
use Illuminate\Support\Facades\DB;

/**
 * The «الأصول والمباني والإيجارات» block of the personal dashboard, read-only, from the rentals registry:
 * the portfolio today (the same counts as the module's summary tab), requests waiting for the executive director's
 * decision on the central workflow, the file officer's preparation queue, contracts ending soon and overdue dues.
 * The assets department is the buildings and rentals department (owner, 2026-10-23).
 */
final class RentalsDashboard
{
    /** Installed renewal-alert window (rentals_module_settings), used when the settings row cannot be read. */
    private const DEFAULT_WINDOW_DAYS = 90;

    private const DECISIONS = [
        RentalApplicationWorkflow::DEFINITION => ['applications', 'طلبات استئجار بانتظار القرار', 'applications'],
        RentalContractWorkflow::DEFINITION => ['contracts_pending', 'عقود بانتظار الاعتماد', 'contracts'],
        RentalTerminationWorkflow::DEFINITION => ['terminations', 'إنهاءات وإخلاءات بانتظار القرار', 'cases'],
        RentalSettlementWorkflow::DEFINITION => ['settlements', 'تسويات بانتظار القرار', 'settlements'],
    ];

    public function __construct(private readonly RentalLedger $ledger, private readonly RentalsAccess $access) {}

    public static function canView(User $user): bool
    {
        return $user->hasPermissionInAnyScope('rentals.view');
    }

    /**
     * @return array{registered: bool, portfolio: list<array{key: string, label: string, value: int}>, unavailableUnits: int,
     *     decisions: array{title: string, items: list<array{key: string, label: string, count: int, url: string}>},
     *     preparation: list<array{key: string, label: string, count: int, url: string}>|null,
     *     expiring: array{days: int, count: int, withoutRenewal: int, url: string, items: list<array{number: string, endsOn: string, daysLeft: int, renewal: bool}>},
     *     overdue: array{dues: int, contracts: int, url: string}}
     */
    public function summary(User $viewer): array
    {
        $today = now()->toDateString();
        $units = DB::table('aims_property_units')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE availability = 'rented') AS rented")
            ->selectRaw("COUNT(*) FILTER (WHERE availability = 'vacant') AS vacant")
            ->selectRaw("COUNT(*) FILTER (WHERE availability IN ('maintenance', 'suspended', 'out_of_service')) AS unavailable")
            ->first();
        $count = fn (string $table, array $statuses): int => DB::table($table)->whereIn('status', $statuses)->count();
        $link = fn (string $tab): string => '/admin/rentals?tab='.$tab;
        $decider = $this->access->canDecide($viewer);
        $atExecutive = DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('i.status', 'in_progress')->whereIn('s.code', ['ed_decision', 'ed_approval'])->whereIn('d.code', array_keys(self::DECISIONS))
            ->groupBy('d.code')->select('d.code')->selectRaw('COUNT(*) AS total')->pluck('total', 'code');

        return [
            'registered' => (int) $units->total > 0 || DB::table('aims_properties')->exists(),
            'portfolio' => [
                ['key' => 'units', 'label' => 'الأعيان', 'value' => (int) $units->total],
                ['key' => 'rented', 'label' => 'مؤجرة', 'value' => (int) $units->rented],
                ['key' => 'vacant', 'label' => 'شاغرة', 'value' => (int) $units->vacant],
                ['key' => 'active', 'label' => 'عقود فعالة', 'value' => DB::table('aims_rental_contracts')->where('status', 'active')->count()],
            ],
            'unavailableUnits' => (int) $units->unavailable,
            'decisions' => [
                'title' => $decider ? 'بانتظار قرارك' : 'بانتظار قرار المدير التنفيذي',
                'items' => array_map(fn (string $code, array $item): array => ['key' => $item[0], 'label' => $item[1], 'count' => (int) ($atExecutive[$code] ?? 0),
                    'url' => $decider ? '/tasks' : $link($item[2])], array_keys(self::DECISIONS), self::DECISIONS),
            ],
            'preparation' => $this->access->canManage($viewer) ? [
                ['key' => 'drafts', 'label' => 'طلبات وعقود مسودة أو مُعادة لم تُرسل', 'url' => $link('contracts'),
                    'count' => $count('aims_rental_applications', ['draft', 'returned']) + $count('aims_rental_contracts', ['draft', 'returned'])
                        + DB::table('aims_rental_cases')->where('kind', 'eviction')->whereIn('status', ['draft', 'returned'])->count() + $count('aims_rental_settlements', ['draft', 'returned'])],
                ['key' => 'contracts_approved', 'label' => 'عقود معتمدة تنتظر التوقيع والتسليم والتفعيل', 'count' => $count('aims_rental_contracts', ['approved']), 'url' => $link('contracts')],
                ['key' => 'handover', 'label' => 'إنهاءات معتمدة تنتظر محضر الاستلام', 'count' => DB::table('aims_rental_cases')->where('kind', 'eviction')->where('status', 'approved')->count(), 'url' => $link('cases')],
                ['key' => 'expired', 'label' => 'عقود منتهية المدة لم تُستلم عينها', 'count' => $count('aims_rental_contracts', ['expired']), 'url' => $link('contracts')],
            ] : null,
            'expiring' => $this->expiring($today) + ['url' => $link('contracts')],
            'overdue' => $this->overdue() + ['url' => $link('dues')],
        ];
    }

    /**
     * Active contracts ending from today to the end of the indicator's window, and whether a renewal or a
     * termination is under way.
     *
     * @return array{days: int, count: int, withoutRenewal: int, items: list<array{number: string, endsOn: string, daysLeft: int, renewal: bool}>}
     */
    private function expiring(string $today): array
    {
        $days = $this->windowDays();
        $contracts = DB::table('aims_rental_contracts')->where('status', 'active')
            ->whereBetween('ends_on', [$today, now()->addDays($days)->toDateString()])
            ->select(['id', 'number', 'ends_on'])->selectRaw('(ends_on - CAST(? AS date)) AS days_left', [$today])
            ->orderBy('ends_on')->get();
        $ids = $contracts->pluck('id')->all();
        $handled = $ids === [] ? collect() : DB::table('aims_rental_contracts')->whereIn('parent_contract_id', $ids)
            ->whereIn('status', RentalContractsSource::RENEWAL_CONTRACT)->pluck('parent_contract_id')
            ->merge(DB::table('aims_rental_cases')->whereIn('contract_id', $ids)->whereIn('kind', ['renewal', 'eviction'])
                ->whereIn('status', RentalContractsSource::RENEWAL_CASE)->pluck('contract_id'))
            ->map(fn ($id): int => (int) $id)->unique();

        return [
            'days' => $days,
            'count' => $contracts->count(),
            'withoutRenewal' => $contracts->reject(fn (object $contract): bool => $handled->contains((int) $contract->id))->count(),
            'items' => $contracts->take(5)->map(fn (object $contract): array => ['number' => $contract->number, 'endsOn' => $contract->ends_on,
                'daysLeft' => (int) $contract->days_left, 'renewal' => $handled->contains((int) $contract->id)])->values()->all(),
        ];
    }

    /** @return array{dues: int, contracts: int} */
    private function overdue(): array
    {
        $row = DB::query()->fromSub($this->ledger->dues(), 'b')->where('b.overdue', true)
            ->selectRaw('COUNT(*) AS dues, COUNT(DISTINCT b.contract_id) AS contracts')->first();

        return ['dues' => (int) $row->dues, 'contracts' => (int) $row->contracts];
    }

    /**
     * The renewal-alert window of the rentals settings (three months by default, SRS 3.8.7.1), the same window the
     * daily alert uses, so «expiring soon» here and the alert agree.
     */
    private function windowDays(): int
    {
        $days = (int) (DB::table('rentals_module_settings')->where('id', 1)->value('renewal_alert_days') ?? self::DEFAULT_WINDOW_DAYS);

        return $days >= 1 && $days <= 365 ? $days : self::DEFAULT_WINDOW_DAYS;
    }
}
