<?php

namespace App\Services\Portal;

use App\Models\Guardian;
use Illuminate\Support\Facades\DB;

/**
 * «مساعداتي وكفالاتي» — publishable facts only:
 * - «تم التسليم»: confirmed delivery records (benefit_records, kind=delivery, not reversed). Family-level items
 *   appear once for the household, never once per child.
 * - «جاهزة للاستلام»: a primary entry of an APPROVED list version whose campaign the department explicitly
 *   published to the portal, and that has no delivery yet.
 * - Sponsorship: «مكفول» with a start date, without sponsor, amount or accounting data (when the setting allows).
 * Never shown: candidates, scores, reserve entries, unapproved or unpublished lists, nominations, reservations.
 */
final class PortalAid
{
    public function __construct(private readonly PortalScope $scope) {}

    public function summary(Guardian $account): array
    {
        $orphans = $this->scope->orphanIds($account);
        $households = $this->scope->householdIds($account);
        $guardian = $this->scope->guardianId($account);
        if ($orphans === [] && ! $guardian) {
            return ['delivered' => [], 'ready' => [], 'sponsorships' => []];
        }
        $names = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->whereIn('o.id', $orphans ?: [0])->pluck('p.full_name', 'o.id');

        $delivered = DB::table('benefit_records as b')->leftJoin('campaigns as c', 'c.id', '=', 'b.campaign_id')->leftJoin('master_data_values as t', 't.id', '=', 'b.type_value_id')
            ->where('b.kind', 'delivery')->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'))
            ->where(fn ($q) => $q->whereIn('b.orphan_id', $orphans ?: [0])->orWhereIn('b.household_id', $households ?: [0]))
            ->orderByDesc('b.delivered_on')->orderByDesc('b.id')->limit(100)
            ->get(['b.public_id', 'b.orphan_id', 'b.household_id', 'b.source', 'b.description', 'b.quantity', 'b.unit', 'b.amount', 'b.currency', 'b.recipient_name', 'b.delivered_on',
                'b.fulfillment_ref', 'c.title as campaign', 'c.reference_no as campaign_ref', 't.name_ar as type'])
            ->map(fn ($b) => ['public_id' => $b->public_id, 'for' => $b->orphan_id ? ($names[$b->orphan_id] ?? 'أحد الأطفال') : 'الأسرة', 'category' => $b->campaign ?: ($b->type ?: 'مساعدة'),
                'description' => $b->description, 'quantity' => $b->quantity !== null ? rtrim(rtrim((string) $b->quantity, '0'), '.').($b->unit ? ' '.$b->unit : '') : null,
                'amount' => $b->amount !== null ? $b->amount.' '.$b->currency : null, 'recipient' => $b->recipient_name, 'date' => $b->delivered_on,
                'reference' => $b->campaign_ref ?: $b->fulfillment_ref])->all();

        $ready = [];
        $campaigns = DB::table('campaigns')->whereNotNull('portal_published_at')->whereIn('status', ['approved', 'distributing'])->get(['id', 'title', 'reference_no', 'portal_instructions', 'ends_on']);
        foreach ($campaigns as $campaign) {
            $list = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->orderByDesc('version')->first(['id']);
            if (! $list) {
                continue;
            }
            $entries = DB::table('campaign_list_entries as e')->leftJoin('campaign_items as i', 'i.id', '=', 'e.item_id')->where('e.list_id', $list->id)->where('e.kind', 'primary')
                ->where(fn ($q) => $q->whereIn('e.orphan_id', $orphans ?: [0])->orWhereIn('e.household_id', $households ?: [0])
                    ->when($guardian, fn ($w) => $w->orWhere('e.guardian_id', $guardian)->orWhere('e.recipient_guardian_id', $guardian)))
                ->get(['e.id', 'e.orphan_id', 'e.household_id', 'e.quantity', 'i.label', 'i.variant']);
            foreach ($entries as $entry) {
                $done = DB::table('campaign_deliveries')->where('list_entry_id', $entry->id)->where('kind', 'delivered')
                    ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as x')->whereColumn('x.corrects_id', 'campaign_deliveries.id'))->exists();
                if (! $done) {
                    $ready[] = ['campaign' => $campaign->title, 'reference' => $campaign->reference_no, 'for' => $entry->orphan_id ? ($names[$entry->orphan_id] ?? 'أحد الأطفال') : 'الأسرة',
                        'item' => trim(($entry->label ?? '').' '.($entry->variant ?? '')) ?: null, 'instructions' => $campaign->portal_instructions, 'until' => $campaign->ends_on];
                }
            }
        }

        $sponsorships = [];
        if (json_decode((string) DB::table('orphan_settings')->where('key', 'portal_show_sponsorship')->value('value'), true) === true && $orphans !== []) {
            $today = today()->toDateString();
            $sponsorships = DB::table('sponsorship_beneficiaries as b')->join('sponsorships as s', 's.id', '=', 'b.sponsorship_id')->whereIn('b.orphan_id', $orphans)
                ->whereIn('s.status', ['active', 'partial'])->where(fn ($q) => $q->whereNull('b.ends_on')->orWhere('b.ends_on', '>=', $today))
                ->get(['b.orphan_id', 'b.starts_on'])->map(fn ($r) => ['for' => $names[$r->orphan_id] ?? 'أحد الأطفال', 'since' => $r->starts_on])->unique('for')->values()->all();
        }

        return ['delivered' => $delivered, 'ready' => $ready, 'sponsorships' => $sponsorships];
    }
}
