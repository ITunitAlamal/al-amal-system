<?php

namespace App\Services\Orphans;

use Illuminate\Support\Facades\DB;

/**
 * «التقارير والإحصائيات»: real, time- and permission-scoped figures. Filters reuse the registry vocabulary, so a
 * report and the registry list count the same rows. Unique beneficiaries, delivery transactions and delivered units
 * are always reported separately; commitments are never shown as receipts, approvals never as deliveries.
 */
final class Reports
{
    public function __construct(private readonly RegistryQueries $queries, private readonly Dashboard $dashboard) {}

    public function build(array $filters, ?string $from, ?string $to): array
    {
        $base = fn () => $this->queries->orphanQuery($filters);
        $total = $base()->count();
        $rate = fn (int $n) => $total ? round($n / $total * 100, 1) : null;
        $quality = $base()->selectRaw("count(*) filter (where p.id_format = 'valid_format') as id_ok, count(*) filter (where p.date_of_birth is not null) as dob,
            count(*) filter (where p.sex is not null) as sex, count(*) filter (where a.id is not null) as address,
            count(*) filter (where (select count(*) from orphan_parent_links l where l.orphan_id = o.id) = 2) as parents,
            count(*) filter (where exists (select 1 from orphan_guardianships g where g.orphan_id = o.id and g.status = 'approved')) as guardian,
            count(*) filter (where o.status = 'active') as approved, count(*) filter (where o.status = 'provisional') as provisional, count(*) filter (where o.completeness = 100) as complete")->first();
        $group = fn (string $expr, string $label, string $join = '') => $base()->selectRaw("$expr as k, $label as label, count(*) as n")->groupByRaw("$expr, $label")->orderByDesc('n')->get();

        return [
            'registry' => ['orphans' => $total, 'approved' => (int) $quality->approved, 'provisional' => (int) $quality->provisional, 'complete' => (int) $quality->complete,
                'guardians' => DB::table('orphan_guardians')->where('status', 'active')->count(),
                'links' => DB::table('orphan_guardianships')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all()],
            'quality' => ['valid_id' => $rate((int) $quality->id_ok), 'dob' => $rate((int) $quality->dob), 'sex' => $rate((int) $quality->sex), 'address' => $rate((int) $quality->address),
                'both_parents' => $rate((int) $quality->parents), 'approved_guardian' => $rate((int) $quality->guardian)],
            'distribution' => [
                'geography' => $group('a.governorate_value_id', "coalesce(gv.name_ar, 'غير محدد')"),
                'class' => $group('o.orphan_class_value_id', "coalesce(cv.name_ar, 'غير مصنف')"),
                'sex' => $group('p.sex', "coalesce(case p.sex when 'male' then 'ذكر' when 'female' then 'أنثى' end, 'غير محدد')"),
                'age' => $this->dashboard->ages(),
            ],
            'sponsorship' => $this->sponsorship($total),
            'campaigns' => $this->campaigns($from, $to),
            'benefits' => $this->benefits($from, $to),
            'reviews' => $this->reviews($from, $to),
            'workload' => $this->dashboard->workload(),
        ];
    }

    private function sponsorship(int $total): array
    {
        $coverage = app(Extensions\AidExtension::class)->coverage();

        return [...$coverage, 'rate' => $total ? round($coverage['orphans'] / $total * 100, 1) : null,
            'committed' => DB::table('sponsorships')->whereIn('status', ['active', 'partial'])->selectRaw('currency, sum(amount) as amount')->groupBy('currency')->pluck('amount', 'currency')->all(),
            'referred_dues' => DB::table('sponsorship_dues')->where('status', 'referred')->count()];
    }

    /** Candidates vs approved vs delivered per campaign, with stock reconciliation. */
    private function campaigns(?string $from, ?string $to): array
    {
        return DB::table('campaigns as c')->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $from)))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereNull('c.starts_on')->orWhere('c.starts_on', '<=', $to)))->orderByDesc('c.id')->limit(50)
            ->get(['c.id', 'c.public_id', 'c.reference_no', 'c.title', 'c.status', 'c.donor_name'])->map(function ($c): array {
                $run = DB::table('campaign_runs')->where('campaign_id', $c->id)->orderByDesc('id')->value('counts');
                $approved = DB::table('campaign_lists')->where('campaign_id', $c->id)->where('status', 'approved')->first();
                $delivered = DB::table('campaign_deliveries as d')->where('d.campaign_id', $c->id)->where('d.kind', 'delivered')
                    ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as x')->whereColumn('x.corrects_id', 'd.id'));
                $stock = DB::table('campaign_stock_issues')->where('campaign_id', $c->id)->selectRaw("coalesce(sum(quantity) filter (where kind = 'issue'), 0) as issued, coalesce(sum(quantity) filter (where kind = 'return'), 0) as returned")->first();

                return ['reference_no' => $c->reference_no, 'public_id' => $c->public_id, 'title' => $c->title, 'status' => $c->status, 'donor' => $c->donor_name,
                    'candidates' => json_decode((string) $run, true) ?: [], 'approved' => $approved ? (int) DB::table('campaign_list_entries')->where('list_id', $approved->id)->where('kind', 'primary')->count() : null,
                    'delivered_units' => (int) (clone $delivered)->distinct()->count('d.unit_key'), 'delivery_transactions' => (int) (clone $delivered)->count(),
                    'delivered_quantity' => (float) (clone $delivered)->sum('d.quantity'), 'issued' => (float) $stock->issued, 'returned' => (float) $stock->returned,
                    'variance' => round((float) $stock->issued - (float) (clone $delivered)->sum('d.quantity') - (float) $stock->returned, 3)];
            })->all();
    }

    private function benefits(?string $from, ?string $to): array
    {
        $rows = DB::table('benefit_records as b')->where('b.kind', 'delivery')->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'))
            ->when($from, fn ($q) => $q->where('b.delivered_on', '>=', $from))->when($to, fn ($q) => $q->where('b.delivered_on', '<=', $to));

        return ['unique_orphans' => (int) (clone $rows)->whereNotNull('b.orphan_id')->distinct()->count('b.orphan_id'), 'unique_households' => (int) (clone $rows)->whereNotNull('b.household_id')->distinct()->count('b.household_id'),
            'transactions' => (int) (clone $rows)->count(), 'units' => (float) (clone $rows)->sum('b.quantity'),
            'by_source' => (clone $rows)->selectRaw('b.source, count(*) as n')->groupBy('b.source')->pluck('n', 'source')->all()];
    }

    private function reviews(?string $from, ?string $to): array
    {
        $q = DB::table('portal_applications')->when($from, fn ($w) => $w->where('submitted_at', '>=', $from))->when($to, fn ($w) => $w->where('submitted_at', '<=', $to.' 23:59:59'));

        return ['submitted' => (clone $q)->count(), 'decided' => (clone $q)->whereIn('status', ['completed', 'rejected'])->count(),
            'avg_days' => ($avg = (clone $q)->whereIn('status', ['completed', 'rejected'])->selectRaw('avg(extract(epoch from (decided_at - submitted_at)) / 86400) as d')->value('d')) !== null ? round((float) $avg, 1) : null,
            'open_over_14_days' => (clone $q)->whereIn('status', ['submitted', 'under_review', 'resubmitted', 'needs_completion'])->where('submitted_at', '<', now()->subDays(14))->count()];
    }

    /** Donor-facing figures: aggregates only (no names or identifiers). */
    public function donor(?int $campaignId, ?int $sponsorId): array
    {
        $campaigns = DB::table('campaigns')->when($campaignId, fn ($q) => $q->where('id', $campaignId))->when($sponsorId, fn ($q) => $q->where('sponsor_id', $sponsorId))->pluck('id');
        $benefits = DB::table('benefit_records as b')->whereIn('b.campaign_id', $campaigns)->where('b.kind', 'delivery')->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'));
        $byArea = DB::table('campaign_deliveries as d')->join('campaign_list_entries as e', 'e.id', '=', 'd.list_entry_id')->leftJoin('master_data_values as g', 'g.id', '=', 'e.governorate_value_id')
            ->whereIn('d.campaign_id', $campaigns)->where('d.kind', 'delivered')->whereNotExists(fn ($q) => $q->from('campaign_deliveries as x')->whereColumn('x.corrects_id', 'd.id'))
            ->selectRaw("coalesce(g.name_ar, 'غير محدد') as area, count(distinct d.unit_key) as beneficiaries, sum(d.quantity) as units")->groupBy('g.name_ar')->get();
        $sponsorships = $sponsorId ? DB::table('sponsorships')->where('sponsor_id', $sponsorId)->whereIn('status', ['active', 'partial'])->count() : null;

        return ['unique_beneficiaries' => (int) (clone $benefits)->selectRaw('count(distinct coalesce(b.orphan_id::text, \'h\' || b.household_id::text)) as n')->value('n'),
            'transactions' => (int) (clone $benefits)->count(), 'units' => (float) (clone $benefits)->sum('b.quantity'), 'by_area' => $byArea, 'active_sponsorships' => $sponsorships,
            'sponsored_orphans' => $sponsorId ? (int) DB::table('sponsorship_beneficiaries as sb')->join('sponsorships as s', 's.id', '=', 'sb.sponsorship_id')->where('s.sponsor_id', $sponsorId)
                ->whereIn('s.status', ['active', 'partial'])->whereNull('sb.ends_on')->whereNotNull('sb.orphan_id')->distinct()->count('sb.orphan_id') : null];
    }
}
