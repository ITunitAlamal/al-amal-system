<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Aid;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Console\Command;

/**
 * «الكفالات»: keeps every live sponsorship's planned dues twelve months ahead (through the eligibility gate), and
 * tells the department head once a day how many referred dues Accounts has not fully collected after their date,
 * and which live sponsorships the gate stopped scheduling. Nothing is referred, collected or changed in Accounts here.
 */
final class OrphansSponsorshipDues extends Command
{
    protected $signature = 'orphans:sponsorship-dues';

    protected $description = 'Extend the planned dues of live sponsorships and alert the orphans head about overdue referred dues and blocked schedules (once a day)';

    public function handle(Aid $aid, OrphanAccess $access, NotificationCenter $notifications): int
    {
        $result = $aid->extendSchedules();
        $heads = $access->headUserIds();
        $overdue = Aid::overdueDues()->count();
        $sent = $overdue > 0 ? $notifications->notifyMany($heads, 'orphans_sponsorship_overdue', [
            'title' => $overdue.' استحقاق كفالة محال للحسابات تجاوز موعده دون تحصيل كامل',
            'body' => 'تابعها مع الحسابات وصعّد ما يلزم للمدير التنفيذي؛ التحصيل يُقرأ من الحسابات ولا يُعدَّل من القسم.',
            'url' => '/orphans', 'dedupe' => 'sponsorship-overdue-'.today()->toDateString(),
        ]) : 0;
        $blocked = $result['blocked'];
        if ($blocked !== []) {
            $refs = implode('، ', array_column(array_slice($blocked, 0, 10), 'reference_no')).(count($blocked) > 10 ? ' و'.(count($blocked) - 10).' أخرى' : '');
            $sent += $notifications->notifyMany($heads, 'orphans_sponsorship_overdue', [
                'title' => count($blocked).' كفالة نشطة توقف تمديد جدول استحقاقاتها',
                'body' => 'ملف مستفيد فيها لم يعد معتمدًا، فلا تُنشأ لها استحقاقات جديدة: '.$refs.'. عالج الملف أو قرر إيقاف الكفالة.',
                'url' => count($blocked) === 1 ? '/orphans/aid/sponsorships/'.$blocked[0]['public_id'] : '/orphans/aid',
                'dedupe' => 'sponsorship-blocked-'.today()->toDateString(),
            ]);
        }
        $this->info(sprintf('Sponsorships scheduled %d (dues added %d, blocked by the eligibility gate %d); overdue dues %d, head notices %d',
            $result['sponsorships'], $result['added'], count($blocked), $overdue, $sent));

        return self::SUCCESS;
    }
}
