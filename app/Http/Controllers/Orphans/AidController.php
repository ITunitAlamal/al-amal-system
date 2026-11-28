<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\Aid;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «الكفالات والمساعدات»: sponsors, sponsorships with Accounts referral, assistance requests and confirmed benefits. */
final class AidController extends Controller
{
    public function __construct(private readonly OrphanAccess $access, private readonly Aid $aid, private readonly RegistryQueries $queries) {}

    public function index(Request $request): Response
    {
        $this->access->assertView($request->user());
        $tab = in_array($request->query('tab'), ['sponsors', 'assistance', 'benefits'], true) ? $request->query('tab') : 'sponsorships';
        $q = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $data = match ($tab) {
            'sponsors' => DB::table('sponsors')->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', $like)->orWhere('reference_no', 'ilike', $like)))->orderBy('name')
                ->select(['*'])->selectSub(DB::table('sponsorships')->whereColumn('sponsor_id', 'sponsors.id')->whereIn('status', ['active', 'partial'])->selectRaw('count(*)'), 'active_count')->paginate(25)->withQueryString(),
            'assistance' => DB::table('assistance_requests as a')->leftJoin('orphans as o', 'o.id', '=', 'a.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
                ->leftJoin('households as h', 'h.id', '=', 'a.household_id')->leftJoin('master_data_values as t', 't.id', '=', 'a.type_value_id')
                ->when($request->query('status'), fn ($w, $s) => $w->where('a.status', $s))->orderByRaw("CASE a.status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('a.id')
                ->select(['a.*', 'p.full_name as orphan_name', 'o.public_id as orphan_public_id', 'h.reference_no as household_reference', 't.name_ar as type'])->paginate(25)->withQueryString(),
            'benefits' => DB::table('benefit_records as b')->leftJoin('orphans as o', 'o.id', '=', 'b.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
                ->leftJoin('households as h', 'h.id', '=', 'b.household_id')->leftJoin('users as u', 'u.id', '=', 'b.recorded_by')->orderByDesc('b.id')
                ->select(['b.*', 'p.full_name as orphan_name', 'o.public_id as orphan_public_id', 'h.reference_no as household_reference', 'u.name as recorded_by_name'])
                ->selectSub(DB::table('benefit_records as r')->whereColumn('r.reverses_id', 'b.id')->selectRaw('count(*) > 0'), 'reversed')->paginate(30)->withQueryString(),
            default => DB::table('sponsorships as s')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')->leftJoin('master_data_values as v', 'v.id', '=', 's.program_value_id')
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('s.reference_no', 'ilike', $like)->orWhere('p.name', 'ilike', $like)))
                ->when($request->query('status'), fn ($w, $s) => $w->where('s.status', $s))->orderByDesc('s.id')
                ->select(['s.*', 'p.name as sponsor_name', 'v.name_ar as program'])
                ->selectSub(DB::table('sponsorship_beneficiaries')->whereColumn('sponsorship_id', 's.id')->whereNull('ends_on')->selectRaw('count(*)'), 'beneficiaries')->paginate(25)->withQueryString()
                ->through(fn ($s) => [...(array) $s, 'effective_status' => Aid::effectiveStatus($s)]),
        };

        return Inertia::render('Orphans/Aid/Index', ['tab' => $tab, 'rows' => $data, 'filters' => ['q' => $q, 'status' => $request->query('status')], 'abilities' => $this->access->abilities($request->user()),
            'labels' => ['sponsorship' => Aid::SPONSORSHIP_STATUS, 'coverage' => Aid::COVERAGE, 'assistance' => Aid::ASSISTANCE_STATUS, 'fulfillment' => Aid::FULFILLMENT_KINDS],
            'options' => ['types' => $this->queries->values('assistance_types'), 'programs' => $this->queries->values('sponsorship_types'), 'frequencies' => $this->queries->values('sponsorship_frequencies'),
                'currencies' => DB::table('currencies')->orderBy('code')->pluck('code')],
            'sponsors' => $tab === 'sponsorships' ? DB::table('sponsors')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'reference_no']) : []]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->access->assertView($request->user());
        $s = DB::table('sponsorships as s')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')->leftJoin('master_data_values as v', 'v.id', '=', 's.program_value_id')
            ->where('s.public_id', $publicId)->firstOrFail(['s.*', 'p.name as sponsor_name', 'p.reference_no as sponsor_reference', 'v.name_ar as program']);

        return Inertia::render('Orphans/Aid/Sponsorship', [
            'sponsorship' => [...(array) $s, 'effective_status' => Aid::effectiveStatus($s)],
            'beneficiaries' => DB::table('sponsorship_beneficiaries as b')->leftJoin('orphans as o', 'o.id', '=', 'b.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
                ->leftJoin('households as h', 'h.id', '=', 'b.household_id')->where('b.sponsorship_id', $s->id)->orderByRaw('b.ends_on IS NOT NULL')->orderBy('b.id')
                ->get(['b.id', 'b.share_amount', 'b.starts_on', 'b.ends_on', 'b.ended_reason', 'p.full_name', 'o.public_id as orphan_public_id', 'o.reference_no as orphan_reference', 'h.reference_no as household_reference']),
            'dues' => DB::table('sponsorship_dues')->where('sponsorship_id', $s->id)->orderBy('period_start')->get()
                ->map(function ($d): array {
                    $collected = Aid::collected($d->finance_receivable_id, (int) $d->id);

                    return [...(array) $d, 'collected' => $collected, 'receivable' => $d->finance_receivable_id ? DB::table('finance_receivables')->where('id', $d->finance_receivable_id)->value('reference') : null,
                        // Same rule as Aid::overdueDues(): referred, past its date, collected below the amount.
                        'overdue' => $d->status === 'referred' && $d->due_on < today()->toDateString() && round((float) $collected, 2) < round((float) $d->amount, 2)];
                }),
            'benefits' => Aid::benefits(['sponsorship_id' => $s->id]),
            'labels' => ['sponsorship' => Aid::SPONSORSHIP_STATUS, 'coverage' => Aid::COVERAGE], 'abilities' => $this->access->abilities($request->user()),
        ]);
    }

    // Every action authorizes first (AC-07): its validation checks that sponsors, orphans and households exist, so a
    // viewer without the capability must be refused before it runs. Aid repeats each check inside its transaction.

    public function storeSponsor(Request $request): RedirectResponse
    {
        $this->access->assert($request->user(), 'sponsorships');
        $data = $request->validate(['kind' => ['required', Rule::in(['individual', 'institution'])], 'name' => 'required|string|max:300', 'contact_name' => 'nullable|string|max:200',
            'phone' => 'nullable|string|max:20', 'email' => 'nullable|email|max:150', 'country' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000', 'id' => 'nullable|integer|exists:sponsors,id',
            'status' => ['nullable', Rule::in(['active', 'inactive'])]], [], ['name' => 'اسم الكافل']);
        $this->aid->saveSponsor($data, $data['id'] ?? null, $request->user());

        return back()->with('success', 'تم حفظ بيانات الكافل.');
    }

    public function storeSponsorship(Request $request): RedirectResponse
    {
        $this->access->assert($request->user(), 'sponsorships');
        $data = $request->validate(['sponsor_id' => 'required|integer|exists:sponsors,id', 'program_value_id' => 'nullable|integer|exists:master_data_values,id',
            'coverage' => ['required', Rule::in(array_keys(Aid::COVERAGE))], 'amount' => 'required|numeric|min:0.01|max:999999999', 'currency' => 'required|string|exists:currencies,code',
            'period_months' => 'required|integer|min:1|max:12', 'frequency_value_id' => 'nullable|integer|exists:master_data_values,id', 'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'notes' => 'nullable|string|max:2000', 'orphans' => 'array', 'orphans.*' => 'string|exists:orphans,public_id',
            'households' => 'array', 'households.*' => 'string|exists:households,reference_no', 'renewal_of' => 'nullable|string|exists:sponsorships,public_id'],
            [], ['amount' => 'قيمة الكفالة', 'starts_on' => 'تاريخ البدء', 'sponsor_id' => 'الكافل']);
        $orphans = DB::table('orphans')->whereIn('public_id', $data['orphans'] ?? [])->pluck('id')->all();
        $households = DB::table('households')->whereIn('reference_no', $data['households'] ?? [])->pluck('id')->all();
        $data['renewal_of_id'] = ! empty($data['renewal_of']) ? DB::table('sponsorships')->where('public_id', $data['renewal_of'])->value('id') : null;
        $id = $this->aid->createSponsorship($data, $orphans, $households, $request->user());

        return redirect('/orphans/aid/sponsorships/'.DB::table('sponsorships')->where('id', $id)->value('public_id'))->with('success', 'تم إنشاء الكفالة كمسودة؛ يفعّلها رئيس القسم.');
    }

    public function sponsorshipAction(Request $request, string $publicId): RedirectResponse
    {
        // Every action here needs the capability (a status change also needs the head, who holds every capability).
        $this->access->assert($request->user(), 'sponsorships');
        $s = DB::table('sponsorships')->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['status', 'schedule', 'refer', 'add_orphan', 'add_household', 'end_line'])],
            'status' => ['required_if:action,status', 'nullable', Rule::in(array_keys(Aid::TRANSITIONS))], 'expected_status' => ['required_if:action,status', 'nullable', Rule::in(array_keys(Aid::TRANSITIONS))],
            'reason' => 'nullable|string|max:300', 'due_id' => 'nullable|integer', 'line_id' => 'nullable|integer', 'orphan_public_id' => 'nullable|string|exists:orphans,public_id',
            'household_reference' => 'nullable|string|exists:households,reference_no', 'share_amount' => 'nullable|numeric|min:0']);
        $user = $request->user();
        $message = match ($data['action']) {
            'status' => tap('تم تحديث حالة الكفالة.', fn () => $this->aid->setStatus($s, $data['status'], $data['reason'] ?? null, $user, $data['expected_status'])),
            'schedule' => tap('تم تحديث جدول الاستحقاقات.', function () use ($s, $user): void {
                $this->access->assert($user, 'sponsorships');
                $this->aid->schedule($s);
            }),
            'refer' => tap('أُحيل الاستحقاق للحسابات؛ التحصيل يُؤكَّد من الحسابات.', fn () => $this->aid->refer(DB::table('sponsorship_dues')->where('sponsorship_id', $s->id)->where('id', $data['due_id'] ?? 0)->firstOrFail(), $user)),
            'add_orphan' => tap('أُضيف اليتيم إلى الكفالة.', function () use ($s, $data, $user): void {
                $this->access->assert($user, 'sponsorships');
                $this->aid->addBeneficiary($s->id, ['orphan_id' => DB::table('orphans')->where('public_id', $data['orphan_public_id'] ?? '')->value('id') ?? abort(422)], today()->toDateString(), $user, $data['share_amount'] ?? null);
            }),
            'add_household' => tap('أُضيفت الأسرة إلى الكفالة.', function () use ($s, $data, $user): void {
                $this->access->assert($user, 'sponsorships');
                $this->aid->addBeneficiary($s->id, ['household_id' => DB::table('households')->where('reference_no', $data['household_reference'] ?? '')->value('id') ?? abort(422)], today()->toDateString(), $user, $data['share_amount'] ?? null);
            }),
            'end_line' => tap('أُنهي سطر المستفيد مع حفظه.', fn () => $this->aid->endBeneficiary((int) $s->id, (int) ($data['line_id'] ?? 0), (string) ($data['reason'] ?? ''), $user)),
        };

        return back()->with('success', $message);
    }

    public function storeAssistance(Request $request): RedirectResponse
    {
        $this->access->assert($request->user(), 'sponsorships');
        $data = $request->validate(['unit' => ['required', Rule::in(['orphan', 'household'])], 'orphan_public_id' => 'required_if:unit,orphan|nullable|string|exists:orphans,public_id',
            'household_reference' => 'required_if:unit,household|nullable|string|exists:households,reference_no', 'type_value_id' => 'nullable|integer|exists:master_data_values,id',
            'description' => 'required|string|max:2000', 'quantity' => 'nullable|numeric|min:0', 'amount' => 'nullable|numeric|min:0', 'currency' => 'nullable|string|exists:currencies,code']);
        $unit = $data['unit'] === 'orphan' ? ['orphan_id' => DB::table('orphans')->where('public_id', $data['orphan_public_id'])->value('id')]
            : ['household_id' => DB::table('households')->where('reference_no', $data['household_reference'])->value('id')];
        $this->aid->createAssistance($data, $unit, $request->user());

        return back()->with('success', 'تم تسجيل طلب المساعدة.');
    }

    public function decideAssistance(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertDecide($request->user(), 'اعتماد طلبات المساعدة لرئيس القسم.');
        $row = DB::table('assistance_requests')->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected', 'cancelled'])], 'note' => 'nullable|string|max:2000']);
        $this->aid->decideAssistance($row, $data['decision'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'تم تسجيل القرار. الاعتماد ليس تسليمًا؛ يُسجَّل التسليم عند تنفيذه.');
    }

    public function storeBenefit(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'sponsorships') || $this->access->allows($request->user(), 'delivery'), 403, 'تسجيل الاستفادة يحتاج قدرة الكفالات أو التسليم.');
        $data = $request->validate(['unit' => ['required', Rule::in(['orphan', 'household'])], 'orphan_public_id' => 'nullable|string|exists:orphans,public_id',
            'household_reference' => 'nullable|string|exists:households,reference_no', 'source' => ['required', Rule::in(['sponsorship', 'assistance', 'other'])],
            'sponsorship_public_id' => 'nullable|string|exists:sponsorships,public_id', 'assistance_public_id' => 'nullable|string|exists:assistance_requests,public_id',
            'type_value_id' => 'nullable|integer|exists:master_data_values,id', 'description' => 'required|string|max:500', 'quantity' => 'nullable|numeric|min:0', 'unit_label' => 'nullable|string|max:30',
            'amount' => 'nullable|numeric|min:0', 'currency' => 'nullable|string|exists:currencies,code', 'recipient_name' => 'required|string|max:300', 'delivered_on' => 'required|date|before_or_equal:today',
            'fulfillment_ref' => 'nullable|string|max:140', 'fulfillment_kind' => ['nullable', Rule::in(array_keys(Aid::FULFILLMENT_KINDS))]],
            [], ['recipient_name' => 'اسم المستلم الفعلي', 'delivered_on' => 'تاريخ التسليم', 'fulfillment_ref' => 'مرجع التنفيذ', 'fulfillment_kind' => 'نوع مرجع التنفيذ']);
        $unit = $data['unit'] === 'orphan' ? ['orphan_id' => DB::table('orphans')->where('public_id', $data['orphan_public_id'] ?? '')->value('id')]
            : ['household_id' => DB::table('households')->where('reference_no', $data['household_reference'] ?? '')->value('id')];
        $this->aid->recordBenefit([...$data, 'unit' => $data['unit_label'] ?? null,
            'sponsorship_id' => ! empty($data['sponsorship_public_id']) ? DB::table('sponsorships')->where('public_id', $data['sponsorship_public_id'])->value('id') : null,
            'assistance_request_id' => ! empty($data['assistance_public_id']) ? DB::table('assistance_requests')->where('public_id', $data['assistance_public_id'])->value('id') : null], $unit, $request->user());

        return back()->with('success', 'سُجّلت الاستفادة المؤكدة.');
    }

    public function reverseBenefit(Request $request, string $publicId): RedirectResponse
    {
        $this->access->assertDecide($request->user(), 'عكس استفادة مؤكدة لرئيس القسم.');
        $row = DB::table('benefit_records')->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['reason' => 'required|string|max:500'], [], ['reason' => 'سبب التصحيح']);
        $this->aid->reverseBenefit($row, $data['reason'], $request->user());

        return back()->with('success', 'سُجّل قيد تصحيح؛ القيد الأصلي محفوظ.');
    }
}
