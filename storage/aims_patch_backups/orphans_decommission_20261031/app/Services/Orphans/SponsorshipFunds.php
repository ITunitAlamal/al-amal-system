<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «صندوق الكفالة المجمع» (requirement Q11): several donors give to one fund, and the fund sponsors orphans. The fund
 * is held by an internal sponsor record, so its sponsorships are approved, scheduled and collected exactly like any
 * other. Donations reach it through one-off contributions earmarked «fund». The summary reads Accounts' records only
 * and keeps promises (pledges, commitments) apart from money received.
 */
final class SponsorshipFunds
{
    public function __construct(private readonly OrphanAccess $access, private readonly OrphanRegistry $registry, private readonly AuditService $audit) {}

    public function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('sponsorship_funds');
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): int
    {
        abort_unless($this->access->isHead($actor), 403, 'إنشاء صندوق كفالة لرئيس القسم.');
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:180'], 'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'اسم الصندوق', 'currency' => 'عملة الصندوق', 'description' => 'الوصف'])->validate();

        return DB::transaction(function () use ($actor, $data): int {
            $sponsorId = DB::table('sponsors')->insertGetId(['public_id' => (string) Str::ulid(), 'donor_no' => $this->registry->number('sponsor_ref_seq', 'DNR-'), 'kind' => 'organization',
                'name' => 'صندوق: '.trim($data['name']), 'preferences' => 'سجل داخلي يمثل صندوق كفالة مجمعًا؛ تموّله التبرعات المخصصة للصندوق.', 'confidential' => false,
                'status' => 'active', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('sponsorship_funds')->insertGetId(['public_id' => (string) Str::ulid(), 'fund_no' => $this->registry->number('sponsorship_fund_ref_seq', 'FND-'),
                'name' => trim($data['name']), 'currency' => strtoupper($data['currency']), 'description' => $data['description'] ?? null, 'sponsor_id' => $sponsorId, 'status' => 'active',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.fund.created', 'sponsorship_funds', $id, null, ['name' => trim($data['name']), 'currency' => strtoupper($data['currency']), 'sponsor' => $sponsorId],
                $actor->id, request()?->ip());

            return $id;
        });
    }

    /** The head closes a fund to new donations and sponsorships; running sponsorships continue until ended one by one. */
    public function close(User $actor, int $fundId, string $reason): void
    {
        abort_unless($this->access->isHead($actor), 403, 'إغلاق صندوق الكفالة لرئيس القسم.');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب الإغلاق'])->validate();
        DB::transaction(function () use ($actor, $fundId, $reason): void {
            $fund = DB::table('sponsorship_funds')->where('id', $fundId)->lockForUpdate()->first();
            abort_unless($fund, 404);
            if ($fund->status !== 'active') {
                throw ValidationException::withMessages(['reason' => 'الصندوق مغلق مسبقًا.']);
            }
            DB::table('sponsorship_funds')->where('id', $fundId)->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason, 'updated_at' => now()]);
            DB::table('sponsors')->where('id', $fund->sponsor_id)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->audit->recordRow('sponsorships.fund.closed', 'sponsorship_funds', $fundId, ['status' => 'active'], ['status' => 'closed', 'reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * Each fund with its figures per currency: donations pledged and received, the monthly commitment of its active
     * sponsorships, what Accounts collected for those sponsorships, and the balance from Accounts' records
     * (donations received minus collections recorded for the fund's sponsorships).
     *
     * @return list<array<string, mixed>>
     */
    public function index(): array
    {
        if (! $this->available()) {
            return [];
        }
        $months = $this->frequencyMonths();

        return DB::table('sponsorship_funds as f')->orderByRaw("f.status <> 'active'")->orderBy('f.name')->get()->map(function (object $fund) use ($months): array {
            $figures = [];
            foreach (DB::table('orphan_contributions')->where('earmark_type', 'fund')->where('earmark_id', $fund->id)->whereIn('status', ['pledged', 'collected'])
                ->groupBy('currency', 'status')->selectRaw('currency, status, sum(amount) as total')->get() as $row) {
                $figures[$row->currency][$row->status === 'collected' ? 'received' : 'pledged'] = $this->cents($row->total);
            }
            $sponsorships = DB::table('sponsorships')->where('sponsor_id', $fund->sponsor_id)->where('status', 'active')->get(['id', 'amount', 'currency', 'frequency_value_id']);
            foreach ($sponsorships as $line) {
                $per = $months[$line->frequency_value_id] ?? 0;
                if ($per > 0) {
                    $figures[$line->currency]['monthly_commitment'] = ($figures[$line->currency]['monthly_commitment'] ?? 0) + intdiv($this->cents($line->amount), $per);
                }
            }
            foreach (DB::table('sponsorship_collections as c')->join('sponsorships as s', 's.id', '=', 'c.sponsorship_id')->where('s.sponsor_id', $fund->sponsor_id)->whereNull('c.reversed_at')
                ->groupBy('c.currency')->selectRaw('c.currency, sum(c.amount) as total')->get() as $row) {
                $figures[$row->currency]['spent'] = $this->cents($row->total);
            }
            $rows = collect($figures)->map(fn (array $f, string $currency): array => ['currency' => $currency,
                'pledged' => FinanceMoney::format($f['pledged'] ?? 0), 'received' => FinanceMoney::format($f['received'] ?? 0),
                'monthly_commitment' => FinanceMoney::format($f['monthly_commitment'] ?? 0), 'spent' => FinanceMoney::format($f['spent'] ?? 0),
                'balance' => FinanceMoney::format(($f['received'] ?? 0) - ($f['spent'] ?? 0))])->values()->all();

            return ['id' => (int) $fund->id, 'public_id' => $fund->public_id, 'fund_no' => $fund->fund_no, 'name' => $fund->name, 'currency' => $fund->currency, 'description' => $fund->description,
                'status' => $fund->status, 'close_reason' => $fund->close_reason, 'sponsorships' => $sponsorships->count(), 'figures' => $rows];
        })->all();
    }

    /** @return array<int, int> months per sponsorship frequency value */
    public function frequencyMonths(): array
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'sponsorship_frequencies')->get(['v.id', 'v.metadata'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (int) (json_decode((string) $row->metadata, true)['months'] ?? 0)])->all();
    }

    private function cents(mixed $value): int
    {
        return FinanceMoney::cents(number_format((float) ($value ?? 0), 2, '.', ''));
    }
}
