<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\Configuration\AuditActionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * «الرئيسية» of the department: one dashboard rendered by role (head/executive: oversight; staff: today's work).
 *
 * KPI definitions (also returned to the page as «كيف يُحسب»):
 * - registered: distinct orphan files (orphans.id) not ended/archived — the same base as the registry list. Separately:
 *   files with status «معتمد» and files with 100% completeness. Import staging rows are never counted here.
 * - portal: portal applications (portal_applications.id) by status: new = submitted; awaiting review = under_review +
 *   resubmitted; completion requested = needs_completion.
 * - sponsored: distinct orphans with an individual sponsorship beneficiary line in force today on an active/partial
 *   sponsorship (commitments, not receipts). Unavailable until the sponsorship module answers.
 * Charts count the same rows as the registry with the matching filter (geography of the child's current address; age
 * bands from «ثوابت النظام»); missing values are shown as their own slice.
 */
final class Dashboard
{
    public function __construct(private readonly OrphanAccess $access, private readonly RegistryQueries $queries) {}

    public function forUser(User $user): array
    {
        $oversight = $this->access->isHead($user) || $this->access->isExecutive($user) || $user->isSystemAdmin();

        return [
            'mode' => $oversight ? 'oversight' : 'staff',
            'kpis' => $oversight ? $this->kpis() : null,
            'charts' => $oversight ? ['geography' => $this->geography(), 'age' => $this->ages()] : null,
            'actions' => $this->actionItems($user, $oversight),
            'workload' => $this->access->canAssign($user) || $this->access->isExecutive($user) ? $this->workload() : null,
            'mine' => $this->myWork($user),
            'recent' => $this->recent($user),
            'quick' => $this->quickActions($user),
            'definitions' => self::DEFINITIONS,
        ];
    }

    public const DEFINITIONS = [
        'registered' => 'عدد ملفات الأيتام القائمة (عدا المنتهية والمؤرشفة)، طفل واحد لكل ملف. «المعتمد» = ملفات بحالة معتمد؛ «المكتمل» = اكتمال 100%. السجلات المرحلية للاستيراد لا تُحتسب.',
        'portal' => 'عدد طلبات بوابة الأوصياء حسب حالتها: جديد = مُقدم ولم يُستلم؛ بانتظار المراجعة = قيد المراجعة أو أُعيد تقديمه؛ بانتظار الاستكمال = طُلب من الوصي استكماله.',
        'sponsored' => 'عدد الأيتام (دون تكرار) المرتبطين اليوم بكفالة فردية نافذة بحالة نشطة أو جزئية. الكفالة التزام وليست مبلغًا مقبوضًا؛ كفالات الأسر تُعرض منفصلة.',
        'geography' => 'توزيع ملفات الأيتام القائمة حسب محافظة العنوان الحالي لليتيم؛ «غير محدد» = لا عنوان حالي مسجل.',
        'age' => 'توزيع ملفات الأيتام القائمة حسب الفئات العمرية المعرفة في «ثوابت النظام» (بالسنوات الكاملة اليوم)؛ «غير متوفر» = لا تاريخ ميلاد.',
    ];

    public function kpis(): array
    {
        $base = $this->queries->orphanQuery([]);
        $row = (clone $base)->selectRaw("count(*) as total, count(*) filter (where o.status = 'active') as approved, count(*) filter (where o.status = 'provisional') as provisional, count(*) filter (where o.completeness = 100) as complete")->first();
        $portal = DB::table('portal_applications')->selectRaw("count(*) filter (where status = 'submitted') as new, count(*) filter (where status in ('under_review','resubmitted')) as review,
            count(*) filter (where status = 'needs_completion') as completion")->first();
        $sponsored = null;
        foreach (app()->tagged('orphans.sponsorship_coverage') as $provider) {
            $sponsored = $provider->coverage();
        }

        return [
            'registered' => ['total' => (int) $row->total, 'approved' => (int) $row->approved, 'provisional' => (int) $row->provisional, 'complete' => (int) $row->complete],
            'portal' => ['new' => (int) $portal->new, 'review' => (int) $portal->review, 'completion' => (int) $portal->completion],
            'sponsored' => $sponsored,
        ];
    }

    /** @return list<array{key: string, label: string, count: int, filter: array<string, string>}> */
    public function geography(): array
    {
        $rows = $this->queries->orphanQuery([])->selectRaw('a.governorate_value_id as id, gv.name_ar as name, count(*) as total')->groupBy('a.governorate_value_id', 'gv.name_ar')
            ->orderByDesc('total')->get();

        return $rows->map(fn ($r): array => ['key' => (string) ($r->id ?? 'unknown'), 'label' => $r->name ?? 'غير محدد', 'count' => (int) $r->total,
            'filter' => ['governorate' => (string) ($r->id ?? 'unknown')]])->all();
    }

    public function ages(): array
    {
        $bands = $this->queries->values('orphan_age_bands');
        $result = [];
        $covered = 0;
        foreach ($bands as $band) {
            $count = $this->queries->orphanQuery(['age_band' => (string) $band['id']])->count();
            $covered += $count;
            $result[] = ['key' => (string) $band['id'], 'label' => $band['name'], 'count' => $count, 'filter' => ['age_band' => (string) $band['id']]];
        }
        $unknown = $this->queries->orphanQuery(['age_band' => 'unknown'])->count();
        $result[] = ['key' => 'unknown', 'label' => 'تاريخ الميلاد غير متوفر', 'count' => $unknown, 'filter' => ['age_band' => 'unknown']];
        // Ages outside every configured band are shown, never dropped.
        $outside = $this->queries->orphanQuery([])->count() - $covered - $unknown;
        if ($outside > 0) {
            $result[] = ['key' => 'outside', 'label' => 'خارج الفئات المعرفة', 'count' => $outside, 'filter' => []];
        }

        return $result;
    }

    /**
     * One feed: overdue first (by due date), then business urgency, then submission time. Staff see their own items
     * (plus unassigned work they may take); the head and the executive see the whole department.
     *
     * @return list<array<string, mixed>>
     */
    public function actionItems(User $user, bool $all): array
    {
        $items = [];
        $today = today()->toDateString();
        $mineOrFree = fn ($q, string $column) => $all ? $q : $q->where(fn ($w) => $w->where($column, $user->id)->orWhereNull($column));
        if ($this->access->allows($user, 'portal') || $all) {
            $apps = $mineOrFree(DB::table('portal_applications as a')->leftJoin('users as u', 'u.id', '=', 'a.assigned_to'), 'a.assigned_to')
                ->whereIn('a.status', ['submitted', 'under_review', 'resubmitted'])->orderBy('a.submitted_at')->limit(40)
                ->get(['a.public_id', 'a.reference_no', 'a.full_name', 'a.status', 'a.due_on', 'a.submitted_at', 'u.name as assignee']);
            foreach ($apps as $a) {
                $items[] = ['source' => 'طلب بوابة', 'subject' => $a->reference_no.' — '.$a->full_name, 'assignee' => $a->assignee, 'due' => $a->due_on,
                    'status' => PortalApplications::STATUS[$a->status] ?? $a->status, 'url' => '/orphans/reviews/'.$a->public_id, 'urgency' => $a->status === 'resubmitted' ? 2 : 1, 'at' => $a->submitted_at];
            }
        }
        $claims = DB::table('orphan_guardianships as g')->join('orphans as o', 'o.id', '=', 'g.orphan_id')->join('persons as p', 'p.id', '=', 'o.person_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.assigned_to')->whereIn('g.status', ['claimed', 'pending'])->whereNotIn('o.status', ['ended', 'archived'])
            ->whereNotExists(fn ($q) => $q->from('orphan_guardianships as x')->whereColumn('x.orphan_id', 'g.orphan_id')->where('x.status', 'approved'))
            ->when(! $all, fn ($q) => $q->where('o.assigned_to', $user->id))->orderBy('g.created_at')->limit(30)
            ->get(['o.public_id', 'o.reference_no', 'p.full_name', 'g.created_at', 'u.name as assignee']);
        foreach ($claims as $c) {
            $items[] = ['source' => 'وصاية بانتظار التحقق', 'subject' => $c->reference_no.' — '.$c->full_name, 'assignee' => $c->assignee, 'due' => null,
                'status' => 'لا توجد وصاية معتمدة', 'url' => '/orphans/registry/orphans/'.$c->public_id.'?tab=family', 'urgency' => 1, 'at' => $c->created_at];
        }
        $visits = DB::table('social_assessments as s')->leftJoin('orphans as o', 'o.id', '=', 's.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
            ->leftJoin('users as u', 'u.id', '=', 's.assigned_to')->where('s.status', 'scheduled')->when(! $all, fn ($q) => $q->where('s.assigned_to', $user->id))
            ->orderBy('s.scheduled_on')->limit(30)->get(['o.public_id', 'p.full_name', 's.kind', 's.scheduled_on', 's.created_at', 'u.name as assignee']);
        foreach ($visits as $v) {
            $items[] = ['source' => ['visit' => 'زيارة ميدانية', 'assessment' => 'تقييم اجتماعي', 'follow_up' => 'متابعة'][$v->kind] ?? 'زيارة', 'subject' => $v->full_name ?? 'أسرة',
                'assignee' => $v->assignee, 'due' => $v->scheduled_on, 'status' => 'مجدولة', 'url' => $v->public_id ? '/orphans/registry/orphans/'.$v->public_id.'?tab=assessment' : '/orphans/registry',
                'urgency' => 1, 'at' => $v->created_at];
        }
        // Completed visits with a needs level wait for the head's review before they feed campaign scoring (ORP-19).
        if ($this->access->canDecide($user) && Schema::hasColumn('social_assessments', 'review_outcome')) {
            $toReview = DB::table('social_assessments as s')->leftJoin('orphans as o', 'o.id', '=', 's.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
                ->leftJoin('users as u', 'u.id', '=', 's.completed_by')->where('s.status', 'completed')->whereNotNull('s.needs_level_value_id')->whereNull('s.review_outcome')
                ->where('s.completed_by', '<>', $user->id)->orderBy('s.completed_on')->limit(30)->get(['o.public_id', 'p.full_name', 's.kind', 's.completed_on', 's.updated_at', 'u.name as assignee']);
            foreach ($toReview as $v) {
                $items[] = ['source' => 'زيارة بانتظار مراجعتك', 'subject' => $v->full_name ?? 'أسرة', 'assignee' => $v->assignee, 'due' => null, 'status' => 'منجزة، لم تُراجَع',
                    'url' => $v->public_id ? '/orphans/registry/orphans/'.$v->public_id.'?tab=assessment' : '/orphans/registry', 'urgency' => 1, 'at' => $v->updated_at];
            }
        }
        $due = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->leftJoin('users as u', 'u.id', '=', 'o.assigned_to')->whereNotNull('o.review_due_on')
            ->where('o.review_due_on', '<=', today()->addDays(7)->toDateString())->whereNotIn('o.status', ['ended', 'archived'])->when(! $all, fn ($q) => $q->where('o.assigned_to', $user->id))
            ->orderBy('o.review_due_on')->limit(30)->get(['o.public_id', 'o.reference_no', 'p.full_name', 'o.review_due_on', 'o.updated_at', 'u.name as assignee']);
        foreach ($due as $d) {
            $items[] = ['source' => 'مراجعة ملف', 'subject' => $d->reference_no.' — '.$d->full_name, 'assignee' => $d->assignee, 'due' => $d->review_due_on, 'status' => 'موعد مراجعة',
                'url' => '/orphans/registry/orphans/'.$d->public_id, 'urgency' => 1, 'at' => $d->updated_at];
        }
        $appeals = DB::table('orphan_appeals as a')->leftJoin('users as u', 'u.id', '=', 'a.assigned_to')->whereIn('a.status', ['open', 'under_review'])
            ->when(! $all, fn ($q) => $q->where('a.assigned_to', $user->id))->orderBy('a.created_at')->limit(20)->get(['a.reference_no', 'a.kind', 'a.created_at', 'a.status', 'u.name as assignee']);
        foreach ($appeals as $a) {
            $items[] = ['source' => 'اعتراض', 'subject' => $a->reference_no, 'assignee' => $a->assignee, 'due' => null, 'status' => $a->status === 'open' ? 'مفتوح' : 'قيد المراجعة',
                'url' => '/orphans/reviews?tab=appeals', 'urgency' => 2, 'at' => $a->created_at];
        }
        if ($all) {
            $incomplete = $this->queries->orphanQuery(['completeness' => 'incomplete'])->count();
            if ($incomplete > 0) {
                $items[] = ['source' => 'ملفات غير مكتملة', 'subject' => $incomplete.' ملف يحتاج استكمال بيانات', 'assignee' => null, 'due' => null, 'status' => 'متابعة',
                    'url' => '/orphans/registry?completeness=incomplete', 'urgency' => 0, 'at' => null];
            }
        }
        foreach (app()->tagged('orphans.action_items') as $provider) {
            array_push($items, ...$provider->items($user, $all));
        }
        foreach ($items as &$item) {
            $item['overdue'] = $item['due'] !== null && $item['due'] < $today;
        }
        unset($item);
        usort($items, fn (array $a, array $b): int => [! $a['overdue'], $a['overdue'] ? $a['due'] : '9999', -$a['urgency'], $a['due'] ?? '9999', (string) $a['at']]
            <=> [! $b['overdue'], $b['overdue'] ? $b['due'] : '9999', -$b['urgency'], $b['due'] ?? '9999', (string) $b['at']]);

        return array_slice($items, 0, 60);
    }

    /** Per staff member: open vs completed vs overdue assignments and the average decision time of portal applications. */
    public function workload(): array
    {
        $staff = $this->access->staff();
        $ids = array_column($staff, 'id');
        if ($ids === []) {
            return [];
        }
        $today = today()->toDateString();
        $apps = DB::table('portal_applications')->whereIn('assigned_to', $ids)->groupBy('assigned_to')->selectRaw("assigned_to,
            count(*) filter (where status in ('submitted','under_review','needs_completion','resubmitted')) as open,
            count(*) filter (where status in ('submitted','under_review','resubmitted') and due_on < ?) as overdue,
            count(*) filter (where status in ('completed','rejected') and decided_at >= ?) as done,
            avg(extract(epoch from (decided_at - submitted_at)) / 86400) filter (where status in ('completed','rejected')) as avg_days", [$today, now()->subDays(30)])->get()->keyBy('assigned_to');
        $files = DB::table('orphans')->whereIn('assigned_to', $ids)->whereNotIn('status', ['ended', 'archived'])->groupBy('assigned_to')
            ->selectRaw('assigned_to, count(*) as files, count(*) filter (where completeness < 100) as incomplete, count(*) filter (where review_due_on < ?) as overdue', [$today])->get()->keyBy('assigned_to');
        $visits = DB::table('social_assessments')->whereIn('assigned_to', $ids)->groupBy('assigned_to')->selectRaw("assigned_to,
            count(*) filter (where status = 'scheduled') as scheduled, count(*) filter (where status = 'scheduled' and scheduled_on < ?) as overdue,
            count(*) filter (where status = 'completed' and completed_on >= ?) as done", [$today, today()->subDays(30)->toDateString()])->get()->keyBy('assigned_to');

        return array_map(function (array $member) use ($apps, $files, $visits): array {
            $a = $apps->get($member['id']);
            $f = $files->get($member['id']);
            $v = $visits->get($member['id']);

            return [...$member, 'applications_open' => (int) ($a->open ?? 0), 'applications_done' => (int) ($a->done ?? 0), 'avg_days' => isset($a->avg_days) ? round((float) $a->avg_days, 1) : null,
                'files' => (int) ($f->files ?? 0), 'files_incomplete' => (int) ($f->incomplete ?? 0), 'visits_scheduled' => (int) ($v->scheduled ?? 0), 'visits_done' => (int) ($v->done ?? 0),
                'overdue' => (int) ($a->overdue ?? 0) + (int) ($f->overdue ?? 0) + (int) ($v->overdue ?? 0)];
        }, $staff);
    }

    /** «مهامي اليوم»: the user's own assignments, overdue first. */
    public function myWork(User $user): array
    {
        return array_values(array_filter($this->actionItems($user, false), fn (array $item): bool => $item['assignee'] !== null));
    }

    public function recent(User $user): array
    {
        return DB::table('audit_logs')->where('actor_id', $user->id)->whereIn('subject_type', ['orphans', 'orphan_guardians', 'orphan_guardianships', 'persons'])
            ->where('action', 'like', 'orphans.%')->orderByDesc('id')->limit(8)->get(['action', 'subject_type', 'subject_id', 'created_at'])
            ->map(function ($row): array {
                $url = match ($row->subject_type) {
                    'orphans' => ($id = DB::table('orphans')->where('id', $row->subject_id)->value('public_id')) ? '/orphans/registry/orphans/'.$id : null,
                    'orphan_guardians' => ($id = DB::table('orphan_guardians')->where('id', $row->subject_id)->value('public_id')) ? '/orphans/registry/guardians/'.$id : null,
                    default => null,
                };
                $name = match ($row->subject_type) {
                    'orphans' => DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('o.id', $row->subject_id)->value('p.full_name'),
                    'orphan_guardians' => DB::table('orphan_guardians as g')->join('persons as p', 'p.id', '=', 'g.person_id')->where('g.id', $row->subject_id)->value('p.full_name'),
                    default => null,
                };

                return ['action' => app(AuditActionCatalog::class)->label($row->action), 'name' => $name, 'url' => $url, 'at' => $row->created_at];
            })->all();
    }

    public function quickActions(User $user): array
    {
        $actions = [];
        if ($this->access->canEdit($user)) {
            $actions[] = ['label' => 'تسجيل أسرة', 'url' => '/orphans/registrations', 'icon' => 'users'];
            $actions[] = ['label' => 'إضافة وصي', 'url' => '/orphans/registry/guardians/create', 'icon' => 'users'];
            $actions[] = ['label' => 'إضافة يتيم', 'url' => '/orphans/registry/orphans/create', 'icon' => 'plus'];
        }
        if ($this->access->allows($user, 'portal')) {
            $actions[] = ['label' => 'مراجعة طلب', 'url' => '/orphans/reviews?assigned=me', 'icon' => 'inbox'];
        }
        $actions[] = ['label' => 'البحث عن ملف', 'url' => '/orphans/registry', 'icon' => 'search'];
        if ($this->access->allows($user, 'import') && Route::has('orphans.import.index')) {
            $actions[] = ['label' => 'الاستيراد الذكي', 'url' => route('orphans.import.index', [], false), 'icon' => 'import',
                'hint' => 'استيراد بيانات الأيتام والأوصياء والكشوفات مع التحقق المسبق واكتشاف الأخطاء والتكرار'];
        }

        return $actions;
    }
}
