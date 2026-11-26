<?php

namespace App\Services\Orphans;

use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «فجوة الاحتياج والتعهدات» (requirement Q38, option C): a researcher records a family's assessed monthly need with
 * its basis; the report compares it with the monthly commitment of the family's active sponsorships (expense part
 * only, same currency). Money received is shown beside it for information and never counted as a commitment or
 * as covering the need; no currency is converted.
 */
final class HouseholdNeeds
{
    public function __construct(private readonly OrphanAccess $access, private readonly SponsorshipFunds $funds, private readonly AuditService $audit) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('orphan_need_assessments');
    }

    public function canAssess(?User $user): bool
    {
        return $this->access->allows($user, 'records') || $this->access->allows($user, 'visits');
    }

    /** @param array<string, mixed> $input */
    public function assess(User $actor, OrphanHousehold $household, array $input): int
    {
        abort_unless($this->canAssess($actor), 403, 'تقدير احتياج الأسرة لموظفي القسم المكلفين بالملفات أو الزيارات.');
        $data = Validator::make($input, [
            'monthly_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'], 'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'basis' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], ['monthly_amount' => 'الاحتياج الشهري', 'currency' => 'العملة', 'basis' => 'أساس التقدير'])->validate();
        if (FinanceMoney::cents($data['monthly_amount']) <= 0) {
            throw ValidationException::withMessages(['monthly_amount' => 'الاحتياج الشهري أكبر من صفر.']);
        }
        if (! in_array($household->status, ['registered', 'active'], true)) {
            throw ValidationException::withMessages(['monthly_amount' => 'يُقدَّر احتياج ملف قيد الدراسة أو نشط فقط.']);
        }

        return DB::transaction(function () use ($actor, $household, $data): int {
            DB::table('orphan_households')->where('id', $household->id)->lockForUpdate()->first();
            $before = DB::table('orphan_need_assessments')->where('household_id', $household->id)->where('is_current', true)->first(['monthly_amount', 'currency']);
            DB::table('orphan_need_assessments')->where('household_id', $household->id)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
            $id = DB::table('orphan_need_assessments')->insertGetId(['household_id' => $household->id, 'monthly_amount' => FinanceMoney::format(FinanceMoney::cents($data['monthly_amount'])),
                'currency' => strtoupper($data['currency']), 'basis' => trim($data['basis']), 'assessed_on' => CarbonImmutable::today(config('orphans.timezone'))->toDateString(),
                'is_current' => true, 'assessed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.need.assessed', 'orphan_need_assessments', $id, $before ? (array) $before : null,
                ['monthly_amount' => $data['monthly_amount'], 'currency' => strtoupper($data['currency'])], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** @return list<array<string, mixed>> the family's assessments, current first */
    public function history(int $householdId): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('orphan_need_assessments as n')->leftJoin('users as u', 'u.id', '=', 'n.assessed_by')->where('n.household_id', $householdId)
            ->orderByDesc('n.is_current')->orderByDesc('n.id')->limit(20)->get(['n.id', 'n.monthly_amount', 'n.currency', 'n.basis', 'n.assessed_on', 'n.is_current', 'u.name as assessor'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'monthly_amount' => $row->monthly_amount, 'currency' => $row->currency, 'basis' => $row->basis,
                'assessed_on' => (string) $row->assessed_on, 'is_current' => (bool) $row->is_current, 'assessor' => $row->assessor])->all();
    }

    /**
     * One family's position: the current need, the monthly commitment in the need's currency (expense part of active
     * sponsorships of the family and its children), the gap and coverage, commitments in other currencies, and the
     * money Accounts recorded over the last 90 days (for information only).
     *
     * @return array<string, mixed>|null
     */
    public function position(int $householdId): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $need = DB::table('orphan_need_assessments')->where('household_id', $householdId)->where('is_current', true)->first();
        $months = $this->funds->frequencyMonths();
        $commitments = [];
        $ids = [];
        foreach (DB::table('sponsorships')->where('household_id', $householdId)->where('status', 'active')->get(['id', 'expense_amount', 'currency', 'frequency_value_id']) as $line) {
            $ids[] = $line->id;
            $per = $months[$line->frequency_value_id] ?? 0;
            if ($per > 0) {
                $commitments[$line->currency] = ($commitments[$line->currency] ?? 0) + intdiv(FinanceMoney::cents((string) $line->expense_amount), $per);
            }
        }
        $received = DB::table('sponsorship_collections')->whereIn('sponsorship_id', $ids ?: [0])->whereNull('reversed_at')
            ->where('posted_on', '>=', CarbonImmutable::today(config('orphans.timezone'))->subDays(90)->toDateString())
            ->groupBy('currency')->selectRaw('currency, sum(amount) as total')->pluck('total', 'currency')
            ->map(fn ($total) => FinanceMoney::format(FinanceMoney::cents(number_format((float) $total, 2, '.', ''))))->all();
        $needCents = $need ? FinanceMoney::cents((string) $need->monthly_amount) : null;
        $committed = $need ? ($commitments[$need->currency] ?? 0) : 0;
        $others = collect($commitments)->except($need?->currency ?? '')->map(fn (int $cents, string $currency): string => FinanceMoney::format($cents).' '.$currency)->values()->all();

        return ['need' => $need ? FinanceMoney::format($needCents) : null, 'currency' => $need?->currency, 'assessed_on' => $need ? (string) $need->assessed_on : null,
            'commitment' => FinanceMoney::format($committed), 'gap' => $need ? FinanceMoney::format(max(0, $needCents - $committed)) : null,
            'coverage' => $need ? min(100, (int) floor($committed * 100 / $needCents)) : null, 'other_commitments' => $others, 'received_90_days' => $received,
            'active_sponsorships' => count($ids)];
    }

    /**
     * The department's gap report: every followed family with a current assessment, the largest gap first, and the
     * number of active files with no assessment yet.
     *
     * @return array{rows: list<array<string, mixed>>, unassessed: int}
     */
    public function report(): array
    {
        if (! $this->available()) {
            return ['rows' => [], 'unassessed' => 0];
        }
        $rows = DB::table('orphan_need_assessments as n')->join('orphan_households as h', 'h.id', '=', 'n.household_id')->where('n.is_current', true)
            ->whereIn('h.status', ['registered', 'active'])->limit(1000)->get(['h.id', 'h.public_id', 'h.reference_no', 'h.family_name'])
            ->map(fn (object $row): array => ['household' => $row->reference_no, 'household_public_id' => $row->public_id, 'family_name' => $this->decrypt($row->family_name)]
                + $this->position((int) $row->id))
            ->sortByDesc(fn (array $row): int => FinanceMoney::cents((string) $row['gap']))->values()->all();

        return ['rows' => $rows, 'unassessed' => DB::table('orphan_households as h')->where('h.status', 'active')
            ->whereNotExists(fn ($q) => $q->from('orphan_need_assessments as n')->whereColumn('n.household_id', 'h.id')->where('n.is_current', true))->count()];
    }

    private function decrypt(?string $cipher): ?string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
