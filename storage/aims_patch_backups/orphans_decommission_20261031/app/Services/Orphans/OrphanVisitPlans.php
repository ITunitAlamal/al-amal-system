<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «خطط الزيارات الميدانية» (requirement Q31): the head plans a period's visits and distributes them among the
 * researchers. Each plan row becomes an ordinary social visit (same report, attestation and head review), linked to
 * the family's open follow-up. Distribution keeps the researcher who already follows the family when the load allows,
 * otherwise it balances the load. Researchers see their visits in «مساحتي» and get one notification per batch.
 */
final class OrphanVisitPlans
{
    /** Visit states that still need work (a family with one of these is not planned again). */
    public const OPEN_VISITS = ['planned', 'submitted', 'returned'];

    public const STATE_LABELS = ['upcoming' => 'لم تبدأ', 'current' => 'جارية', 'ended' => 'انتهت'];

    private const MAX_ROWS = 200;

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanVisits $visits,
        private readonly OrphanFollowups $followups,
        private readonly OrphanRegistry $registry,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('orphan_visit_plans');
    }

    /** Distributing researchers is the head's office (as for a single visit). */
    public function canManage(?User $user): bool
    {
        return $this->access->isHead($user);
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): string
    {
        abort_unless($this->canManage($actor), 403, 'إعداد خطط الزيارات وتوزيعها لرئيس القسم.');
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['title' => 'عنوان الخطة', 'starts_on' => 'بداية الفترة', 'ends_on' => 'نهاية الفترة', 'notes' => 'ملاحظات'])->validate();
        if (Carbon::parse($data['starts_on'])->diffInDays(Carbon::parse($data['ends_on'])) > 92) {
            throw ValidationException::withMessages(['ends_on' => 'مدة الخطة لا تتجاوز ثلاثة أشهر.']);
        }

        return DB::transaction(function () use ($actor, $data): string {
            $publicId = (string) Str::ulid();
            $id = DB::table('orphan_visit_plans')->insertGetId(['public_id' => $publicId, 'reference_no' => $this->registry->number('orphan_visit_plan_ref_seq', 'VPL-'),
                'title' => trim($data['title']), 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.visit_plan.created', 'orphan_visit_plans', $id, null, ['title' => trim($data['title']), 'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on']], $actor->id, request()?->ip());

            return $publicId;
        });
    }

    /** @return list<array<string, mixed>> plans, latest first, with their progress */
    public function index(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('orphan_visit_plans as p')->leftJoin('users as u', 'u.id', '=', 'p.created_by')->orderByDesc('p.starts_on')->orderByDesc('p.id')->limit(100)
            ->get(['p.id', 'p.public_id', 'p.reference_no', 'p.title', 'p.starts_on', 'p.ends_on', 'u.name as author',
                DB::raw("(select count(*) from orphan_social_visits v where v.plan_id = p.id and v.status <> 'cancelled') as visits"),
                DB::raw("(select count(*) from orphan_social_visits v where v.plan_id = p.id and v.status = 'reviewed') as reviewed")])
            ->map(fn (object $plan): array => $this->presentPlan($plan) + ['visits' => (int) $plan->visits, 'reviewed' => (int) $plan->reviewed, 'author' => $plan->author])->all();
    }

    public function find(string $publicId): object
    {
        $plan = DB::table('orphan_visit_plans as p')->leftJoin('users as u', 'u.id', '=', 'p.created_by')->where('p.public_id', $publicId)
            ->first(['p.*', 'u.name as author']);
        abort_unless($plan, 404);

        return $plan;
    }

    /** @return array<string, mixed> */
    public function presentPlan(object $plan): array
    {
        $today = $this->today();
        $starts = substr((string) $plan->starts_on, 0, 10);
        $ends = substr((string) $plan->ends_on, 0, 10);
        $state = $starts > $today ? 'upcoming' : ($ends < $today ? 'ended' : 'current');

        return ['id' => (int) $plan->id, 'public_id' => $plan->public_id, 'reference_no' => $plan->reference_no, 'title' => $plan->title, 'starts_on' => $starts, 'ends_on' => $ends,
            'state' => $state, 'state_label' => self::STATE_LABELS[$state]];
    }

    /**
     * The plan's visits and the progress of each researcher.
     *
     * @return array{visits: list<array<string, mixed>>, researchers: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function progress(object $plan): array
    {
        $today = $this->today();
        $rows = DB::table('orphan_social_visits as v')->join('orphan_households as h', 'h.id', '=', 'v.household_id')->leftJoin('users as u', 'u.id', '=', 'v.researcher_id')
            ->where('v.plan_id', $plan->id)->orderBy('u.name')->orderBy('v.scheduled_on')
            ->get(['v.id', 'v.reference_no', 'v.status', 'v.scheduled_on', 'v.visited_on', 'v.researcher_id', 'v.workflow_instance_id', 'v.cancel_reason',
                'u.name as researcher', 'h.reference_no as household', 'h.public_id as household_public_id']);
        $visits = $rows->map(function (object $visit) use ($today): array {
            $scheduled = substr((string) $visit->scheduled_on, 0, 10);

            return ['id' => (int) $visit->id, 'reference_no' => $visit->reference_no, 'status' => $visit->status, 'status_label' => OrphanVisits::STATUS_LABELS[$visit->status] ?? $visit->status,
                'scheduled_on' => $scheduled, 'researcher' => $visit->researcher, 'researcher_id' => (int) $visit->researcher_id, 'household' => $visit->household,
                'household_public_id' => $visit->household_public_id, 'cancel_reason' => $visit->cancel_reason,
                'overdue' => $visit->status === 'planned' && $scheduled < $today,
                'manageable' => $visit->status === 'planned' && ! $visit->workflow_instance_id, 'reassignable' => $visit->status === 'planned' && ! $visit->visited_on && ! $visit->workflow_instance_id];
        });
        $empty = ['total' => 0, 'planned' => 0, 'submitted' => 0, 'returned' => 0, 'reviewed' => 0, 'cancelled' => 0, 'overdue' => 0];
        $researchers = $visits->groupBy('researcher_id')->map(function ($items) use ($empty): array {
            $counts = $empty;
            foreach ($items as $item) {
                $counts[$item['status']]++;
                $counts['total'] += $item['status'] === 'cancelled' ? 0 : 1;
                $counts['overdue'] += $item['overdue'] ? 1 : 0;
            }

            return ['id' => $items->first()['researcher_id'], 'name' => $items->first()['researcher'], 'completion' => $counts['total'] ? (int) round($counts['reviewed'] * 100 / $counts['total']) : 0] + $counts;
        })->values();
        $totals = $empty;
        foreach ($researchers as $researcher) {
            foreach (array_keys($empty) as $key) {
                $totals[$key] += $researcher[$key];
            }
        }

        return ['visits' => $visits->all(), 'researchers' => $researchers->all(), 'totals' => $totals + ['completion' => $totals['total'] ? (int) round($totals['reviewed'] * 100 / $totals['total']) : 0]];
    }

    /**
     * Families that can be added to a plan: under study or active, with no visit still in progress. Families whose
     * follow-up falls due by the end of the plan come first.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(object $plan, int $limit = 300): array
    {
        $ends = substr((string) $plan->ends_on, 0, 10);
        $rows = DB::table('orphan_households as h')->leftJoin('orphan_followups as f', fn ($join) => $join->on('f.household_id', '=', 'h.id')->where('f.status', 'open'))
            ->leftJoin('users as a', 'a.id', '=', 'f.assigned_to')
            ->whereIn('h.status', OrphanFollowups::FOLLOWED_STATUSES)
            ->whereNotExists(fn (Builder $q) => $q->from('orphan_social_visits as v')->whereColumn('v.household_id', 'h.id')->whereIn('v.status', self::OPEN_VISITS))
            ->orderByRaw('f.due_on IS NULL')->orderBy('f.due_on')->orderBy('h.id')->limit($limit)
            ->get(['h.id', 'h.reference_no', 'h.public_id', 'h.family_name', 'f.due_on', 'f.reason', 'f.assigned_to', 'a.name as assignee',
                DB::raw("(select v.researcher_id from orphan_social_visits v where v.household_id = h.id and v.status = 'reviewed' order by v.visited_on desc limit 1) as last_researcher"),
                DB::raw("(select max(v.visited_on) from orphan_social_visits v where v.household_id = h.id and v.status = 'reviewed') as last_visit")]);

        return $rows->map(function (object $row) use ($ends): array {
            $due = $row->due_on ? substr((string) $row->due_on, 0, 10) : null;

            return ['public_id' => $row->public_id, 'reference_no' => $row->reference_no, 'family_name' => $this->decrypt($row->family_name), 'due_on' => $due,
                'due_in_plan' => $due !== null && $due <= $ends, 'followup_reason' => $row->reason, 'assignee' => $row->assignee,
                'last_visit' => $row->last_visit ? substr((string) $row->last_visit, 0, 10) : null];
        })->all();
    }

    /**
     * Researchers who can be given visits, with how many open visits each already holds.
     *
     * @return list<array{id: int, name: string, open_visits: int}>
     */
    public function researcherOptions(): array
    {
        $ids = $this->access->departmentUsersWith('visits');
        $load = DB::table('orphan_social_visits')->whereIn('researcher_id', $ids)->whereIn('status', ['planned', 'returned'])->groupBy('researcher_id')
            ->selectRaw('researcher_id, count(*) as n')->pluck('n', 'researcher_id');

        return DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => $user->name, 'open_visits' => (int) ($load[$user->id] ?? 0)])->all();
    }

    /**
     * Adds families to the plan as visits. A row without a researcher is distributed among the chosen researchers; a
     * row without a date takes the family's follow-up date inside the plan period, else the first day available.
     *
     * @param  array<string, mixed>  $input
     * @return int the number of visits created
     */
    public function assign(User $actor, object $plan, array $input): int
    {
        abort_unless($this->canManage($actor), 403, 'توزيع زيارات الخطة لرئيس القسم.');
        $today = $this->today();
        $starts = substr((string) $plan->starts_on, 0, 10);
        $ends = substr((string) $plan->ends_on, 0, 10);
        if ($ends < $today) {
            throw ValidationException::withMessages(['rows' => 'انتهت فترة الخطة؛ أنشئ خطة جديدة.']);
        }
        $first = max($starts, $today);
        $options = array_column($this->researcherOptions(), 'id');
        $data = Validator::make($input, [
            'researchers' => ['required', 'array', 'min:1', 'max:30'], 'researchers.*' => ['integer', 'distinct', Rule::in($options)],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS], 'rows.*.household' => ['required', 'string', 'distinct'],
            'rows.*.researcher_id' => ['nullable', 'integer', Rule::in($input['researchers'] ?? [])],
            'rows.*.scheduled_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$first, 'before_or_equal:'.$ends],
            'visit_type_value_id' => ['nullable', 'integer', app(OrphanForms::class)->valueOf('social_visit_types')],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['researchers' => 'الباحثون', 'researchers.*' => 'الباحثون', 'rows' => 'الأسر المختارة', 'rows.*.household' => 'ملف الأسرة', 'rows.*.researcher_id' => 'الباحث', 'rows.*.scheduled_on' => 'موعد الزيارة',
            'visit_type_value_id' => 'نوع الزيارة', 'reason' => 'سبب الزيارة'])->validate();
        $researchers = array_map('intval', $data['researchers']);

        return DB::transaction(function () use ($actor, $plan, $data, $researchers, $first, $ends): int {
            DB::table('orphan_visit_plans')->where('id', $plan->id)->lockForUpdate()->first();
            $load = array_fill_keys($researchers, 0);
            foreach (DB::table('orphan_social_visits')->where('plan_id', $plan->id)->whereIn('researcher_id', $researchers)->where('status', '<>', 'cancelled')
                ->groupBy('researcher_id')->selectRaw('researcher_id, count(*) as n')->pluck('n', 'researcher_id') as $id => $n) {
                $load[(int) $id] = (int) $n;
            }
            $cap = (int) ceil((array_sum($load) + count($data['rows'])) / count($researchers));
            $created = [];
            foreach ($data['rows'] as $index => $row) {
                $household = DB::table('orphan_households')->where('public_id', $row['household'])->lockForUpdate()->first(['id', 'status', 'reference_no']);
                if (! $household || ! in_array($household->status, OrphanFollowups::FOLLOWED_STATUSES, true)) {
                    throw ValidationException::withMessages(["rows.{$index}.household" => 'تُضاف للخطة ملفات الأسر قيد الدراسة أو النشطة فقط.']);
                }
                if (DB::table('orphan_social_visits')->where('household_id', $household->id)->whereIn('status', self::OPEN_VISITS)->exists()) {
                    throw ValidationException::withMessages(["rows.{$index}.household" => 'للملف '.$household->reference_no.' زيارة لم تُنجز بعد.']);
                }
                $followup = DB::table('orphan_followups')->where('household_id', $household->id)->where('status', 'open')->first(['due_on', 'reason', 'assigned_to']);
                $researcher = isset($row['researcher_id']) ? (int) $row['researcher_id'] : $this->pick($load, $cap, [
                    $followup ? (int) $followup->assigned_to : null,
                    (int) DB::table('orphan_social_visits')->where('household_id', $household->id)->where('status', 'reviewed')->orderByDesc('visited_on')->value('researcher_id') ?: null,
                ]);
                $load[$researcher]++;
                $due = $followup ? substr((string) $followup->due_on, 0, 10) : $first;
                $reason = $followup ? 'متابعة: '.$followup->reason : (trim((string) ($data['reason'] ?? '')) ?: 'زيارة ضمن خطة '.$plan->reference_no.': '.$plan->title);
                $created[$researcher][] = $this->visits->insertPlanned($actor, (int) $household->id, ['orphan_child_id' => null,
                    'visit_type_value_id' => $data['visit_type_value_id'] ?? null, 'reason' => Str::limit($reason, 1000, ''),
                    'scheduled_on' => $row['scheduled_on'] ?? min(max($due, $first), $ends), 'researcher_id' => $researcher], (int) $plan->id);
            }
            foreach ($created as $researcher => $ids) {
                if ($researcher !== (int) $actor->id) {
                    $this->notifications->notify($researcher, 'orphans_visit_assigned', ['title' => 'أُسندت إليك '.count($ids).' زيارة ضمن خطة '.$plan->reference_no,
                        'body' => $plan->title.' ('.substr((string) $plan->starts_on, 0, 10).' – '.substr((string) $plan->ends_on, 0, 10).')',
                        'url' => '/orphans?tab=mine', 'dedupe' => 'visit_plan:'.$plan->id.':'.$researcher.':'.max($ids)]);
                }
            }
            $this->audit->recordRow('orphans.visit_plan.assigned', 'orphan_visit_plans', (int) $plan->id, null,
                ['visits' => array_sum(array_map('count', $created)), 'by_researcher' => array_map('count', $created)], $actor->id, request()?->ip());

            return array_sum(array_map('count', $created));
        });
    }

    /**
     * Keeps the researcher who already follows the family while their share stays under the fair cap; otherwise
     * the least loaded researcher (ties: the first chosen).
     *
     * @param  array<int, int>  $load
     * @param  list<?int>  $preferred
     */
    private function pick(array $load, int $cap, array $preferred): int
    {
        foreach ($preferred as $id) {
            if ($id !== null && array_key_exists($id, $load) && $load[$id] < $cap) {
                return $id;
            }
        }

        return (int) array_keys($load, min($load), true)[0];
    }

    private function decrypt(?string $cipher): ?string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function today(): string
    {
        return now(config('orphans.timezone'))->toDateString();
    }
}
