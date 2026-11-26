<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceMoney;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sponsorships: drafted in the department, recommended by the head and approved by the executive director
 * (SPONSORSHIP workflow). An amendment is a new version approved the same way; the earlier one is kept as
 * «superseded». Approval creates the schedule of dues; collections, payouts and the child's savings are recorded
 * by Accounts only (SponsorshipAccounts), so the department never claims a payment that was not posted.
 */
final class Sponsorships
{
    public const STATUS_LABELS = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار التوصية والاعتماد', 'active' => 'نشطة', 'paused' => 'موقوفة مؤقتًا',
        'ended' => 'منتهية', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة', 'superseded' => 'مستبدلة بإصدار أحدث'];

    public const SCHEDULE_LABELS = ['expected' => 'مستحقة', 'partial' => 'محصلة جزئيًا', 'collected' => 'محصلة', 'waived' => 'معفاة', 'cancelled' => 'ملغاة'];

    public const REGULARITY_LABELS = ['ongoing' => 'منتظمة', 'paused' => 'متوقفة', 'irregular' => 'غير منتظمة (متأخرة)'];

    /** Dues are generated this far ahead and extended daily (orphans:sponsorship-schedules). */
    private const HORIZON_MONTHS = 12;

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanForms $forms,
        private readonly OrphanRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    /**
     * Creates or edits a draft (a new sponsorship or an amendment version).
     *
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input, ?int $id = null): int
    {
        $this->access->assert($actor, 'sponsorships');
        $existing = $id ? DB::table('sponsorships')->where('id', $id)->first() : null;
        abort_if($id && ! $existing, 404);
        if ($existing && ($existing->status !== 'draft' || ! $this->mayEditDraft($actor, $existing))) {
            throw ValidationException::withMessages(['sponsorship' => 'تُعدّل المسودة قبل رفعها أو بعد إعادتها فقط، من معدّها أو رئيس القسم.']);
        }
        $amendment = $existing && $existing->supersedes_id;
        $money = ['regex:/^\d{1,10}(\.\d{1,2})?$/'];
        $rules = [
            'type_value_id' => ['nullable', 'integer', $this->forms->valueOf('sponsorship_types')],
            'amount' => ['required', ...$money], 'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'frequency_value_id' => ['required', 'integer', $this->forms->valueOf('sponsorship_frequencies')],
            'purpose_value_id' => ['required', 'integer', $this->forms->valueOf('sponsorship_purposes')],
            'savings_amount' => ['nullable', ...$money], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'conditions' => ['nullable', 'string', 'max:2000'], 'source' => ['nullable', 'string', 'max:200'],
        ];
        if (! $amendment) {
            $rules += ['sponsor_id' => ['required', 'integer', Rule::exists('sponsors', 'id')->where('status', 'active')],
                'beneficiary_type' => ['required', 'in:child,household'], 'beneficiary_reference' => ['required', 'string', 'max:30']];
        }
        $data = validator($input, $rules, ['sponsor_id.exists' => 'اختر كافلًا نشطًا.'], ['amount' => 'المبلغ', 'currency' => 'العملة', 'frequency_value_id' => 'الدورية',
            'purpose_value_id' => 'الغرض', 'savings_amount' => 'جزء الادخار', 'starts_on' => 'تاريخ البداية', 'ends_on' => 'تاريخ النهاية',
            'beneficiary_reference' => 'رقم ملف المستفيد', 'sponsor_id' => 'الكافل'])->validate();

        if ($amendment) {
            [$type, $childId, $householdId, $sponsorId] = [$existing->beneficiary_type, $existing->orphan_child_id ? (int) $existing->orphan_child_id : null, (int) $existing->household_id, (int) $existing->sponsor_id];
            $this->assertAmendmentDate(DB::table('sponsorships')->where('id', $existing->supersedes_id)->first(), $data['starts_on']);
        } else {
            [$type, $sponsorId] = [$data['beneficiary_type'], (int) $data['sponsor_id']];
            [$childId, $householdId] = $this->beneficiary($type, $data['beneficiary_reference']);
            $this->assertNoDuplicate($sponsorId, $householdId, $childId, $id);
        }
        [$amount, $expense, $savings] = $this->split((int) $data['purpose_value_id'], (string) $data['amount'], $data['savings_amount'] ?? null, $type);
        $values = ['type_value_id' => $data['type_value_id'] ?? null, 'amount' => $amount, 'currency' => strtoupper($data['currency']),
            'frequency_value_id' => (int) $data['frequency_value_id'], 'purpose_value_id' => (int) $data['purpose_value_id'], 'expense_amount' => $expense,
            'savings_amount' => $savings, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'conditions' => $data['conditions'] ?? null,
            'source' => $data['source'] ?? null, 'updated_at' => now()];
        if (! $amendment) {
            $values += ['sponsor_id' => $sponsorId, 'beneficiary_type' => $type, 'orphan_child_id' => $childId, 'household_id' => $householdId];
        }

        return DB::transaction(function () use ($actor, $values, $existing): int {
            if ($existing) {
                DB::table('sponsorships')->where('id', $existing->id)->lockForUpdate()->first();
                DB::table('sponsorships')->where('id', $existing->id)->update($values);
                $this->audit->recordRow('sponsorships.draft_updated', 'sponsorships', (int) $existing->id, null, ['amount' => $values['amount'], 'currency' => $values['currency']], $actor->id, request()?->ip());

                return (int) $existing->id;
            }
            $id = DB::table('sponsorships')->insertGetId($values + ['public_id' => (string) Str::ulid(), 'sponsorship_no' => $this->registry->number('sponsorship_ref_seq', 'SPN-'),
                'status' => 'draft', 'version' => 1, 'created_by' => $actor->id, 'created_at' => now()]);
            $this->audit->recordRow('sponsorships.created', 'sponsorships', $id, null, ['beneficiary' => $values['beneficiary_type'], 'amount' => $values['amount'], 'currency' => $values['currency']],
                $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Opens an amendment: a new draft version of an active or paused sponsorship, approved like a new one. */
    public function amend(User $actor, int $id): int
    {
        $this->access->assert($actor, 'sponsorships');

        return DB::transaction(function () use ($actor, $id): int {
            $original = DB::table('sponsorships')->where('id', $id)->lockForUpdate()->first();
            abort_unless($original, 404);
            if (! in_array($original->status, ['active', 'paused'], true)) {
                throw ValidationException::withMessages(['sponsorship' => 'يُعدّل بإصدار جديد ما كان نشطًا أو موقوفًا.']);
            }
            if (DB::table('sponsorships')->where('supersedes_id', $original->id)->whereIn('status', ['draft', 'pending_approval'])->exists()) {
                throw ValidationException::withMessages(['sponsorship' => 'يوجد تعديل مفتوح على هذه الكفالة؛ أكمله أو ألغه أولًا.']);
            }
            $today = $this->today()->toDateString();
            $next = DB::table('sponsorship_schedules')->where('sponsorship_id', $original->id)->where('status', 'expected')->where('period_start', '>=', $today)->min('period_start');
            $version = (int) $original->version + 1;
            $copy = collect((array) $original)->only(['sponsor_id', 'beneficiary_type', 'orphan_child_id', 'household_id', 'type_value_id', 'amount', 'currency', 'frequency_value_id',
                'purpose_value_id', 'expense_amount', 'savings_amount', 'ends_on', 'conditions', 'source'])->all();
            $newId = DB::table('sponsorships')->insertGetId($copy + ['public_id' => (string) Str::ulid(), 'sponsorship_no' => explode('/', $original->sponsorship_no)[0].'/'.$version,
                'version' => $version, 'supersedes_id' => $original->id, 'status' => 'draft', 'starts_on' => max((string) $next ?: $today, (string) $original->starts_on),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.amendment_opened', 'sponsorships', $newId, null, ['supersedes' => $original->sponsorship_no, 'version' => $version], $actor->id, request()?->ip());

            return $newId;
        });
    }

    /** First submission: the head recommends, then the executive director decides. */
    public function submit(User $actor, int $id, WorkflowEngine $engine): void
    {
        $this->access->assert($actor, 'sponsorships');
        DB::transaction(function () use ($actor, $id, $engine): void {
            $row = DB::table('sponsorships')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'draft' || $row->workflow_instance_id || ! $this->mayEditDraft($actor, $row)) {
                throw ValidationException::withMessages(['sponsorship' => 'المسودة مرفوعة مسبقًا أو ليست لك.']);
            }
            $this->assertSubmittable($row);
            $instance = $engine->startForSubject($actor, 'SPONSORSHIP', SponsorshipWorkflow::SUBJECT_TYPE, (int) $row->id, $this->access->departmentId());
            DB::table('sponsorships')->where('id', $row->id)->update(['workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /** A draft never submitted is cancelled (kept for the record). */
    public function cancelDraft(User $actor, int $id, string $reason): void
    {
        $this->access->assert($actor, 'sponsorships');
        DB::transaction(function () use ($actor, $id, $reason): void {
            $row = DB::table('sponsorships')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'draft' || $row->workflow_instance_id || ! $this->mayEditDraft($actor, $row)) {
                throw ValidationException::withMessages(['sponsorship' => 'تُلغى المسودة التي لم تُرفع فقط.']);
            }
            DB::table('sponsorships')->where('id', $id)->update(['status' => 'cancelled', 'status_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.draft_cancelled', 'sponsorships', $id, ['status' => 'draft'], ['status' => 'cancelled'], $actor->id, request()?->ip());
        });
    }

    /**
     * Pause, resume or end an approved sponsorship (the head of the department, with a reason). Pausing cancels the
     * dues not yet collected from today; resuming reopens them from today; ending cancels the dues after the end date.
     *
     * @param  array<string, mixed>  $input
     */
    public function changeStatus(User $actor, int $id, string $action, array $input): void
    {
        abort_unless($this->access->isHead($actor), 403, 'إيقاف الكفالة واستئنافها وإنهاؤها لرئيس قسم الكفالات.');
        $data = validator($input, ['reason' => ['required', 'string', 'min:5', 'max:1000'], 'ends_on' => ['nullable', 'date']], [], ['reason' => 'السبب', 'ends_on' => 'تاريخ الانتهاء'])->validate();
        DB::transaction(function () use ($actor, $id, $action, $data): void {
            $row = DB::table('sponsorships')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $today = $this->today()->toDateString();
            $allowed = ['pause' => ['active'], 'resume' => ['paused'], 'end' => ['active', 'paused']][$action] ?? abort(404);
            if (! in_array($row->status, $allowed, true)) {
                throw ValidationException::withMessages(['sponsorship' => 'لا يصح هذا الإجراء على حالة الكفالة الحالية.']);
            }
            $schedules = DB::table('sponsorship_schedules')->where('sponsorship_id', $id);
            $update = ['status_reason' => $data['reason'], 'updated_at' => now()];
            if ($action === 'pause') {
                (clone $schedules)->where('status', 'expected')->where('due_on', '>=', $today)->update(['status' => 'cancelled', 'updated_at' => now()]);
                $update['status'] = 'paused';
            } elseif ($action === 'resume') {
                (clone $schedules)->where('status', 'cancelled')->where('period_start', '>=', $today)->update(['status' => 'expected', 'updated_at' => now()]);
                $update['status'] = 'active';
            } else {
                $endsOn = $data['ends_on'] ?? $today;
                if ($endsOn < $row->starts_on) {
                    throw ValidationException::withMessages(['ends_on' => 'تاريخ الانتهاء قبل بداية الكفالة.']);
                }
                (clone $schedules)->where('status', 'expected')->where('period_start', '>', $endsOn)->update(['status' => 'cancelled', 'updated_at' => now()]);
                $update += ['status' => 'ended', 'ends_on' => $endsOn];
            }
            DB::table('sponsorships')->where('id', $id)->update($update);
            if ($action === 'resume') {
                $this->extend(DB::table('sponsorships')->where('id', $id)->first(), $today);
            }
            $this->refreshRegularity($id);
            $this->audit->recordRow('sponsorships.'.$action, 'sponsorships', $id, ['status' => $row->status], ['status' => $update['status'], 'reason' => $data['reason']], $actor->id, request()?->ip());
        });
    }

    /**
     * Rechecked at every submission and at the executive decision: the beneficiary is still active, no duplicate,
     * the configured per-child limit, and (for an amendment) an effective date after the last collected period.
     */
    public function assertSubmittable(object $row): void
    {
        if ($row->orphan_child_id) {
            if (DB::table('orphan_children')->where('id', $row->orphan_child_id)->value('status') !== 'active') {
                throw ValidationException::withMessages(['sponsorship' => 'ملف الطفل المستفيد ليس نشطًا.']);
            }
        } elseif (DB::table('orphan_households')->where('id', $row->household_id)->value('status') !== 'active') {
            throw ValidationException::withMessages(['sponsorship' => 'ملف الأسرة المستفيدة ليس نشطًا.']);
        }
        if ($row->supersedes_id) {
            $original = DB::table('sponsorships')->where('id', $row->supersedes_id)->first();
            if (! in_array($original->status, ['active', 'paused'], true)) {
                throw ValidationException::withMessages(['sponsorship' => 'الكفالة الأصلية لم تعد نشطة؛ لا يُعتمد تعديلها.']);
            }
            $this->assertAmendmentDate($original, (string) $row->starts_on);

            return;
        }
        $this->assertNoDuplicate((int) $row->sponsor_id, (int) $row->household_id, $row->orphan_child_id ? (int) $row->orphan_child_id : null, (int) $row->id);
    }

    /** At the executive director's approval (inside the workflow move): an amendment supersedes the earlier version. */
    public function activate(object $row, int $actorId): void
    {
        $this->assertSubmittable($row);
        if (! $row->supersedes_id) {
            return;
        }
        $original = DB::table('sponsorships')->where('id', $row->supersedes_id)->lockForUpdate()->first();
        $endsOn = max((string) $original->starts_on, CarbonImmutable::parse($row->starts_on)->subDay()->toDateString());
        DB::table('sponsorship_schedules')->where('sponsorship_id', $original->id)->where('status', 'expected')->where('period_start', '>=', $row->starts_on)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('sponsorships')->where('id', $original->id)->update(['status' => 'superseded', 'ends_on' => $endsOn,
            'status_reason' => 'استُبدلت بالإصدار '.$row->sponsorship_no, 'updated_at' => now()]);
        $this->audit->recordRow('sponsorships.superseded', 'sponsorships', (int) $original->id, ['status' => $original->status], ['status' => 'superseded', 'by' => $row->sponsorship_no], $actorId);
    }

    /**
     * Generates the dues of an active sponsorship from a date up to the horizon (or its end date). Idempotent: an
     * existing period (including a cancelled one) is never recreated.
     */
    public function extend(object $row, ?string $from = null): int
    {
        if ($row->status !== 'active') {
            return 0;
        }
        $months = (int) (json_decode((string) DB::table('master_data_values')->where('id', $row->frequency_value_id)->value('metadata'), true)['months'] ?? 0);
        if ($months <= 0) {
            return 0;
        }
        $start = CarbonImmutable::parse($row->starts_on);
        $limit = $this->today()->addMonths(self::HORIZON_MONTHS);
        if ($row->ends_on && CarbonImmutable::parse($row->ends_on)->lessThan($limit)) {
            $limit = CarbonImmutable::parse($row->ends_on);
        }
        $from = $from ? CarbonImmutable::parse($from) : $start;
        $rows = [];
        for ($k = 0; $k < 1200; $k++) {
            $period = $start->addMonthsNoOverflow($k * $months);
            if ($period->greaterThan($limit)) {
                break;
            }
            if ($period->lessThan($from)) {
                continue;
            }
            $rows[] = ['sponsorship_id' => $row->id, 'period_start' => $period->toDateString(), 'due_on' => $period->toDateString(), 'expected_amount' => $row->amount,
                'expense_part' => $row->expense_amount, 'savings_part' => $row->savings_amount, 'status' => 'expected', 'created_at' => now(), 'updated_at' => now()];
        }
        $created = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            $created += DB::table('sponsorship_schedules')->insertOrIgnore($chunk);
        }

        return $created;
    }

    /** «ongoing», «paused» or «irregular» (a due unpaid beyond the grace period set by the executive director). */
    public function regularity(object $row): string
    {
        if ($row->status === 'paused') {
            return 'paused';
        }
        $grace = (int) (DB::table('orphans_module_settings')->where('id', 1)->value('sponsorship_grace_days') ?? 30);
        $late = DB::table('sponsorship_schedules')->where('sponsorship_id', $row->id)->whereIn('status', ['expected', 'partial'])
            ->where('due_on', '<', $this->today()->subDays($grace)->toDateString())->exists();

        return $late ? 'irregular' : 'ongoing';
    }

    public function refreshRegularity(int $id): void
    {
        $row = DB::table('sponsorships')->where('id', $id)->first();
        if (! $row || ! in_array($row->status, ['active', 'paused'], true)) {
            return;
        }
        $valueId = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'sponsorship_statuses')
            ->where('v.code', $this->regularity($row))->value('v.id');
        if ($valueId && (int) $row->regularity_value_id !== (int) $valueId) {
            DB::table('sponsorships')->where('id', $id)->update(['regularity_value_id' => $valueId, 'updated_at' => now()]);
        }
    }

    /** Dues with what was collected on each (reversed collections excluded) and what remains. */
    public function scheduleBalances(): Builder
    {
        $collected = DB::table('sponsorship_collections')->whereNull('reversed_at')->groupBy('schedule_id')->selectRaw('schedule_id, sum(amount) as collected');

        return DB::table('sponsorship_schedules as s')->leftJoinSub($collected, 'c', 'c.schedule_id', '=', 's.id')
            ->select('s.*', DB::raw('coalesce(c.collected, 0) as collected'), DB::raw('s.expected_amount - coalesce(c.collected, 0) as remaining'));
    }

    /**
     * Money figures of one sponsorship, from Accounts' records only.
     *
     * @return array{due: string, collected: string, outstanding: string, overdue: int, savings: string, disbursed: string, available: string}
     */
    public function figures(object $row): array
    {
        $today = $this->today()->toDateString();
        $due = DB::query()->fromSub($this->scheduleBalances(), 'b')->where('b.sponsorship_id', $row->id)->whereNotIn('b.status', ['cancelled', 'waived'])->where('b.due_on', '<=', $today);
        $grace = (int) (DB::table('orphans_module_settings')->where('id', 1)->value('sponsorship_grace_days') ?? 30);
        $collected = $this->cents(DB::table('sponsorship_collections')->where('sponsorship_id', $row->id)->whereNull('reversed_at')->sum('amount'));
        $savings = $this->cents(DB::table('orphan_savings_entries')->where('sponsorship_id', $row->id)->selectRaw("coalesce(sum(case when kind = 'credit' then amount else -amount end), 0) as n")->value('n'));
        $disbursed = $this->cents(DB::table('sponsorship_disbursements')->where('sponsorship_id', $row->id)->where('status', 'executed')->sum('amount'));

        return ['due' => FinanceMoney::format($this->cents((clone $due)->sum('b.expected_amount'))), 'collected' => FinanceMoney::format($collected),
            'outstanding' => FinanceMoney::format($this->cents((clone $due)->sum('b.remaining'))),
            'overdue' => (clone $due)->whereIn('b.status', ['expected', 'partial'])->where('b.due_on', '<', $this->today()->subDays($grace)->toDateString())->count(),
            'savings' => FinanceMoney::format($savings), 'disbursed' => FinanceMoney::format($disbursed), 'available' => FinanceMoney::format($collected - $savings - $disbursed)];
    }

    /**
     * What the child file shows: sponsored or not, in words, and the counts behind it.
     *
     * @return array{label: string, sponsored: bool, active: int, paused: int, pending: int, household: int}
     */
    public function childStatus(int $childId, int $householdId): array
    {
        $own = DB::table('sponsorships')->where('orphan_child_id', $childId)
            ->where(fn ($q) => $q->whereIn('status', ['active', 'paused'])->orWhere(fn ($w) => $w->where('status', 'pending_approval')->whereNull('supersedes_id')))->pluck('status');
        $household = DB::table('sponsorships')->where('household_id', $householdId)->whereNull('orphan_child_id')->where('status', 'active')->count();
        $active = $own->filter(fn ($s) => $s === 'active')->count();
        $label = match (true) {
            $active > 0 => 'مكفول ('.$active.' كفالة نشطة)',
            $household > 0 => 'مشمول بكفالة أسرية',
            $own->contains('paused') => 'كفالته موقوفة مؤقتًا',
            $own->contains('pending_approval') => 'كفالة بانتظار الاعتماد',
            default => 'غير مكفول',
        };

        return ['label' => $label, 'sponsored' => $active > 0 || $household > 0, 'active' => $active, 'paused' => $own->filter(fn ($s) => $s === 'paused')->count(),
            'pending' => $own->filter(fn ($s) => $s === 'pending_approval')->count(), 'household' => $household];
    }

    public function mayEditDraft(User $actor, object $row): bool
    {
        return (int) $row->created_by === (int) $actor->id || $this->access->isHead($actor);
    }

    /** @return array{0: ?int, 1: int} child id, household id */
    private function beneficiary(string $type, string $reference): array
    {
        $reference = trim($reference);
        if ($type === 'child') {
            $child = DB::table('orphan_children')->where('file_no', $reference)->first(['id', 'household_id', 'status']);
            if (! $child) {
                throw ValidationException::withMessages(['beneficiary_reference' => 'لا يوجد طفل بهذا الرقم.']);
            }
            if ($child->status !== 'active') {
                throw ValidationException::withMessages(['beneficiary_reference' => 'الكفالة لطفل ملفه نشط (معتمد من المدير التنفيذي).']);
            }

            return [(int) $child->id, (int) $child->household_id];
        }
        $household = DB::table('orphan_households')->where('reference_no', $reference)->first(['id', 'status']);
        if (! $household) {
            throw ValidationException::withMessages(['beneficiary_reference' => 'لا توجد أسرة بهذا الرقم.']);
        }
        if ($household->status !== 'active') {
            throw ValidationException::withMessages(['beneficiary_reference' => 'الكفالة لأسرة ملفها نشط.']);
        }

        return [null, (int) $household->id];
    }

    /** @return array{0: string, 1: string, 2: string} amount, expense part, savings part */
    private function split(int $purposeId, string $amount, ?string $savings, string $beneficiaryType): array
    {
        $flags = json_decode((string) DB::table('master_data_values')->where('id', $purposeId)->value('metadata'), true) ?: [];
        $total = FinanceMoney::cents($amount);
        $expenses = ! empty($flags['expenses']);
        $saves = ! empty($flags['savings']);
        if ($total <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        if (! $expenses && ! $saves) {
            throw ValidationException::withMessages(['purpose_value_id' => 'غرض الكفالة غير معرّف في الثوابت (مصاريف أو ادخار).']);
        }
        if ($saves && $beneficiaryType !== 'child') {
            throw ValidationException::withMessages(['purpose_value_id' => 'الادخار يُقيد في دفتر طفل محدد؛ اختر طفلًا مستفيدًا.']);
        }
        $savingsCents = match (true) {
            $expenses && $saves => FinanceMoney::cents($savings !== null && $savings !== '' ? $savings : '0'),
            $saves => $total,
            default => 0,
        };
        if ($expenses && $saves && ($savingsCents <= 0 || $savingsCents >= $total)) {
            throw ValidationException::withMessages(['savings_amount' => 'في الكفالة المجزأة يكون جزء الادخار أكبر من صفر وأقل من المبلغ الكلي.']);
        }

        return [FinanceMoney::format($total), FinanceMoney::format($total - $savingsCents), FinanceMoney::format($savingsCents)];
    }

    private function assertNoDuplicate(int $sponsorId, int $householdId, ?int $childId, ?int $exceptId): void
    {
        $open = fn ($q) => $q->whereIn('status', ['active', 'paused'])->orWhere(fn ($w) => $w->where('status', 'pending_approval')->whereNull('supersedes_id'));
        $same = DB::table('sponsorships')->where('sponsor_id', $sponsorId)->where('household_id', $householdId)
            ->when($childId, fn ($q) => $q->where('orphan_child_id', $childId), fn ($q) => $q->whereNull('orphan_child_id'))
            ->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->where($open)->exists();
        if ($same) {
            throw ValidationException::withMessages(['beneficiary_reference' => 'للكافل نفسه كفالة قائمة لهذا المستفيد؛ عدّلها بإصدار جديد بدل تكرارها.']);
        }
        $limit = DB::table('orphans_module_settings')->where('id', 1)->value('sponsorship_max_active_per_child');
        if ($childId && $limit) {
            $count = DB::table('sponsorships')->where('orphan_child_id', $childId)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->where($open)->count();
            if ($count >= (int) $limit) {
                throw ValidationException::withMessages(['beneficiary_reference' => 'بلغ الطفل الحد الأقصى المعتمد للكفالات القائمة ('.$limit.').']);
            }
        }
    }

    private function assertAmendmentDate(object $original, string $startsOn): void
    {
        $lastCollected = DB::table('sponsorship_collections as c')->join('sponsorship_schedules as s', 's.id', '=', 'c.schedule_id')
            ->where('c.sponsorship_id', $original->id)->whereNull('c.reversed_at')->max('s.period_start');
        if ($startsOn < (string) $original->starts_on) {
            throw ValidationException::withMessages(['starts_on' => 'لا يسري التعديل قبل بداية الكفالة الأصلية ('.$original->starts_on.').']);
        }
        if ($lastCollected && $startsOn <= (string) $lastCollected) {
            throw ValidationException::withMessages(['starts_on' => 'يسري التعديل بعد آخر فترة حصّلتها الحسابات ('.$lastCollected.').']);
        }
    }

    private function cents(mixed $value): int
    {
        return FinanceMoney::cents(number_format((float) ($value ?? 0), 2, '.', ''));
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(config('orphans.timezone'));
    }
}
