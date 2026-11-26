<?php

namespace App\Services\Orphans\Extensions;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\User;
use App\Services\Orphans\Aid;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Plugs sponsorships and confirmed benefits into the dashboard, the files, the previews and the registry filters. */
final class AidExtension
{
    public function __construct(private readonly OrphanAccess $access) {}

    /** «الأيتام المكفولون»: distinct orphans on an in-force individual/multiple line of an active or partial sponsorship. */
    public function coverage(): array
    {
        $today = today()->toDateString();
        $live = fn () => DB::table('sponsorship_beneficiaries as b')->join('sponsorships as s', 's.id', '=', 'b.sponsorship_id')->whereIn('s.status', ['active', 'partial'])
            ->where(fn ($q) => $q->whereNull('s.ends_on')->orWhere('s.ends_on', '>=', $today))->where('b.starts_on', '<=', $today)->whereNull('b.ends_on');

        return ['orphans' => (int) $live()->whereNotNull('b.orphan_id')->distinct()->count('b.orphan_id'), 'households' => (int) $live()->whereNotNull('b.household_id')->distinct()->count('b.household_id'),
            'sponsorships' => (int) DB::table('sponsorships')->whereIn('status', ['active', 'partial'])->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->count()];
    }

    public function orphanSection(Orphan $orphan, User $viewer): ?array
    {
        $households = DB::table('household_members')->where('person_id', $orphan->person_id)->whereNull('ends_on')->pluck('household_id')->all();
        $lines = DB::table('sponsorship_beneficiaries as b')->join('sponsorships as s', 's.id', '=', 'b.sponsorship_id')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')
            ->where(fn ($q) => $q->where('b.orphan_id', $orphan->id)->orWhereIn('b.household_id', $households ?: [0]))->orderByDesc('b.id')
            ->get(['s.reference_no', 's.status', 's.ends_on', 's.amount', 's.currency', 's.coverage', 'p.name', 'b.starts_on', 'b.ends_on as line_end', 'b.household_id']);
        $benefits = Aid::benefits(['orphan_id' => $orphan->id]);
        $householdBenefits = $households ? Aid::benefits(['household_id' => $households], 30) : [];
        $requests = DB::table('assistance_requests')->where('orphan_id', $orphan->id)->orderByDesc('id')->limit(20)->get();

        return ['key' => 'aid', 'tab' => 'aid', 'title' => 'الكفالات والاستفادة', 'empty' => 'لا توجد كفالات أو استفادات مؤكدة.', 'items' => [
            ...$lines->map(fn ($l): array => ['text' => 'كفالة '.$l->reference_no.' — '.$l->name.' · '.(Aid::SPONSORSHIP_STATUS[Aid::effectiveStatus($l)] ?? $l->status).' · '.$l->amount.' '.$l->currency
                .($l->household_id ? ' (كفالة أسرة)' : '').' · منذ '.$l->starts_on.($l->line_end ? ' حتى '.$l->line_end : '')])->all(),
            ...array_map(fn (array $b): array => ['text' => 'استفادة مؤكدة '.$b['delivered_on'].' — '.$b['description'].($b['quantity'] ? ' · '.$b['quantity'].' '.$b['unit'] : '').($b['amount'] ? ' · '.$b['amount'].' '.$b['currency'] : '')
                .($b['recipient_name'] ? ' · المستلم: '.$b['recipient_name'] : '').' · المرجع: '.$b['fulfillment_ref']], $benefits),
            ...array_map(fn (array $b): array => ['text' => 'استفادة على مستوى الأسرة (تُحتسب مرة واحدة للأسرة) '.$b['delivered_on'].' — '.$b['description']], $householdBenefits),
            ...$requests->map(fn ($r): array => ['text' => 'طلب مساعدة '.$r->reference_no.' · '.(Aid::ASSISTANCE_STATUS[$r->status] ?? $r->status).' · '.$r->description])->all(),
        ]];
    }

    public function guardianSection(OrphanGuardian $guardian, User $viewer): ?array
    {
        return null;
    }

    /** Preview summary: confirmed benefits, never double-counting household aid across children. */
    public function summary(string $type, int $id): ?array
    {
        if ($type === 'orphan') {
            $count = DB::table('benefit_records as b')->where('b.kind', 'delivery')->where('b.orphan_id', $id)->whereNotExists(fn ($q) => $q->from('benefit_records as r')->whereColumn('r.reverses_id', 'b.id'))->count();
            $last = DB::table('benefit_records')->where('kind', 'delivery')->where('orphan_id', $id)->max('delivered_on');

            return ['text' => $count ? "{$count} استفادة مؤكدة، آخرها {$last}" : 'لا توجد استفادة مؤكدة.'];
        }
        $orphans = DB::table('orphan_guardianships')->where('guardian_id', $id)->whereIn('status', ['claimed', 'pending', 'approved'])->pluck('orphan_id')->all();
        $person = DB::table('orphan_guardians')->where('id', $id)->value('person_id');
        $households = DB::table('household_members')->where('person_id', $person)->whereNull('ends_on')->pluck('household_id')->all();
        $child = $orphans ? count(Aid::benefits(['orphan_id' => $orphans], 1000)) : 0;
        $family = $households ? count(Aid::benefits(['household_id' => $households], 1000)) : 0;

        return ['text' => "استفادات الأيتام المرتبطين: {$child} · استفادات الأسرة: {$family} (لا تُنسب للوصي شخصيًا)"];
    }

    /** Registry filters: sponsorship coverage and last confirmed benefit. */
    public function apply(Builder $query, array $filters): void
    {
        $today = today()->toDateString();
        if (($filters['sponsorship'] ?? '') !== '') {
            $exists = fn ($q) => $q->from('sponsorship_beneficiaries as sb')->join('sponsorships as s', 's.id', '=', 'sb.sponsorship_id')->whereColumn('sb.orphan_id', 'o.id')
                ->whereIn('s.status', ['active', 'partial'])->whereNull('sb.ends_on')->where(fn ($w) => $w->whereNull('s.ends_on')->orWhere('s.ends_on', '>=', $today));
            $filters['sponsorship'] === 'yes' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }
        if (($filters['benefit'] ?? '') !== '') {
            $delivered = fn (?string $since) => fn ($q) => $q->from('benefit_records as br')->whereColumn('br.orphan_id', 'o.id')->where('br.kind', 'delivery')
                ->whereNotExists(fn ($r) => $r->from('benefit_records as rv')->whereColumn('rv.reverses_id', 'br.id'))->when($since, fn ($w) => $w->where('br.delivered_on', '>=', $since));
            $filters['benefit'] === 'none' ? $query->whereNotExists($delivered(null)) : $query->whereExists($delivered(today()->subDays((int) $filters['benefit'])->toDateString()));
        }
    }

    public function items(User $user, bool $all): array
    {
        if (! $this->access->allows($user, 'sponsorships') && ! $all) {
            return [];
        }
        $items = [];
        foreach (DB::table('sponsorships as s')->join('sponsors as p', 'p.id', '=', 's.sponsor_id')->whereIn('s.status', ['active', 'partial'])->whereNotNull('s.ends_on')
            ->where('s.ends_on', '<=', today()->addDays(30)->toDateString())->orderBy('s.ends_on')->limit(20)->get(['s.public_id', 's.reference_no', 's.ends_on', 's.created_at', 'p.name']) as $s) {
            $items[] = ['source' => 'كفالة قاربت على الانتهاء', 'subject' => $s->reference_no.' — '.$s->name, 'assignee' => null, 'due' => $s->ends_on, 'status' => 'تجديد أو إنهاء',
                'url' => '/orphans/aid/sponsorships/'.$s->public_id, 'urgency' => 1, 'at' => $s->created_at];
        }
        foreach (DB::table('sponsorship_dues as d')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')->where('d.status', 'planned')->whereIn('s.status', ['active', 'partial'])
            ->where('d.due_on', '<', today()->toDateString())->orderBy('d.due_on')->limit(20)->get(['s.public_id', 's.reference_no', 'd.due_on', 'd.created_at']) as $d) {
            $items[] = ['source' => 'استحقاق كفالة لم يُحَل للحسابات', 'subject' => $d->reference_no, 'assignee' => null, 'due' => $d->due_on, 'status' => 'إحالة',
                'url' => '/orphans/aid/sponsorships/'.$d->public_id, 'urgency' => 1, 'at' => $d->created_at];
        }
        if ($all || $this->access->canAssign($user)) {
            foreach (DB::table('assistance_requests')->where('status', 'requested')->orderBy('created_at')->limit(20)->get(['reference_no', 'description', 'created_at']) as $r) {
                $items[] = ['source' => 'طلب مساعدة', 'subject' => $r->reference_no.' — '.\Illuminate\Support\Str::limit($r->description, 60), 'assignee' => null, 'due' => null,
                    'status' => 'بانتظار القرار', 'url' => '/orphans/aid?tab=assistance', 'urgency' => 1, 'at' => $r->created_at];
            }
        }

        return $items;
    }
}
