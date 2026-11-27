<?php

namespace App\Services\Orphans\Extensions;

use App\Models\User;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;

/** Campaign decisions in the department feed: actionable for the executive director, tracking-only for others. */
final class CampaignExtension
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function items(User $user, bool $all): array
    {
        $executive = $this->access->isExecutive($user);
        if (! $executive && ! $all && ! $this->access->allows($user, 'campaigns')) {
            return [];
        }
        $items = [];
        foreach (DB::table('campaign_lists as l')->join('campaigns as c', 'c.id', '=', 'l.campaign_id')->where('l.status', 'pending_executive')->orderBy('l.submitted_at')
            ->get(['c.public_id', 'c.reference_no', 'c.title', 'l.version', 'l.submitted_at', 'l.base_list_id']) as $l) {
            $items[] = ['source' => $l->base_list_id ? 'تعديل كشف حملة' : 'كشف حملة', 'subject' => $l->reference_no.' — '.$l->title.' (الإصدار '.$l->version.')', 'assignee' => 'المدير التنفيذي', 'due' => null,
                'status' => $executive ? 'بانتظار اعتمادك' : 'بانتظار اعتماد المدير التنفيذي (للمتابعة)', 'url' => '/orphans/campaigns/'.$l->public_id.'?tab=approval', 'urgency' => 3, 'at' => $l->submitted_at,
                'readonly' => ! $executive];
        }
        if (! $executive) {
            // Drafts the preparer referred to the head (ORP-08): actionable for the head, tracking-only for others.
            $head = $this->access->canDecide($user);
            foreach (DB::table('campaign_lists as l')->join('campaigns as c', 'c.id', '=', 'l.campaign_id')->where('l.status', 'draft')->whereNotNull('l.review_requested_at')
                ->whereNotIn('c.status', ['closed', 'cancelled'])->orderBy('l.review_requested_at')->get(['c.public_id', 'c.reference_no', 'c.title', 'l.id', 'l.version', 'l.review_requested_at']) as $l) {
                $items[] = ['source' => 'كشف حملة بانتظار مراجعة رئيس القسم', 'subject' => $l->reference_no.' — '.$l->title.' (الإصدار '.$l->version.')', 'assignee' => 'رئيس القسم', 'due' => null,
                    'status' => $head ? 'راجعه وارفعه للمدير التنفيذي بتوصيتك' : 'بانتظار مراجعة رئيس القسم (للمتابعة)', 'url' => '/orphans/campaigns/'.$l->public_id.'?tab=list&list='.$l->id,
                    'urgency' => 2, 'at' => $l->review_requested_at, 'readonly' => ! $head];
            }
            foreach (DB::table('campaign_duplicate_alerts as a')->join('campaigns as c', 'c.id', '=', 'a.campaign_id')->where('a.status', 'open')->whereIn('c.status', ['list_draft', 'draft'])
                ->selectRaw('c.public_id, c.reference_no, count(*) as n, min(a.created_at) as at')->groupBy('c.public_id', 'c.reference_no')->get() as $d) {
                $items[] = ['source' => 'تنبيهات تكرار', 'subject' => $d->reference_no.' — '.$d->n.' تنبيه', 'assignee' => null, 'due' => null, 'status' => 'مراجعة قبل الرفع', 'url' => '/orphans/campaigns/'.$d->public_id.'?tab=list',
                    'urgency' => 1, 'at' => $d->at];
            }
        }

        return $items;
    }

    /** Count for «مساحتي» of the executive director. */
    public function pendingForExecutive(): int
    {
        return DB::table('campaign_lists')->where('status', 'pending_executive')->count();
    }
}
