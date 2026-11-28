<?php

namespace App\Services\Dashboard\Boards;

use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Orphans\Aid;
use App\Services\Orphans\Campaigns\Campaigns;
use App\Services\Orphans\Dashboard as OrphansDashboard;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;

/**
 * شؤون الأيتام والكفالات: the head's oversight board and one board per unit the employee works in
 * (records, social research, sponsorships, relief campaigns). Children are counted once (one file per child);
 * «معتمد مبدئيًا» (import) is always shown apart from approved files. Names in tables are the registry names the
 * user may already open; identity numbers are never sent.
 */
final class OrphansBoards
{
    public const UNITS = ['ORPHANS-U1' => 'records', 'ORPHANS-U2' => 'social', 'ORPHANS-U3' => 'sponsorship', 'ORPHANS-U4' => 'campaigns'];

    private const LIVE = ['ended', 'archived'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphansDashboard $dashboard) {}

    /** @return list<string> board kinds for this user, head first */
    public function kinds(User $user): array
    {
        $kinds = [];
        if ($this->access->isHead($user)) {
            $kinds[] = 'head';
        } elseif ($this->access->isStaff($user)) {
            $codes = DB::table('department_user_roles as a')->join('departments as d', 'd.id', '=', 'a.department_id')->where('a.user_id', $user->id)
                ->whereRaw(EffectiveAssignments::sql('a'))->whereIn('d.code', array_keys(self::UNITS))->pluck('d.code')->all();
            $kinds = array_values(array_unique(array_map(fn (string $code): string => self::UNITS[$code], $codes))) ?: ['records'];
        }
        // Campaign members (PR staff included) operate only the campaigns assigned to them.
        if (! in_array('campaigns', $kinds, true) && ! in_array('head', $kinds, true) && $this->campaignIds($user) !== []) {
            $kinds[] = 'campaigns';
        }

        return $kinds;
    }

    public function build(User $user, string $kind): array
    {
        return match ($kind) {
            'head' => $this->head($user),
            'social' => $this->social($user),
            'sponsorship' => $this->sponsorship(),
            'campaigns' => $this->campaigns($user),
            default => $this->records($user),
        };
    }

    private function orphans()
    {
        return DB::table('orphans')->whereNotIn('status', self::LIVE);
    }

    private function head(User $user): array
    {
        $k = $this->dashboard->kpis();
        $reg = $k['registered'];
        $actions = $this->dashboard->actionItems($user, true);
        $portal = $k['portal']['new'] + $k['portal']['review'] + $k['portal']['completion'];
        $incomplete = DB::table('orphans')->whereNotIn('status', self::LIVE)->where('completeness', '<', 100)->count();

        return [
            'key' => 'orphans-head', 'title' => 'شؤون الأيتام والكفالات', 'decision' => 'أي الطلبات والملفات والكفالات تحتاج إجراء الفريق الآن؟', 'scope' => 'القسم كله',
            'hero' => BoardKit::metric('الأيتام المسجلون (طفل واحد لكل ملف)', $reg['total'], null, [...BoardKit::weekOverWeek($this->orphans(), 'created_at'), 'label' => 'ملفات أُنشئت خلال آخر 7 أيام'],
                'معتمد: '.$reg['approved'].' · معتمد مبدئيًا (استيراد): '.$reg['provisional'].' · غير ذلك: '.max(0, $reg['total'] - $reg['approved'] - $reg['provisional']), '/orphans/registry'),
            'kpis' => [
                BoardKit::metric('طلبات البوابة المفتوحة', $portal, 'down', detail: 'جديد '.$k['portal']['new'].' · قيد المراجعة '.$k['portal']['review'].' · بانتظار الاستكمال '.$k['portal']['completion'], url: '/orphans/reviews'),
                $k['sponsored'] ? BoardKit::metric('الأيتام المكفولون حاليًا', $k['sponsored']['orphans'], 'up', detail: 'كفالات نشطة: '.$k['sponsored']['sponsorships'], url: '/orphans/aid')
                    : BoardKit::unavailable('الأيتام المكفولون حاليًا', 'لا توجد كفالات مسجلة بعد.'),
                BoardKit::metric('ملفات غير مكتملة', $incomplete, 'down', url: '/orphans/registry?completeness=incomplete'),
            ],
            'trend' => BoardKit::trend('ملفات جديدة ومراجعات منجزة أسبوعيًا', 'الملفات = ملفات أيتام أُنشئت (أي مصدر، عدا المنتهية والمؤرشفة)؛ المراجعات = طلبات بوابة بُتّ فيها. نوافذ 7 أيام تنتهي اليوم.', [
                ['label' => 'ملفات جديدة', 'values' => BoardKit::weekly($this->orphans(), 'created_at')],
                ['label' => 'مراجعات منجزة', 'values' => BoardKit::weekly(DB::table('portal_applications')->whereNotNull('decided_at'), 'decided_at')],
            ]),
            'bars' => BoardKit::bars('الأيتام حسب المحافظة', 'ملفات الأيتام القائمة حسب محافظة السكن الحالي. اختر صفًا لفتح السجل بالفلتر نفسه.',
                array_map(fn (array $r): array => ['label' => $r['label'], 'value' => $r['count'], 'url' => '/orphans/registry?'.http_build_query($r['filter'])], $this->dashboard->geography())),
            'table' => $this->actionTable($actions, 'يحتاج إلى إجراء', true),
            'actions' => $this->quick($user),
            'definitions' => ['الأيتام المسجلون' => OrphansDashboard::DEFINITIONS['registered'], 'طلبات البوابة' => OrphansDashboard::DEFINITIONS['portal'], 'الكفالات' => OrphansDashboard::DEFINITIONS['sponsored']],
        ];
    }

    private function records(User $user): array
    {
        $mine = $this->dashboard->myWork($user);
        $items = collect($this->dashboard->actionItems($user, false));
        $assigned = DB::table('portal_applications')->where('assigned_to', $user->id);

        return [
            'key' => 'orphans-records', 'title' => 'التسجيل والمراجعة', 'decision' => 'أي حالة أعالجها الآن دون نقص مستندات أو تكرار سجلات؟', 'scope' => 'ما أُسند إليّ',
            'hero' => BoardKit::metric('حالات مسندة إليّ تحتاج إجراء', count($mine), 'down', [...BoardKit::weekOverWeek(clone $assigned, 'assigned_at'), 'label' => 'أُسندت إليك خلال آخر 7 أيام'], url: '/orphans/reviews'),
            'kpis' => [
                BoardKit::metric('بانتظار استكمال الوصي', (clone $assigned)->where('status', 'needs_completion')->count(), null, detail: 'طلبات أُعيدت للوصي لاستكمالها', url: '/orphans/reviews'),
                BoardKit::metric('وصايات بانتظار التحقق', $items->where('source', 'وصاية بانتظار التحقق')->count(), 'down', detail: 'أطفال بلا وصاية معتمدة في ملفاتك'),
                BoardKit::metric('متأخرة', $items->where('overdue', true)->count(), 'down'),
            ],
            'trend' => BoardKit::trend('ما أُسند إليّ وما أنجزته أسبوعيًا', 'المسند = طلبات بوابة أُسندت إليك؛ المنجز = طلبات بتَّ فيها أنت.', [
                ['label' => 'مسند', 'values' => BoardKit::weekly(clone $assigned, 'assigned_at')],
                ['label' => 'منجز', 'values' => BoardKit::weekly(DB::table('portal_applications')->where('decided_by', $user->id), 'decided_at')],
            ]),
            'bars' => BoardKit::bars('حالاتي حسب نوع الإجراء', 'ما ينتظر إجراءك الآن مصنفًا حسب مصدره.', $items->groupBy('source')->map(fn ($g, $label): array => ['label' => $label, 'value' => $g->count()])->values()->all()),
            'table' => $this->actionTable($items->all(), 'مهامي', false),
            'actions' => $this->quick($user),
        ];
    }

    private function social(User $user): array
    {
        $today = today()->toDateString();
        $mine = DB::table('social_assessments')->where('assigned_to', $user->id);
        $scheduled = (clone $mine)->where('status', 'scheduled');
        $kinds = ['visit' => 'زيارة ميدانية', 'assessment' => 'تقييم اجتماعي', 'follow_up' => 'متابعة'];
        $rows = (clone $scheduled)->from('social_assessments as s')->leftJoin('orphans as o', 'o.id', '=', 's.orphan_id')->leftJoin('households as h', 'h.id', '=', 's.household_id')
            ->orderBy('s.scheduled_on')->limit(50)->get(['s.kind', 's.scheduled_on', 'o.public_id', 'o.reference_no', 'h.reference_no as household'])
            ->map(fn (object $v): array => ['cells' => ['reference' => $v->household ?: $v->reference_no, 'type' => $kinds[$v->kind] ?? 'زيارة', 'date' => $v->scheduled_on],
                'status' => $v->scheduled_on < $today ? ['label' => 'متأخرة', 'tone' => 'danger'] : ($v->scheduled_on === $today ? ['label' => 'اليوم', 'tone' => 'warning'] : ['label' => 'مجدولة', 'tone' => 'info']),
                'url' => $v->public_id ? '/orphans/registry/orphans/'.$v->public_id.'?tab=assessment' : '/orphans/registry', 'action' => 'فتح', 'overdue' => $v->scheduled_on < $today])->all();

        return [
            'key' => 'orphans-social', 'title' => 'البحث الاجتماعي', 'decision' => 'أي الزيارات والتقييمات أبدأ بها؟', 'scope' => 'ما أُسند إليّ',
            'hero' => BoardKit::metric('زيارات وتقييمات متأخرة', (clone $scheduled)->where('scheduled_on', '<', $today)->count(), 'down', url: '/orphans/registry'),
            'kpis' => [
                BoardKit::metric('زيارات اليوم', (clone $scheduled)->where('scheduled_on', $today)->count()),
                BoardKit::metric('متابعات مجدولة', (clone $scheduled)->where('kind', 'follow_up')->count()),
                BoardKit::metric('خلال 7 أيام قادمة', (clone $scheduled)->whereBetween('scheduled_on', [$today, today()->addDays(7)->toDateString()])->count()),
            ],
            'trend' => BoardKit::trend('المنجز مقابل المجدول أسبوعيًا', 'المجدول حسب موعد الزيارة؛ المنجز حسب تاريخ الإنجاز. للزيارات المسندة إليك فقط.', [
                ['label' => 'مجدول', 'values' => BoardKit::weekly(clone $mine, 'scheduled_on')],
                ['label' => 'منجز', 'values' => BoardKit::weekly((clone $mine)->whereNotNull('completed_on'), 'completed_on')],
            ]),
            'bars' => BoardKit::bars('حالاتي المجدولة حسب نوع المتابعة', 'الزيارات والتقييمات المجدولة المسندة إليك.',
                (clone $scheduled)->groupBy('kind')->selectRaw('kind, count(*) AS value')->get()->map(fn (object $r): array => ['label' => $kinds[$r->kind] ?? $r->kind, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('زياراتي', [['key' => 'reference', 'label' => 'مرجع الأسرة/الملف'], ['key' => 'type', 'label' => 'النوع'], ['key' => 'date', 'label' => 'الموعد']], $rows, 'لا توجد زيارات مجدولة لك.'),
            'actions' => $this->quick($user),
        ];
    }

    private function sponsorship(): array
    {
        $today = today()->toDateString();
        $soon = today()->addDays(60)->toDateString();
        $s = DB::table('sponsorships');
        $active = DB::table('sponsorship_beneficiaries as b')->join('sponsorships as s', 's.id', '=', 'b.sponsorship_id')->whereIn('s.status', ['active', 'partial'])
            ->whereNotNull('b.orphan_id')->where(fn ($q) => $q->whereNull('b.ends_on')->orWhere('b.ends_on', '>=', $today));
        $rows = DB::table('sponsorships as s')->leftJoin('sponsors as p', 'p.id', '=', 's.sponsor_id')
            ->where(fn ($q) => $q->whereIn('s.status', ['draft', 'suspended'])->orWhere(fn ($w) => $w->whereIn('s.status', ['active', 'partial'])->whereBetween('s.ends_on', [$today, $soon])))
            ->orderBy('s.ends_on')->limit(50)->get(['s.public_id', 's.reference_no', 's.status', 's.starts_on', 's.ends_on', 'p.name as sponsor'])
            ->map(fn (object $r): array => ['cells' => ['reference' => $r->reference_no, 'type' => $r->sponsor ?: '—', 'starts' => $r->starts_on, 'ends' => $r->ends_on],
                'status' => ['label' => Aid::SPONSORSHIP_STATUS[$r->status] ?? $r->status, 'tone' => $r->status === 'suspended' ? 'muted' : ($r->status === 'draft' ? 'warning' : 'info')],
                'url' => '/orphans/aid/sponsorships/'.$r->public_id, 'action' => $r->status === 'draft' ? 'استكمال' : 'متابعة', 'overdue' => false])->all();

        return [
            'key' => 'orphans-sponsorship', 'title' => 'الكفالات', 'decision' => 'أي الكفالات والتغطيات تحتاج متابعة؟', 'scope' => 'كفالات القسم',
            'hero' => BoardKit::metric('أيتام مكفولون حاليًا (دون تكرار)', (clone $active)->distinct()->count('b.orphan_id'), 'up', detail: 'الكفالة التزام وليست مبلغًا مقبوضًا', url: '/orphans/aid'),
            'kpis' => [
                BoardKit::metric('تنتهي خلال 60 يومًا', (clone $s)->whereIn('status', ['active', 'partial'])->whereBetween('ends_on', [$today, $soon])->count(), 'down'),
                BoardKit::metric('مسودات لم تُفعّل', (clone $s)->where('status', 'draft')->count(), 'down'),
                BoardKit::metric('موقوفة أو منتهية المدة', (clone $s)->whereIn('status', ['suspended', 'expired'])->count(), 'down'),
            ],
            'trend' => BoardKit::trend('كفالات فُعّلت أسبوعيًا', 'عدد الكفالات التي فُعّلت (تاريخ التفعيل). التحصيل يُتابع في الحسابات ولا يُحتسب هنا.', [
                ['label' => 'فُعّلت', 'values' => BoardKit::weekly((clone $s)->whereNotNull('activated_at'), 'activated_at')],
            ]),
            'bars' => BoardKit::bars('الكفالات النشطة حسب البرنامج', 'الكفالات النشطة أو الجزئية حسب برنامج الكفالة.',
                DB::table('sponsorships as s')->leftJoin('master_data_values as v', 'v.id', '=', 's.program_value_id')->whereIn('s.status', ['active', 'partial'])
                    ->groupBy('v.name_ar')->selectRaw("COALESCE(v.name_ar, 'دون برنامج') AS label, count(*) AS value")->get()->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('كفالات تحتاج متابعة', [['key' => 'reference', 'label' => 'المرجع'], ['key' => 'type', 'label' => 'الكافل'], ['key' => 'starts', 'label' => 'البداية'], ['key' => 'ends', 'label' => 'النهاية']],
                $rows, 'لا توجد كفالات تحتاج متابعة.', '/orphans/aid'),
            'actions' => [['label' => 'الكفالات والمساعدات', 'url' => '/orphans/aid', 'icon' => 'heart']],
        ];
    }

    /** @return list<int> campaigns this user manages or is a current member of */
    private function campaignIds(User $user): array
    {
        $today = today()->toDateString();

        return DB::table('campaigns')->where('manager_user_id', $user->id)->pluck('id')
            ->merge(DB::table('campaign_members')->where('user_id', $user->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->pluck('campaign_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function campaigns(User $user): array
    {
        $scoped = $this->access->isStaff($user) || $this->access->isHead($user) ? null : $this->campaignIds($user);
        $campaigns = DB::table('campaigns')->whereIn('status', ['pending_executive', 'approved', 'distributing', 'reconciling'])->when($scoped !== null, fn ($q) => $q->whereIn('id', $scoped ?: [0]));
        $ids = (clone $campaigns)->pluck('id')->all();
        $lists = DB::table('campaign_lists')->whereIn('campaign_id', $ids ?: [0])->where('status', 'approved');
        $entries = DB::table('campaign_list_entries as e')->whereIn('e.list_id', (clone $lists)->pluck('id')->all() ?: [0])->where('e.kind', 'primary');
        $delivered = DB::table('campaign_deliveries as d')->whereIn('d.campaign_id', $ids ?: [0])->where('d.kind', 'delivered')
            ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as x')->whereColumn('x.corrects_id', 'd.id'));
        $outstanding = (clone $entries)->whereNotExists(fn ($q) => $q->from('campaign_deliveries as d')->whereColumn('d.list_entry_id', 'e.id')->where('d.kind', 'delivered')
            ->whereNotExists(fn ($w) => $w->from('campaign_deliveries as x')->whereColumn('x.corrects_id', 'd.id')));
        $rows = (clone $campaigns)->orderByDesc('id')->limit(30)->get(['id', 'public_id', 'reference_no', 'title', 'status'])->map(function (object $c): array {
            $list = DB::table('campaign_lists')->where('campaign_id', $c->id)->whereIn('status', ['approved', 'pending_executive'])->orderByDesc('version')->first(['id', 'version', 'status', 'entry_count']);
            $done = DB::table('campaign_deliveries')->where('campaign_id', $c->id)->where('kind', 'delivered')->count();

            return ['cells' => ['reference' => $c->reference_no, 'type' => $c->title, 'version' => $list ? 'الإصدار '.$list->version.($list->status === 'approved' ? ' (معتمد ومقفل)' : ' (بانتظار المدير التنفيذي)') : 'لا كشف معتمد',
                'quantity' => $list ? $done.' / '.$list->entry_count : '—'], 'status' => ['label' => Campaigns::STATUS[$c->status] ?? $c->status, 'tone' => $c->status === 'pending_executive' ? 'warning' : 'info'],
                'url' => '/orphans/campaigns/'.$c->public_id, 'action' => 'فتح', 'overdue' => false];
        })->all();

        return [
            'key' => 'orphans-campaigns', 'title' => 'الحملات الإغاثية', 'decision' => 'هل يمكن تنفيذ الحملة المعتمدة بدقة وفي موعدها؟', 'scope' => $scoped === null ? 'حملات القسم' : 'الحملات المسندة إليّ',
            'hero' => BoardKit::metric('مخصصات معتمدة بانتظار تسليم مؤكد', (clone $outstanding)->count(), 'down', detail: 'من الكشوف المعتمدة فقط؛ الترشيح لا يُعد تسليمًا', url: '/orphans/campaigns'),
            'kpis' => [
                BoardKit::metric('وحدات سُلّمت فعلًا', (int) (clone $delivered)->sum('d.quantity'), 'up', detail: 'تسليمات مؤكدة غير مصححة'),
                BoardKit::metric('تنبيهات تكرار مفتوحة', DB::table('campaign_duplicate_alerts')->whereIn('campaign_id', $ids ?: [0])->where('status', 'open')->count(), 'down'),
                BoardKit::metric('كشوف بانتظار المدير التنفيذي', DB::table('campaign_lists')->whereIn('campaign_id', $ids ?: [0])->where('status', 'pending_executive')->count(), null, detail: 'الاعتماد للمدير التنفيذي وحده'),
            ],
            'trend' => BoardKit::trend('الوحدات المسلّمة أسبوعيًا', 'مجموع كميات التسليم المؤكدة (دون المصححة). الكميات المخططة تظهر في جدول الحملات.', [
                ['label' => 'مسلّم', 'values' => BoardKit::weekly(clone $delivered, 'd.occurred_at', sum: 'd.quantity')],
            ], unit: 'وحدة'),
            'bars' => BoardKit::bars('المخصصات المعتمدة حسب المحافظة', 'مستفيدو الكشوف المعتمدة (الأساسية) حسب المحافظة.',
                (clone $entries)->leftJoin('master_data_values as g', 'g.id', '=', 'e.governorate_value_id')->groupBy('g.name_ar')->selectRaw("COALESCE(g.name_ar, 'غير محدد') AS label, count(*) AS value")
                    ->get()->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('الحملات الجارية', [['key' => 'reference', 'label' => 'المرجع'], ['key' => 'type', 'label' => 'الحملة'], ['key' => 'version', 'label' => 'الكشف'], ['key' => 'quantity', 'label' => 'سُلّم / المعتمد']],
                $rows, 'لا توجد حملات جارية.', '/orphans/campaigns'),
            'actions' => [['label' => 'الحملات الإغاثية', 'url' => '/orphans/campaigns', 'icon' => 'boxes']],
        ];
    }

    private function actionTable(array $items, string $title, bool $all): array
    {
        $rows = array_map(fn (array $i): array => ['cells' => ['type' => $i['source'], 'subject' => $i['subject'], 'holder' => $i['assignee'] ?: 'غير مسند', 'due' => $i['due'] ?? '—'],
            'status' => $i['overdue'] ? ['label' => 'متأخر', 'tone' => 'danger'] : ['label' => $i['status'], 'tone' => 'info'], 'url' => $i['url'], 'action' => ($i['readonly'] ?? false) ? 'متابعة' : 'فتح', 'overdue' => (bool) $i['overdue']],
            array_slice($items, 0, 60));

        return BoardKit::table($title, [['key' => 'type', 'label' => 'نوع الحالة'], ['key' => 'subject', 'label' => 'المرجع والاسم'], ['key' => 'holder', 'label' => 'الموظف المسؤول'], ['key' => 'due', 'label' => 'الاستحقاق']],
            $rows, $all ? 'لا يوجد ما يحتاج إلى إجراء في القسم.' : 'لا يوجد ما ينتظر إجراءك.', '/orphans');
    }

    /** Quick actions the user may take; the smart import is one of them (never a menu entry). */
    private function quick(User $user): array
    {
        return array_map(fn (array $q): array => ['label' => $q['label'], 'url' => $q['url'], 'icon' => $q['icon'] ?? 'folder'], $this->dashboard->quickActions($user));
    }
}
