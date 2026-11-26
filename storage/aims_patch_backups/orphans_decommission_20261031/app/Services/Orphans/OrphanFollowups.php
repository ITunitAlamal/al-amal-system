<?php

namespace App\Services\Orphans;

use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «المتابعة الدورية للحالة» (requirements Q10 and Q27): every family file has at most one open follow-up with a due
 * date and a responsible staff member. The responsible member is reminded before the due date and when it is late;
 * the department head is alerted once it is overdue by the escalation period. An approved visit report completes the
 * open follow-up and schedules the next one from the date the researcher recommended, or from the case's review
 * interval. Alerts and bookkeeping only: no workflow, no payment, no change to the file itself.
 */
final class OrphanFollowups
{
    public const SOURCE_LABELS = ['manual' => 'حددها الموظف', 'visit' => 'أوصى بها تقرير زيارة', 'periodic' => 'مراجعة دورية'];

    public const STATUS_LABELS = ['open' => 'مفتوحة', 'completed' => 'منجزة', 'cancelled' => 'ملغاة'];

    /** Families whose case is followed: under study or active. */
    public const FOLLOWED_STATUSES = ['registered', 'active'];

    private const COLUMNS = ['f.id', 'f.household_id', 'f.due_on', 'f.reason', 'f.source', 'f.status', 'f.outcome', 'f.closed_at', 'f.escalated_at', 'f.assigned_to',
        'f.completed_visit_id', 'a.name as assignee', 'c.name as closed_by', 'h.reference_no as household', 'h.public_id as household_public_id', 'k.full_name as child',
        'v.reference_no as completed_visit'];

    /** @var array{interval: ?int, reminder: int, escalation: int}|null */
    private ?array $settings = null;

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('orphan_followups');
    }

    /** @return array{interval: ?int, reminder: int, escalation: int} */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $row = $this->available() ? DB::table('orphans_module_settings')->where('id', 1)->first(['followup_interval_months', 'followup_reminder_days', 'followup_escalation_days']) : null;

        return $this->settings = ['interval' => $row?->followup_interval_months !== null ? (int) $row->followup_interval_months : null,
            'reminder' => (int) ($row->followup_reminder_days ?? 7), 'escalation' => (int) ($row->followup_escalation_days ?? 7)];
    }

    /** The case's own review interval set by the head, else the department default; null when neither is decided. */
    public function intervalFor(int $householdId): ?int
    {
        $own = DB::table('orphan_households')->where('id', $householdId)->value('followup_interval_months');

        return $own !== null ? (int) $own : $this->settings()['interval'];
    }

    public function canSchedule(?User $user): bool
    {
        return $this->access->allows($user, 'visits') || $this->access->allows($user, 'records');
    }

    /**
     * Who may be responsible for a follow-up: today's heads and department staff holding the visits capability.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assigneeOptions(): array
    {
        return DB::table('users')->whereIn('id', $this->access->departmentUsersWith('visits'))->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name])->all();
    }

    /**
     * Schedules the follow-up of a family file. Staff schedule their own; the head may assign any researcher.
     *
     * @param  array<string, mixed>  $input
     */
    public function schedule(User $actor, OrphanHousehold $household, array $input): int
    {
        abort_unless($this->canSchedule($actor), 403, 'جدولة المتابعة لموظفي القسم المكلفين بالملفات أو الزيارات.');
        $head = $this->access->isHead($actor);
        $data = Validator::make($input, [
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today(), 'before_or_equal:'.$this->now()->addYears(2)->toDateString()],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'assigned_to' => ['nullable', 'integer', Rule::in(array_column($this->assigneeOptions(), 'id'))],
            'child_public_id' => ['nullable', 'string', Rule::exists('orphan_children', 'public_id')->where('household_id', $household->id)],
        ], [], ['due_on' => 'موعد المتابعة', 'reason' => 'سبب المتابعة', 'assigned_to' => 'المسؤول عن المتابعة', 'child_public_id' => 'الطفل'])->validate();
        $assignee = (int) ($data['assigned_to'] ?? $actor->id);
        abort_if($assignee !== (int) $actor->id && ! $head, 403, 'إسناد المتابعة لموظف آخر لرئيس القسم.');
        abort_unless($this->access->allows(User::find($assignee), 'visits') || $this->access->allows(User::find($assignee), 'records'), 422, 'المسؤول المختار لا يملك تفويضًا في القسم.');
        $childId = ! empty($data['child_public_id']) ? (int) DB::table('orphan_children')->where('public_id', $data['child_public_id'])->value('id') : null;

        return DB::transaction(function () use ($actor, $household, $data, $assignee, $childId): int {
            $locked = DB::table('orphan_households')->where('id', $household->id)->lockForUpdate()->first(['status']);
            if (! in_array($locked->status, self::FOLLOWED_STATUSES, true)) {
                throw ValidationException::withMessages(['due_on' => 'تُتابع ملفات الأسر قيد الدراسة أو النشطة فقط.']);
            }
            if (DB::table('orphan_followups')->where('household_id', $household->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['due_on' => 'للملف متابعة مفتوحة؛ أنجزها أو ألغها أولًا.']);
            }
            $id = $this->insert($household->id, $childId, $data['due_on'], trim($data['reason']), 'manual', null, $assignee, (int) $actor->id);
            $this->audit->recordRow('orphans.followup.scheduled', 'orphan_followups', $id, null, ['household' => $household->reference_no, 'due_on' => $data['due_on'],
                'assigned_to' => $assignee], $actor->id, request()?->ip());
            $this->notifyAssigned($id, $assignee, (int) $actor->id, $household->reference_no, $household->public_id, $data['due_on']);

            return $id;
        });
    }

    /**
     * Records the result of a follow-up done without a visit report (a call, an office review). The next follow-up is
     * scheduled on the date given, else from the review interval; none when neither is known.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(User $actor, int $followupId, array $input): void
    {
        $data = Validator::make($input, [
            'outcome' => ['required', 'string', 'min:10', 'max:2000'],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after:'.$this->today(), 'before_or_equal:'.$this->now()->addYears(2)->toDateString()],
        ], [], ['outcome' => 'نتيجة المتابعة', 'next_due_on' => 'موعد المتابعة التالية'])->validate();
        DB::transaction(function () use ($actor, $followupId, $data): void {
            $row = $this->lockOpen($followupId);
            abort_unless((int) $row->assigned_to === (int) $actor->id || $this->access->isHead($actor), 403, 'يسجل النتيجة المسؤول عن المتابعة أو رئيس القسم.');
            $this->close($row, 'completed', trim($data['outcome']), (int) $actor->id, null);
            $this->scheduleNext((int) $row->household_id, $data['next_due_on'] ?? null, null, (int) $row->assigned_to, (int) $actor->id);
        });
    }

    /** The head cancels a follow-up that is no longer needed (the file was closed, a duplicate), with a reason. */
    public function cancel(User $actor, int $followupId, string $reason): void
    {
        abort_unless($this->access->isHead($actor), 403, 'إلغاء المتابعة لرئيس القسم.');
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:5', 'max:2000']], [], ['reason' => 'سبب الإلغاء'])->validate();
        DB::transaction(function () use ($actor, $followupId, $reason): void {
            $this->close($this->lockOpen($followupId), 'cancelled', trim($reason), (int) $actor->id, null);
        });
    }

    /** The head moves an open follow-up to another staff member (absence, workload). */
    public function reassign(User $actor, int $followupId, int $assignee): void
    {
        abort_unless($this->access->isHead($actor), 403, 'إعادة إسناد المتابعة لرئيس القسم.');
        Validator::make(['assigned_to' => $assignee], ['assigned_to' => ['required', 'integer', Rule::in(array_column($this->assigneeOptions(), 'id'))]], [],
            ['assigned_to' => 'المسؤول عن المتابعة'])->validate();
        DB::transaction(function () use ($actor, $followupId, $assignee): void {
            $row = $this->lockOpen($followupId);
            if ((int) $row->assigned_to === $assignee) {
                return;
            }
            DB::table('orphan_followups')->where('id', $row->id)->update(['assigned_to' => $assignee, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.followup.reassigned', 'orphan_followups', (int) $row->id, ['assigned_to' => (int) $row->assigned_to], ['assigned_to' => $assignee],
                $actor->id, request()?->ip());
            $household = DB::table('orphan_households')->where('id', $row->household_id)->first(['reference_no', 'public_id']);
            $this->notifyAssigned((int) $row->id, $assignee, (int) $actor->id, $household->reference_no, $household->public_id, (string) $row->due_on);
        });
    }

    /** The head sets how often this case is reviewed (months); null returns it to the department default. */
    public function setInterval(User $actor, OrphanHousehold $household, ?int $months): void
    {
        abort_unless($this->access->isHead($actor), 403, 'تحديد دورية متابعة الحالة لرئيس القسم.');
        Validator::make(['months' => $months], ['months' => ['nullable', 'integer', 'between:1,24']], [], ['months' => 'دورية المتابعة'])->validate();
        DB::transaction(function () use ($actor, $household, $months): void {
            $before = DB::table('orphan_households')->where('id', $household->id)->lockForUpdate()->value('followup_interval_months');
            DB::table('orphan_households')->where('id', $household->id)->update(['followup_interval_months' => $months, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.followup.interval_set', 'orphan_households', (int) $household->id, ['followup_interval_months' => $before],
                ['followup_interval_months' => $months], $actor->id, request()?->ip());
        });
    }

    /**
     * Called inside the visit workflow when the head approves a report: completes the family's open follow-up with
     * the visit and schedules the next one (the date the researcher recommended, else the review interval).
     */
    public function afterVisitReviewed(object $visit, int $actorId): void
    {
        if (! $this->available()) {
            return;
        }
        $open = DB::table('orphan_followups')->where('household_id', $visit->household_id)->where('status', 'open')->lockForUpdate()->first();
        if ($open) {
            $this->close($open, 'completed', 'أُنجزت بتقرير الزيارة '.$visit->reference_no.' المعتمد.', $actorId, (int) $visit->id);
        }
        $this->scheduleNext((int) $visit->household_id, $visit->next_visit_on ? substr((string) $visit->next_visit_on, 0, 10) : null, $visit, (int) $visit->researcher_id, $actorId);
    }

    /** The open follow-up of a family (for linking a newly planned visit), or null. */
    public function openFor(int $householdId): ?int
    {
        if (! $this->available()) {
            return null;
        }
        $id = DB::table('orphan_followups')->where('household_id', $householdId)->where('status', 'open')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Daily alerts: the responsible member once when the due date enters the reminder window and once when it is
     * late; the department head once when it is overdue by the escalation period.
     *
     * @return array{soon: int, overdue: int, escalated: int, notified: int, heads: int}
     */
    public function sendAlerts(bool $dryRun = false): array
    {
        $settings = $this->settings();
        $today = $this->today();
        $heads = $this->access->headUserIds();
        $counts = ['soon' => 0, 'overdue' => 0, 'escalated' => 0, 'notified' => 0, 'heads' => count($heads)];
        if (! $this->available()) {
            return $counts;
        }
        $until = $this->now()->addDays($settings['reminder'])->toDateString();
        $escalateBefore = $this->now()->subDays($settings['escalation'])->toDateString();
        $rows = $this->query()->where('f.status', 'open')->where('f.due_on', '<=', $until)->orderBy('f.due_on')->limit(1000)->get(self::COLUMNS);
        foreach ($rows as $row) {
            $due = substr((string) $row->due_on, 0, 10);
            $stage = $due < $today ? 'overdue' : 'soon';
            $counts[$stage]++;
            $url = '/orphans/households/'.$row->household_public_id;
            if (! $dryRun) {
                $counts['notified'] += $this->notifications->notifyMany([(int) $row->assigned_to], 'orphans_followup_due', [
                    'title' => ($stage === 'overdue' ? 'متابعة متأخرة منذ ' : 'متابعة مستحقة في ').$due.' — ملف '.$row->household,
                    'body' => $row->reason, 'url' => $url, 'dedupe' => 'orphan_followup:'.$row->id.':'.$stage.':'.$due]);
            }
            if ($due <= $escalateBefore && $row->escalated_at === null) {
                $counts['escalated']++;
                if ($dryRun || $heads === []) {
                    continue;
                }
                $counts['notified'] += $this->notifications->notifyMany($heads, 'orphans_followup_escalated', [
                    'title' => 'متابعة متأخرة عن موعدها ('.$due.') — ملف '.$row->household, 'body' => 'المسؤول: '.$row->assignee.'. '.$row->reason,
                    'url' => $url, 'dedupe' => 'orphan_followup:'.$row->id.':escalated:'.$due]);
                DB::table('orphan_followups')->where('id', $row->id)->whereNull('escalated_at')->update(['escalated_at' => now(), 'escalated_to' => json_encode($heads), 'updated_at' => now()]);
            }
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> the family's follow-ups, the open one first */
    public function forHousehold(int $householdId, User $viewer): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->query()->where('f.household_id', $householdId)->orderByRaw("f.status <> 'open'")->orderByDesc('f.due_on')->limit(20)->get(self::COLUMNS)
            ->map(fn (object $row): array => $this->present($row, $viewer))->all();
    }

    /** @return list<array<string, mixed>> open follow-ups of the department (or of one member), earliest first */
    public function open(User $viewer, ?int $assignee = null, int $limit = 200): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->query()->where('f.status', 'open')->when($assignee, fn (Builder $q, int $id) => $q->where('f.assigned_to', $id))
            ->orderBy('f.due_on')->limit($limit)->get(self::COLUMNS)->map(fn (object $row): array => $this->present($row, $viewer))->all();
    }

    /**
     * Families under study or active with no open follow-up, with the date of their last approved visit.
     *
     * @return list<array{household: string, household_public_id: string, family_name: ?string, status_label: string, last_visit: ?string}>
     */
    public function unscheduled(int $limit = 100): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('orphan_households as h')->whereIn('h.status', self::FOLLOWED_STATUSES)
            ->whereNotExists(fn (Builder $q) => $q->from('orphan_followups as f')->whereColumn('f.household_id', 'h.id')->where('f.status', 'open'))
            ->orderBy('h.id')->limit($limit)
            ->get(['h.reference_no', 'h.public_id', 'h.family_name', 'h.status',
                DB::raw("(select max(v.visited_on) from orphan_social_visits v where v.household_id = h.id and v.status = 'reviewed') as last_visit")])
            ->map(fn (object $row): array => ['household' => $row->reference_no, 'household_public_id' => $row->public_id, 'family_name' => $this->decrypt($row->family_name),
                'status_label' => OrphanHousehold::STATUS_LABELS[$row->status] ?? $row->status, 'last_visit' => $row->last_visit ? substr((string) $row->last_visit, 0, 10) : null])->all();
    }

    /**
     * What waits for this user in the field: visits to report or complete, and follow-ups due within the reminder
     * window or late. Zero for anyone outside the department.
     *
     * @return array{visits: int, followups: int, overdue: int}
     */
    public function workload(User $user): array
    {
        if (! $this->available()) {
            return ['visits' => 0, 'followups' => 0, 'overdue' => 0];
        }
        $today = $this->today();
        $visits = DB::table('orphan_social_visits')->where('researcher_id', $user->id)->whereIn('status', ['planned', 'returned']);
        $lateVisits = (clone $visits)->where('status', 'planned')->where('scheduled_on', '<', $today)->count();
        $followups = DB::table('orphan_followups')->where('assigned_to', $user->id)->where('status', 'open')
            ->where('due_on', '<=', $this->now()->addDays($this->settings()['reminder'])->toDateString());
        $lateFollowups = (clone $followups)->where('due_on', '<', $today)->count();

        return ['visits' => $visits->count(), 'followups' => $followups->count(), 'overdue' => $lateVisits + $lateFollowups];
    }

    /**
     * This user's planned visits and follow-ups due between two dates, for the personal calendar (references only).
     *
     * @return list<array{date: string, end: null, title: string, source: string, url: string, tone: string}>
     */
    public function calendar(User $user, string $from, string $to): array
    {
        if (! $this->available()) {
            return [];
        }
        $events = [];
        foreach (DB::table('orphan_social_visits')->where('researcher_id', $user->id)->where('status', 'planned')->whereBetween('scheduled_on', [$from, $to])
            ->get(['id', 'reference_no', 'scheduled_on']) as $visit) {
            $events[] = ['date' => substr((string) $visit->scheduled_on, 0, 10), 'end' => null, 'title' => 'زيارة اجتماعية '.$visit->reference_no, 'source' => 'زيارة ميدانية مسندة إليّ',
                'url' => '/orphans/visits/'.$visit->id, 'tone' => 'warning'];
        }
        foreach ($this->query()->where('f.assigned_to', $user->id)->where('f.status', 'open')->whereBetween('f.due_on', [$from, $to])->get(['f.due_on', 'h.reference_no', 'h.public_id']) as $row) {
            $events[] = ['date' => substr((string) $row->due_on, 0, 10), 'end' => null, 'title' => 'متابعة ملف '.$row->reference_no, 'source' => 'متابعة حالة مسندة إليّ',
                'url' => '/orphans/households/'.$row->public_id, 'tone' => 'warning'];
        }

        return $events;
    }

    /** Creates the next follow-up after one is done, unless the file is no longer followed or no date is known. */
    private function scheduleNext(int $householdId, ?string $nextDue, ?object $visit, int $preferredAssignee, int $actorId): void
    {
        $household = DB::table('orphan_households')->where('id', $householdId)->first(['status', 'reference_no', 'public_id']);
        if (! $household || ! in_array($household->status, self::FOLLOWED_STATUSES, true)
            || DB::table('orphan_followups')->where('household_id', $householdId)->where('status', 'open')->exists()) {
            return;
        }
        $interval = $this->intervalFor($householdId);
        if ($nextDue !== null) {
            [$source, $reason] = $visit ? ['visit', 'متابعة أوصى بها تقرير الزيارة '.$visit->reference_no.'.'] : ['manual', 'متابعة تالية حُددت عند إنجاز المتابعة السابقة.'];
        } elseif ($interval !== null) {
            [$source, $reason, $nextDue] = ['periodic', 'مراجعة دورية للحالة '.self::intervalLabel($interval).'.', $this->now()->addMonthsNoOverflow($interval)->toDateString()];
        } else {
            return;
        }
        $assignee = $this->eligibleAssignee($preferredAssignee) ?? $this->eligibleAssignee($actorId);
        if ($assignee === null) {
            return;
        }
        $id = $this->insert($householdId, null, $nextDue, $reason, $source, $visit ? (int) $visit->id : null, $assignee, $actorId);
        $this->audit->recordRow('orphans.followup.scheduled', 'orphan_followups', $id, null, ['household' => $household->reference_no, 'due_on' => $nextDue, 'source' => $source,
            'assigned_to' => $assignee], $actorId, request()?->ip());
        $this->notifyAssigned($id, $assignee, $actorId, $household->reference_no, $household->public_id, $nextDue);
    }

    /** «كل شهر»، «كل شهرين»، «كل 3 أشهر»، «كل 12 شهرًا». */
    public static function intervalLabel(int $months): string
    {
        return match (true) {
            $months === 1 => 'كل شهر',
            $months === 2 => 'كل شهرين',
            $months <= 10 => 'كل '.$months.' أشهر',
            default => 'كل '.$months.' شهرًا',
        };
    }

    private function eligibleAssignee(int $userId): ?int
    {
        return in_array($userId, array_column($this->assigneeOptions(), 'id'), true) ? $userId : null;
    }

    private function insert(int $householdId, ?int $childId, string $dueOn, string $reason, string $source, ?int $sourceVisitId, int $assignee, int $actorId): int
    {
        return (int) DB::table('orphan_followups')->insertGetId(['household_id' => $householdId, 'orphan_child_id' => $childId, 'due_on' => $dueOn, 'reason' => $reason,
            'source' => $source, 'source_visit_id' => $sourceVisitId, 'assigned_to' => $assignee, 'status' => 'open', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function close(object $row, string $status, string $outcome, int $actorId, ?int $visitId): void
    {
        DB::table('orphan_followups')->where('id', $row->id)->update(['status' => $status, 'outcome' => $outcome, 'completed_visit_id' => $visitId, 'closed_by' => $actorId,
            'closed_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.followup.'.$status, 'orphan_followups', (int) $row->id, ['status' => 'open'], ['status' => $status, 'outcome' => $outcome, 'visit' => $visitId],
            $actorId, request()?->ip());
    }

    private function lockOpen(int $followupId): object
    {
        $row = DB::table('orphan_followups')->where('id', $followupId)->lockForUpdate()->first();
        abort_unless($row, 404);
        if ($row->status !== 'open') {
            throw ValidationException::withMessages(['followup' => 'أُغلقت هذه المتابعة مسبقًا.']);
        }

        return $row;
    }

    private function notifyAssigned(int $id, int $assignee, int $actorId, string $household, string $publicId, string $dueOn): void
    {
        if ($assignee === $actorId) {
            return;
        }
        $this->notifications->notify($assignee, 'orphans_followup_due', ['title' => 'أُسندت إليك متابعة ملف '.$household.' في '.$dueOn,
            'url' => '/orphans/households/'.$publicId, 'dedupe' => 'orphan_followup:'.$id.':assigned:'.$assignee]);
    }

    private function query(): Builder
    {
        return DB::table('orphan_followups as f')->join('orphan_households as h', 'h.id', '=', 'f.household_id')->leftJoin('users as a', 'a.id', '=', 'f.assigned_to')
            ->leftJoin('users as c', 'c.id', '=', 'f.closed_by')->leftJoin('orphan_children as k', 'k.id', '=', 'f.orphan_child_id')
            ->leftJoin('orphan_social_visits as v', 'v.id', '=', 'f.completed_visit_id');
    }

    /** @return array<string, mixed> */
    private function present(object $row, User $viewer): array
    {
        $due = substr((string) $row->due_on, 0, 10);
        $open = $row->status === 'open';
        $head = $this->access->isHead($viewer);

        return ['id' => (int) $row->id, 'due_on' => $due, 'reason' => $row->reason, 'source' => $row->source, 'source_label' => self::SOURCE_LABELS[$row->source] ?? $row->source,
            'status' => $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'outcome' => $row->outcome,
            'closed_at' => $row->closed_at ? substr((string) $row->closed_at, 0, 10) : null, 'closed_by' => $row->closed_by, 'assignee' => $row->assignee,
            'assigned_to' => (int) $row->assigned_to, 'household' => $row->household, 'household_public_id' => $row->household_public_id, 'completed_visit' => $row->completed_visit,
            'completed_visit_id' => $row->completed_visit_id ? (int) $row->completed_visit_id : null, 'child' => $this->decrypt($row->child),
            'overdue' => $open && $due < $this->today(), 'escalated' => $open && $row->escalated_at !== null,
            'can_complete' => $open && ((int) $row->assigned_to === (int) $viewer->id || $head), 'can_manage' => $open && $head];
    }

    private function decrypt(?string $cipher): ?string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function now(): Carbon
    {
        return now(config('orphans.timezone'));
    }

    private function today(): string
    {
        return $this->now()->toDateString();
    }
}
