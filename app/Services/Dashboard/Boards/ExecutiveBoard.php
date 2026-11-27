<?php

namespace App\Services\Dashboard\Boards;

use App\Models\User;
use App\Services\Progress\TransactionTracker;
use App\Services\Rentals\RentalsDashboard;
use App\Services\WorkflowVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * المدير التنفيذي — where is my decision needed now, and which work is at risk.
 * Campaign lists (initial and amended versions) are counted here because only the executive director approves them;
 * the table links to the review page, never to a one-click approval.
 */
final class ExecutiveBoard
{
    public function __construct(private readonly TransactionTracker $tracker) {}

    public function build(User $user): array
    {
        $tasks = $this->tracker->tasksOf($user);
        $lists = DB::table('campaign_lists as l')->join('campaigns as c', 'c.id', '=', 'l.campaign_id')->where('l.status', 'pending_executive')
            ->orderBy('l.submitted_at')->get(['c.public_id', 'c.reference_no', 'c.title', 'l.version', 'l.base_list_id', 'l.entry_count', 'l.submitted_at']);
        $pending = $tasks->count() + $lists->count();
        $overdue = $tasks->where('overdue', true)->count();
        $incoming = DB::table('workflow_events')->where('assigned_to_id', $user->id);
        $handled = DB::table('workflow_events')->where('actor_id', $user->id)->whereNotIn('action', ['submit', 'start', 'comment']);
        $visible = DB::table('workflow_instances as i')->where('i.status', 'in_progress')->whereNotIn('i.workflow_version_id', WorkflowVisibilityService::confidentialVersions());

        $byKind = $tasks->groupBy('kind')->map->count();
        $segments = $byKind->map(fn (int $n, string $kind): string => $kind.' '.$n)->values()->all();
        if ($lists->isNotEmpty()) {
            $segments[] = 'كشوف حملات '.$lists->count();
        }

        $rows = $lists->map(fn (object $l): array => ['cells' => ['reference' => $l->reference_no, 'type' => $l->base_list_id ? 'تعديل كشف معتمد (الإصدار '.$l->version.')' : 'كشف مستفيدين أولي',
            'subject' => $l->title.' · '.$l->entry_count.' مستفيد', 'submitted' => BoardKit::due($l->submitted_at), 'age' => BoardKit::age($l->submitted_at)],
            'status' => ['label' => 'بانتظار اعتمادك', 'tone' => 'warning'], 'url' => '/orphans/campaigns/'.$l->public_id.'?tab=lists', 'action' => 'مراجعة الكشف', 'overdue' => false])
            ->concat($tasks->take(40)->map(fn (array $t): array => ['cells' => ['reference' => $t['reference'], 'type' => $t['kind'], 'subject' => trim(($t['requester'] ?? '').' · '.$t['step'], ' ·'),
                'submitted' => BoardKit::due($t['dueAt']) ? 'يستحق '.BoardKit::due($t['dueAt']) : '—', 'age' => null],
                'status' => $t['overdue'] ? ['label' => 'متأخر', 'tone' => 'danger'] : ['label' => 'بانتظار قرارك', 'tone' => 'info'], 'url' => $t['url'], 'action' => 'مراجعة', 'overdue' => $t['overdue']]))
            ->values()->all();

        return [
            'key' => 'executive', 'title' => 'لوحة المدير التنفيذي', 'decision' => 'أين يلزم قراري الآن، وأي أعمال المؤسسة متأخرة؟', 'scope' => 'المؤسسة كلها',
            'hero' => BoardKit::metric('قرارات تنتظر اعتمادي الآن', $pending, 'down', [...BoardKit::weekOverWeek($incoming, 'occurred_at'), 'label' => 'وصل إليك خلال آخر 7 أيام'],
                $segments ? implode(' · ', $segments) : 'لا شيء بانتظارك', '/tasks'),
            'kpis' => [
                BoardKit::metric('قرارات متأخرة عن مهلتها', $overdue, 'down', detail: 'من القرارات المنتظرة أعلاه', url: '/tasks'),
                BoardKit::metric('معاملات المؤسسة المتأخرة', (clone $visible)->whereNotNull('i.sla_breached_at')->count(), 'down',
                    detail: 'من '.(clone $visible)->count().' معاملة جارية (دون السرية)'),
                BoardKit::metric('حملات إغاثية تحتاج متابعة', DB::table('campaigns')->whereIn('status', ['pending_executive', 'distributing', 'reconciling'])->count(), null,
                    detail: 'بانتظار اعتماد الكشف أو قيد التوزيع أو التسوية', url: '/orphans/campaigns'),
                ...$this->rentals($user),
            ],
            'trend' => BoardKit::trend('الوارد إليك والمنجز منك أسبوعيًا', 'الوارد = مراحل أُسندت إليك في مسارات العمل؛ المنجز = إجراءات اتخذتها (اعتماد، إعادة، رفض…). نوافذ 7 أيام تنتهي اليوم.', [
                ['label' => 'وارد', 'values' => BoardKit::weekly($incoming, 'occurred_at')],
                ['label' => 'منجز', 'values' => BoardKit::weekly($handled, 'occurred_at')],
            ]),
            'bars' => BoardKit::bars('المعاملات الجارية حسب القسم', 'معاملات مسارات العمل المفتوحة الآن لكل قسم في الهيكل (دون السرية).',
                (clone $visible)->leftJoin('departments as d', 'd.id', '=', 'i.department_id')->groupBy('d.name')->selectRaw("COALESCE(d.name, 'دون قسم') AS label, count(*) AS value")
                    ->get()->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('قرارات تنتظر اعتمادي', [['key' => 'reference', 'label' => 'المرجع'], ['key' => 'type', 'label' => 'النوع'], ['key' => 'subject', 'label' => 'التفاصيل'],
                ['key' => 'submitted', 'label' => 'التاريخ'], ['key' => 'age', 'label' => 'العمر']], $rows, 'لا توجد قرارات بانتظارك الآن.', '/tasks'),
            'actions' => [['label' => 'صندوق المهام', 'url' => '/tasks', 'icon' => 'inbox'], ['label' => 'الحملات الإغاثية', 'url' => '/orphans/campaigns', 'icon' => 'boxes']],
        ];
    }

    /**
     * One compact rentals tile (TRANSFORM §11): contracts with arrears, with occupancy and contracts ending without
     * renewal in its detail, from the rentals block's own summary. Nothing while no unit or property is registered.
     *
     * @return list<array<string, mixed>>
     */
    private function rentals(User $user): array
    {
        try {
            $r = app(RentalsDashboard::class)->summary($user);
        } catch (\Throwable) {
            return [BoardKit::unavailable('الإيجارات', 'تعذر قراءة ملخص الإيجارات الآن.')];
        }
        if (! $r['registered']) {
            return [];
        }
        $portfolio = collect($r['portfolio'])->keyBy('key');

        return [BoardKit::metric('عقود إيجار عليها متأخرات', (int) $r['overdue']['contracts'], 'down',
            detail: 'الإشغال: '.$portfolio['rented']['value'].' مؤجرة من '.$portfolio['units']['value'].' عينًا · تنتهي خلال '.$r['expiring']['days']
                .' يومًا دون تجديد: '.$r['expiring']['withoutRenewal'],
            url: RentalsDashboard::canView($user) ? $r['overdue']['url'] : null)];
    }
}
