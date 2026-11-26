<?php

namespace App\Services\Orphans;

use App\Models\OrphanChild;
use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «قائمة انتظار الكفالة والترشيح» (requirement Q14): active children or families waiting for a sponsor, per
 * sponsorship program (the «نوع الكفالة» constant; none = general). The order is explained, not decided by the
 * system: the current approved social score first, then the longest wait. A sponsorship draft for a waiting
 * beneficiary nominates them; nominating anyone ahead of higher-ranked waiting entries needs a written reason,
 * kept for the fairness review. Approval marks the entry «sponsored»; rejection or cancellation puts it back.
 */
final class SponsorshipWaitlist
{
    public const STATUS_LABELS = ['waiting' => 'بالانتظار', 'nominated' => 'مرشح (كفالة قيد الاعتماد)', 'sponsored' => 'كُفل', 'removed' => 'أُخرج من القائمة'];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('sponsorship_waitlist');
    }

    public function canAdd(?User $user): bool
    {
        return $this->access->allows($user, 'records') || $this->access->allows($user, 'visits') || $this->access->allows($user, 'sponsorships');
    }

    /** @param array<string, mixed> $input */
    public function add(User $actor, OrphanHousehold $household, array $input): int
    {
        abort_unless($this->canAdd($actor), 403, 'الإدراج في قائمة الانتظار لموظفي القسم المكلفين.');
        $data = Validator::make($input, [
            'beneficiary_type' => ['required', 'in:child,household'],
            'child_public_id' => ['nullable', 'required_if:beneficiary_type,child', 'string', Rule::exists('orphan_children', 'public_id')->where('household_id', $household->id)->where('status', 'active')],
            'program_value_id' => ['nullable', 'integer', app(OrphanForms::class)->valueOf('sponsorship_types')],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['child_public_id.exists' => 'يُدرج طفل ملفه نشط من هذه الأسرة.'], ['beneficiary_type' => 'المستفيد', 'child_public_id' => 'الطفل', 'program_value_id' => 'برنامج الكفالة',
            'reason' => 'سبب الإدراج'])->validate();
        if ($household->status !== 'active') {
            throw ValidationException::withMessages(['beneficiary_type' => 'يُدرج في قائمة الانتظار ملف أسرة نشط فقط.']);
        }
        $childId = $data['beneficiary_type'] === 'child' ? (int) DB::table('orphan_children')->where('public_id', $data['child_public_id'])->value('id') : null;
        $program = $data['program_value_id'] ?? null;

        return DB::transaction(function () use ($actor, $household, $data, $childId, $program): int {
            DB::table('orphan_households')->where('id', $household->id)->lockForUpdate()->first();
            $open = DB::table('sponsorship_waitlist')->where('household_id', $household->id)->whereIn('status', ['waiting', 'nominated'])
                ->where(fn ($q) => $childId ? $q->where('orphan_child_id', $childId) : $q->whereNull('orphan_child_id'))
                ->where(fn ($q) => $program ? $q->where('program_value_id', $program) : $q->whereNull('program_value_id'))->exists();
            if ($open) {
                throw ValidationException::withMessages(['beneficiary_type' => 'المستفيد مدرج في قائمة هذا البرنامج.']);
            }
            $sponsored = DB::table('sponsorships')->where('status', 'active')->where('household_id', $household->id)
                ->where(fn ($q) => $childId ? $q->where('orphan_child_id', $childId) : $q->whereNull('orphan_child_id'))
                ->where(fn ($q) => $program ? $q->where('type_value_id', $program) : $q)->exists();
            if ($sponsored) {
                throw ValidationException::withMessages(['beneficiary_type' => 'للمستفيد كفالة نشطة في هذا البرنامج.']);
            }
            $id = DB::table('sponsorship_waitlist')->insertGetId(['household_id' => $household->id, 'orphan_child_id' => $childId, 'beneficiary_type' => $data['beneficiary_type'],
                'program_value_id' => $program, 'reason' => trim($data['reason']), 'status' => 'waiting', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.waitlist.added', 'sponsorship_waitlist', $id, null, ['household' => $household->reference_no, 'child' => $childId, 'program' => $program],
                $actor->id, request()?->ip());

            return $id;
        });
    }

    /** The head removes a waiting entry (moved away, no longer eligible), with a reason that stays on record. */
    public function remove(User $actor, int $entryId, string $reason): void
    {
        abort_unless($this->access->isHead($actor), 403, 'الإخراج من قائمة الانتظار لرئيس القسم.');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب الإخراج'])->validate();
        DB::transaction(function () use ($actor, $entryId, $reason): void {
            $row = DB::table('sponsorship_waitlist')->where('id', $entryId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'waiting') {
                throw ValidationException::withMessages(['reason' => 'يُخرج من القائمة المستفيد المنتظر فقط (لا المرشح أو المكفول).']);
            }
            DB::table('sponsorship_waitlist')->where('id', $entryId)->update(['status' => 'removed', 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.waitlist.removed', 'sponsorship_waitlist', $entryId, ['status' => 'waiting'], ['status' => 'removed', 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * Ranked entries of one program (null = general): waiting and nominated, best current social score first,
     * then the longest wait. Each row says why it stands where it does.
     *
     * @return list<array<string, mixed>>
     */
    public function ranked(?int $programId): array
    {
        if (! $this->available()) {
            return [];
        }
        $rows = DB::table('sponsorship_waitlist as w')->join('orphan_households as h', 'h.id', '=', 'w.household_id')->leftJoin('orphan_children as c', 'c.id', '=', 'w.orphan_child_id')
            ->leftJoin('sponsorships as s', 's.id', '=', 'w.sponsorship_id')
            ->whereIn('w.status', ['waiting', 'nominated'])->where(fn ($q) => $programId ? $q->where('w.program_value_id', $programId) : $q->whereNull('w.program_value_id'))
            ->get(['w.*', 'h.reference_no as household', 'h.public_id as household_public_id', 'c.file_no', 'c.public_id as child_public_id', 's.public_id as sponsorship_public_id', 's.sponsorship_no']);

        $waitingAhead = 0;

        return $this->order($rows)->values()->map(function (array $row, int $index) use (&$waitingAhead): array {
            $row += ['rank' => $index + 1, 'ahead_waiting' => $waitingAhead];
            $waitingAhead += $row['status'] === 'waiting' ? 1 : 0;

            return $row;
        })->all();
    }

    /** @return list<array{id: ?int, name: string, entries: list<array<string, mixed>>}> every program that has entries */
    public function board(): array
    {
        if (! $this->available()) {
            return [];
        }
        $programs = DB::table('sponsorship_waitlist')->whereIn('status', ['waiting', 'nominated'])->distinct()->pluck('program_value_id');
        $names = DB::table('master_data_values')->whereIn('id', $programs->filter())->pluck('name_ar', 'id');

        return $programs->sortBy(fn ($id) => $id === null ? 0 : 1)->values()->map(fn ($id): array => ['id' => $id !== null ? (int) $id : null,
            'name' => $id !== null ? ($names[$id] ?? 'برنامج') : 'عام (دون برنامج محدد)', 'entries' => $this->ranked($id !== null ? (int) $id : null)])->all();
    }

    /** @return list<array<string, mixed>> the household's open and past entries */
    public function forHousehold(int $householdId): array
    {
        if (! $this->available()) {
            return [];
        }
        $names = DB::table('master_data_values')->pluck('name_ar', 'id');

        return DB::table('sponsorship_waitlist as w')->leftJoin('orphan_children as c', 'c.id', '=', 'w.orphan_child_id')->where('w.household_id', $householdId)
            ->orderByRaw("w.status not in ('waiting','nominated')")->orderByDesc('w.id')->limit(30)->get(['w.*', 'c.file_no'])
            ->map(function (object $row) use ($names): array {
                $rank = in_array($row->status, ['waiting', 'nominated'], true)
                    ? collect($this->ranked($row->program_value_id ? (int) $row->program_value_id : null))->firstWhere('id', (int) $row->id)['rank'] ?? null : null;

                return ['id' => (int) $row->id, 'beneficiary' => $row->orphan_child_id ? 'طفل '.$row->file_no : 'الأسرة', 'program' => $row->program_value_id ? ($names[$row->program_value_id] ?? '') : 'عام',
                    'status' => $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'reason' => $row->reason, 'rank' => $rank,
                    'since' => substr((string) $row->created_at, 0, 10), 'close_reason' => $row->close_reason];
            })->all();
    }

    /**
     * Called when a new sponsorship draft is saved: the beneficiary's waiting entry in the same program becomes
     * «nominated». Anyone ranked higher and still waiting must be passed over with a written reason.
     */
    public function linkDraft(User $actor, object $sponsorship, ?string $skipReason): void
    {
        if (! $this->available() || $sponsorship->supersedes_id) {
            return;
        }
        $program = $sponsorship->type_value_id ? (int) $sponsorship->type_value_id : null;
        $entry = DB::table('sponsorship_waitlist')->where('household_id', $sponsorship->household_id)->where('status', 'waiting')
            ->where(fn ($q) => $sponsorship->orphan_child_id ? $q->where('orphan_child_id', $sponsorship->orphan_child_id) : $q->whereNull('orphan_child_id'))
            ->where(fn ($q) => $program ? $q->where('program_value_id', $program) : $q->whereNull('program_value_id'))->lockForUpdate()->first();
        if (! $entry) {
            return;
        }
        $ahead = collect($this->ranked($program))->takeUntil(fn (array $row): bool => $row['id'] === (int) $entry->id)->where('status', 'waiting')->count();
        $skipReason = trim((string) $skipReason);
        if ($ahead > 0 && mb_strlen($skipReason) < 10) {
            throw ValidationException::withMessages(['skip_reason' => 'يسبق هذا المستفيد في قائمة الانتظار '.$ahead.' بالانتظار؛ اذكر سبب تقديمه (10 أحرف على الأقل).']);
        }
        DB::table('sponsorship_waitlist')->where('id', $entry->id)->update(['status' => 'nominated', 'sponsorship_id' => $sponsorship->id, 'nominated_by' => $actor->id,
            'nominated_at' => now(), 'skip_reason' => $ahead > 0 ? $skipReason : null, 'updated_at' => now()]);
        $this->audit->recordRow('sponsorships.waitlist.nominated', 'sponsorship_waitlist', (int) $entry->id, ['status' => 'waiting'],
            ['status' => 'nominated', 'sponsorship' => $sponsorship->sponsorship_no, 'passed_over' => $ahead, 'skip_reason' => $ahead > 0 ? $skipReason : null], $actor->id, request()?->ip());
    }

    /** After the executive decision or a cancelled draft: sponsored, or back to waiting. */
    public function afterDecision(int $sponsorshipId, string $status, int $actorId): void
    {
        if (! $this->available()) {
            return;
        }
        $entry = DB::table('sponsorship_waitlist')->where('sponsorship_id', $sponsorshipId)->where('status', 'nominated')->lockForUpdate()->first();
        if (! $entry) {
            return;
        }
        $update = $status === 'active'
            ? ['status' => 'sponsored', 'closed_by' => $actorId, 'closed_at' => now()]
            : ['status' => 'waiting', 'sponsorship_id' => null, 'nominated_by' => null, 'nominated_at' => null, 'skip_reason' => null];
        DB::table('sponsorship_waitlist')->where('id', $entry->id)->update($update + ['updated_at' => now()]);
        $this->audit->recordRow('sponsorships.waitlist.'.($status === 'active' ? 'sponsored' : 'returned'), 'sponsorship_waitlist', (int) $entry->id, ['status' => 'nominated'],
            ['status' => $update['status'], 'sponsorship_status' => $status], $actorId);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function order(Collection $rows): Collection
    {
        $today = CarbonImmutable::today(config('orphans.timezone'));
        $children = OrphanChild::whereIn('id', $rows->pluck('orphan_child_id')->filter())->get(['id', 'full_name'])->keyBy('id');
        $names = DB::table('master_data_values')->pluck('name_ar', 'id');

        return $rows->map(function (object $row) use ($today, $children, $names): array {
            $score = $this->score($row);
            $since = CarbonImmutable::parse($row->created_at);

            return ['id' => (int) $row->id, 'status' => $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status, 'reason' => $row->reason,
                'beneficiary_type' => $row->beneficiary_type, 'beneficiary' => $row->orphan_child_id ? ($children[$row->orphan_child_id]->full_name ?? '').' ('.$row->file_no.')' : 'أسرة '.$row->household,
                'beneficiary_reference' => $row->orphan_child_id ? $row->file_no : $row->household, 'household' => $row->household, 'household_public_id' => $row->household_public_id,
                'child_public_id' => $row->child_public_id, 'program_value_id' => $row->program_value_id ? (int) $row->program_value_id : null,
                'program' => $row->program_value_id ? ($names[$row->program_value_id] ?? '') : 'عام', 'score' => $score['value'], 'score_label' => $score['label'],
                'waiting_days' => (int) $since->diffInDays($today), 'since' => $since->toDateString(), 'skip_reason' => $row->skip_reason,
                'sponsorship_public_id' => $row->sponsorship_public_id, 'sponsorship_no' => $row->sponsorship_no];
        })->sort(function (array $a, array $b): int {
            if (($a['score'] === null) !== ($b['score'] === null)) {
                return $a['score'] === null ? 1 : -1;
            }

            return [$b['score'] ?? 0, $a['since'], $a['id']] <=> [$a['score'] ?? 0, $b['since'], $b['id']];
        });
    }

    /**
     * The current approved social score behind the order: the child's own, or the highest among a family's
     * active children.
     *
     * @return array{value: ?float, label: string}
     */
    private function score(object $row): array
    {
        $query = DB::table('orphan_social_scores as s')->join('scoring_rulesets as r', 'r.id', '=', 's.ruleset_id')->where('s.is_current', true)
            ->when($row->orphan_child_id, fn ($q) => $q->where('s.orphan_child_id', $row->orphan_child_id),
                fn ($q) => $q->whereIn('s.orphan_child_id', DB::table('orphan_children')->where('household_id', $row->household_id)->where('status', 'active')->select('id')))
            ->selectRaw('coalesce(s.override_total, s.total) as value, r.version')->orderByDesc('value')->first();
        if (! $query) {
            return ['value' => null, 'label' => 'لا يوجد تقييم اجتماعي معتمد (يأتي بعد المقيّمين حسب الأقدمية)'];
        }

        return ['value' => (float) $query->value, 'label' => 'التقييم الاجتماعي '.(float) $query->value.' (قواعد إصدار '.$query->version.')'.($row->orphan_child_id ? '' : ' — أعلى أطفال الأسرة')];
    }
}
