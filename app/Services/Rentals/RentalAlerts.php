<?php

namespace App\Services\Rentals;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;

/**
 * Daily rentals alerts (approved document §8 and §6): contracts entering the renewal window (90 days by default,
 * set in the rentals settings), contracts past their end, and dues past their date with a balance. Each alert goes
 * once to the assets head and the rentals officers; repeated runs send nothing new.
 */
final class RentalAlerts
{
    public function __construct(
        private readonly RentalContracts $contracts,
        private readonly RentalLedger $ledger,
        private readonly RentalsAccess $access,
        private readonly NotificationCenter $notifications,
    ) {}

    /** @return array{expired: int, renewed: int, expiring: int, overdue: int} */
    public function run(): array
    {
        $result = $this->contracts->expire() + ['expiring' => 0, 'overdue' => 0];
        $settings = $this->settings();
        $managers = $this->access->managers();
        if ($managers->isEmpty()) {
            return $result;
        }
        $today = now()->toDateString();
        $expiring = DB::table('aims_rental_contracts as c')->where('c.status', 'active')
            ->whereBetween('c.ends_on', [$today, now()->addDays($settings->renewal_alert_days)->toDateString()])
            ->whereNotExists(fn ($q) => $q->from('aims_rental_contracts as n')->whereColumn('n.parent_contract_id', 'c.id')->whereNotIn('n.status', ['rejected', 'cancelled']))
            ->whereNotExists(fn ($q) => $q->from('aims_rental_cases as k')->whereColumn('k.contract_id', 'c.id')->where('k.kind', 'eviction')->whereNotIn('k.status', ['rejected', 'cancelled']))
            ->get(['c.id', 'c.number', 'c.ends_on']);
        $recipients = $managers->pluck('id');
        foreach ($expiring as $contract) {
            $result['expiring'] += $this->notifications->notifyMany($recipients, 'rental_contract_expiring', [
                'title' => 'عقد الإيجار '.$contract->number.' ينتهي في '.$contract->ends_on,
                'body' => 'راجع الالتزام والدفعات وحالة العين، وأثبت رد المستأجر: تجديد بعقد لاحق أو إنهاء وإخلاء.',
                'url' => '/admin/rentals/records/contract/'.$contract->id,
                'dedupe' => 'rental:expiring:'.$contract->id.':'.$contract->ends_on,
            ]);
        }
        $overdue = $this->ledger->dues()->where('d.status', 'open')
            ->where('d.due_on', '<', now()->subDays($settings->overdue_alert_days)->toDateString())->get()
            ->filter(fn (object $due): bool => (float) $due->remaining > 0);
        foreach ($overdue as $due) {
            $result['overdue'] += $this->notifications->notifyMany($recipients, 'rental_due_overdue', [
                'title' => 'استحقاق متأخر على العقد '.$due->contract_number,
                'body' => 'استحقاق '.$due->due_on.' متبقٍ منه '.number_format((float) $due->remaining, 2).' '.$due->currency_code.' دون قبض مسجل من الحسابات.',
                'url' => '/admin/rentals?tab=dues',
                'dedupe' => 'rental:overdue:'.$due->id,
            ]);
        }

        return $result;
    }

    public function settings(): object
    {
        return DB::table('rentals_module_settings')->where('id', 1)->first() ?? (object) ['renewal_alert_days' => 90, 'overdue_alert_days' => 0];
    }
}
