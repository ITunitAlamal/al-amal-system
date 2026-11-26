<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\OrphanChild;
use App\Services\Finance\FinanceGuard;
use App\Services\Orphans\OrphanContributions;
use App\Services\Orphans\SponsorshipAccounts;
use App\Services\Orphans\Sponsorships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts' page for sponsorships: approve families' payout details, record collections against dues, payouts,
 * savings withdrawals and receipts of one-off donations, each from a voucher of the external accounting software.
 */
class FinanceSponsorshipsController extends Controller
{
    private const FILTERS = ['open' => 'المستحق حتى شهر قادم', 'overdue' => 'المتأخرة', 'collected' => 'المحصلة', 'all' => 'الكل'];

    public function __construct(private readonly SponsorshipAccounts $accounts, private readonly Sponsorships $sponsorships) {}

    public function index(Request $r, OrphanContributions $contributions, FinanceGuard $guard): Response
    {
        $user = $r->user();
        $this->accounts->assert($user);
        $filters = $r->validate(['filter' => ['nullable', Rule::in(array_keys(self::FILTERS))], 'search' => ['nullable', 'string', 'max:40']]);
        $filter = $filters['filter'] ?? 'open';
        $search = trim((string) ($filters['search'] ?? ''));
        $today = today(config('orphans.timezone'));
        $grace = (int) (DB::table('orphans_module_settings')->where('id', 1)->value('sponsorship_grace_days') ?? 30);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');

        $dues = DB::query()->fromSub($this->sponsorships->scheduleBalances(), 'b')->join('sponsorships as s', 's.id', '=', 'b.sponsorship_id')
            ->join('sponsors as d', 'd.id', '=', 's.sponsor_id')->join('orphan_households as h', 'h.id', '=', 's.household_id')->leftJoin('orphan_children as c', 'c.id', '=', 's.orphan_child_id')
            ->whereNotIn('b.status', ['cancelled', 'waived'])
            ->when($filter === 'open', fn ($q) => $q->whereIn('b.status', ['expected', 'partial'])->where('b.due_on', '<=', $today->copy()->addMonth()->toDateString()))
            ->when($filter === 'overdue', fn ($q) => $q->whereIn('b.status', ['expected', 'partial'])->where('b.due_on', '<', $today->copy()->subDays($grace)->toDateString()))
            ->when($filter === 'collected', fn ($q) => $q->whereIn('b.status', ['collected', 'partial']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('s.sponsorship_no', 'ilike', "%{$search}%")->orWhere('c.file_no', 'ilike', "%{$search}%")
                ->orWhere('h.reference_no', 'ilike', "%{$search}%")->orWhere('d.donor_no', 'ilike', "%{$search}%")))
            ->orderBy('b.due_on')->limit(500)
            ->get(['b.id', 'b.period_start', 'b.due_on', 'b.expected_amount', 'b.collected', 'b.remaining', 'b.savings_part', 'b.status', 's.sponsorship_no', 's.public_id',
                's.currency', 's.orphan_child_id', 'd.name as sponsor_name', 'd.donor_no', 'd.confidential', 'c.file_no', 'h.reference_no as household']);
        $names = OrphanChild::whereIn('id', $dues->pluck('orphan_child_id')->filter()->unique())->get(['id', 'full_name'])->pluck('full_name', 'id');
        $available = "coalesce((select sum(c.amount) from sponsorship_collections c where c.sponsorship_id = s.id and c.reversed_at is null), 0)
            - coalesce((select sum(case when e.kind = 'credit' then e.amount else -e.amount end) from orphan_savings_entries e where e.sponsorship_id = s.id), 0)
            - coalesce((select sum(x.amount) from sponsorship_disbursements x where x.sponsorship_id = s.id and x.status = 'executed'), 0)";

        return Inertia::render('Finance/Sponsorships', [
            'dues' => $dues->map(fn ($d) => ['id' => $d->id, 'period_start' => $d->period_start, 'due_on' => $d->due_on, 'expected' => $d->expected_amount, 'collected' => $d->collected,
                'remaining' => $d->remaining, 'savings_part' => $d->savings_part, 'status' => $d->status, 'status_label' => Sponsorships::SCHEDULE_LABELS[$d->status] ?? $d->status,
                'overdue' => in_array($d->status, ['expected', 'partial'], true) && $d->due_on < $today->copy()->subDays($grace)->toDateString(),
                'sponsorship_no' => $d->sponsorship_no, 'sponsorship_public_id' => $d->public_id, 'currency' => $d->currency, 'donor' => $d->donor_no.' — '.$d->sponsor_name,
                'beneficiary' => $d->orphan_child_id ? ($names[$d->orphan_child_id] ?? '').' ('.$d->file_no.')' : 'أسرة '.$d->household]),
            'collections' => DB::table('sponsorship_collections as c')->join('sponsorships as s', 's.id', '=', 'c.sponsorship_id')->join('sponsorship_schedules as p', 'p.id', '=', 'c.schedule_id')
                ->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')->orderByDesc('c.id')->limit(200)
                ->get(['c.id', 'c.amount', 'c.currency', 'c.accounting_reference', 'c.posted_on', 'c.reversed_at', 'c.reversal_reason', 's.sponsorship_no', 'p.period_start', 'u.name as recorded_by']),
            'payable' => DB::table('sponsorships as s')->join('orphan_households as h', 'h.id', '=', 's.household_id')->whereIn('s.status', ['active', 'paused', 'ended', 'superseded'])
                ->whereRaw("({$available}) > 0")->orderBy('s.sponsorship_no')->limit(500)
                ->get(['s.id', 's.sponsorship_no', 's.currency', 'h.reference_no as household', DB::raw("({$available}) as available"),
                    DB::raw("exists(select 1 from orphan_payment_instructions p where p.household_id = s.household_id and p.status = 'approved') as has_payout")]),
            'disbursements' => DB::table('sponsorship_disbursements as x')->join('sponsorships as s', 's.id', '=', 'x.sponsorship_id')->join('orphan_households as h', 'h.id', '=', 'x.household_id')
                ->leftJoin('users as u', 'u.id', '=', 'x.recorded_by')->orderByDesc('x.id')->limit(200)
                ->get(['x.id', 'x.amount', 'x.currency', 'x.accounting_reference', 'x.executed_on', 'x.status', 'x.reversal_reason', 's.sponsorship_no', 'h.reference_no as household', 'u.name as recorded_by']),
            'payouts' => DB::table('orphan_payment_instructions as p')->join('orphan_households as h', 'h.id', '=', 'p.household_id')->leftJoin('users as u', 'u.id', '=', 'p.submitted_by')
                ->where('p.status', 'pending_review')->orderBy('p.id')->limit(200)
                ->get(['p.id', 'p.channel_value_id', 'p.institution_value_id', 'p.account_last4', 'p.created_at', 'p.submitted_by', 'h.reference_no as household', 'u.name as submitted_by_name'])
                ->map(fn ($p) => ['id' => $p->id, 'household' => $p->household, 'channel' => $values[$p->channel_value_id] ?? '', 'institution' => $values[$p->institution_value_id] ?? null,
                    'account' => '••••'.$p->account_last4, 'submitted_by' => $p->submitted_by_name, 'created_at' => $p->created_at, 'own' => (int) $p->submitted_by === (int) $user->id]),
            'savings' => DB::table('orphan_savings_entries as e')->join('orphan_children as c', 'c.id', '=', 'e.orphan_child_id')->groupBy('c.file_no', 'e.currency')
                ->selectRaw("c.file_no, e.currency, sum(case when e.kind = 'credit' then e.amount else -e.amount end) as balance")->orderBy('c.file_no')->limit(500)->get(),
            'savingsEntries' => DB::table('orphan_savings_entries as e')->join('orphan_children as c', 'c.id', '=', 'e.orphan_child_id')->leftJoin('users as u', 'u.id', '=', 'e.recorded_by')
                ->orderByDesc('e.id')->limit(100)->get(['e.id', 'e.kind', 'e.amount', 'e.currency', 'e.accounting_reference', 'e.reason', 'e.created_at', 'c.file_no', 'u.name as recorded_by']),
            'contributions' => DB::table('orphan_contributions as o')->leftJoin('sponsors as d', 'd.id', '=', 'o.sponsor_id')->whereIn('o.status', ['pledged', 'collected'])
                ->orderByRaw("o.status = 'pledged' desc")->orderByDesc('o.id')->limit(200)->get(['o.*', 'd.name as sponsor_name', 'd.donor_no'])
                ->map(fn ($o) => ['id' => $o->id, 'reference_no' => $o->reference_no, 'amount' => $o->amount, 'currency' => $o->currency, 'status' => $o->status,
                    'status_label' => OrphanContributions::STATUS_LABELS[$o->status] ?? $o->status, 'earmark' => $contributions->earmarkLabel($o),
                    'donor' => $o->sponsor_id ? $o->donor_no.' — '.$o->sponsor_name : $o->donor_name, 'accounting_reference' => $o->accounting_reference, 'posted_on' => $o->posted_on]),
            'currencies' => $this->accounts->currencies(),
            'externalSystem' => $guard->externalSystemName(),
            'filters' => ['filter' => $filter, 'search' => $search], 'filterLabels' => self::FILTERS,
        ]);
    }

    public function action(Request $r, string $action): RedirectResponse
    {
        $user = $r->user();
        $id = $r->integer('id');
        $message = match ($action) {
            'payout-approve' => tap('اعتُمدت بيانات الصرف للأسرة.', fn () => $this->accounts->reviewPayment($user, $id, 'approved', $r->input('note'))),
            'payout-reject' => tap('رُفضت بيانات الصرف وسُجل السبب.', fn () => $this->accounts->reviewPayment($user, $id, 'rejected', $r->input('note'))),
            'collection' => $this->accounts->recordCollection($user, $r->all()) === 0 ? 'سبق تسجيل هذه العملية؛ لم يتكرر التحصيل.' : 'سُجل التحصيل على الاستحقاق.',
            'collection-reverse' => tap('عُكس التحصيل وبقي السجل للتدقيق.', fn () => $this->accounts->reverseCollection($user, $id, (string) $r->input('reason'))),
            'disbursement' => tap('سُجل الصرف للأسرة.', fn () => $this->accounts->recordDisbursement($user, $r->all())),
            'disbursement-reverse' => tap('عُكس الصرف وبقي السجل للتدقيق.', fn () => $this->accounts->reverseDisbursement($user, $id, (string) $r->input('reason'))),
            'savings-debit' => tap('سُجل السحب من ادخار الطفل.', fn () => $this->accounts->recordSavingsDebit($user, $r->all())),
            'contribution-collect' => tap('سُجل قبض التبرع.', fn () => $this->accounts->collectContribution($user, $id, $r->all())),
            'contribution-refund' => tap('سُجل رد التبرع.', fn () => $this->accounts->refundContribution($user, $id, (string) $r->input('reason'))),
            default => abort(404),
        };

        return back()->with('success', $message);
    }

    /** Full payout details of a pending instruction, revealed on request and logged. */
    public function payoutDetails(Request $r, int $instruction): JsonResponse
    {
        return response()->json($this->accounts->paymentDetails($r->user(), $instruction))->header('Cache-Control', 'no-store, private');
    }
}
