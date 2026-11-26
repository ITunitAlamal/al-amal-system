<?php

namespace App\Services\Orphans;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The approved registry's work lists (applications, change requests, visits, emergencies, referrals, documents,
 * follow-ups and the user's own field work). They feed the single «يحتاج إلى إجراء» queue; each list is bounded,
 * so a full list is shown as «50+», never as a total.
 */
final class OrphanWorkQueues
{
    /** Bounded list sizes; a list that reaches its bound is shown as «N+». */
    public const LIMITS = ['applications' => 50, 'changes' => 50, 'visits' => 50, 'documents' => 100];

    public function __construct(private readonly OrphanEmergencies $emergencies, private readonly OrphanFollowups $followups,
        private readonly OrphanReferrals $referrals, private readonly OrphanDocuments $documents) {}

    public function plansAvailable(): bool
    {
        return $this->followups->available();
    }

    /** @return array<string, mixed> */
    public function queues(User $user): array
    {
        $today = now(config('orphans.timezone'))->toDateString();

        return [
            'applications' => DB::table('orphan_applications as a')->join('orphan_households as h', 'h.id', '=', 'a.household_id')->whereIn('a.status', ['submitted', 'under_review', 'needs_info', 'in_decision'])
                ->orderBy('a.id')->limit(self::LIMITS['applications'])->get(['a.id', 'a.reference_no', 'a.status', 'a.source', 'a.created_at', 'h.reference_no as household'])
                ->map(fn ($row) => (array) $row + ['status_label' => OrphanApplications::STATUS_LABELS[$row->status] ?? $row->status]),
            'changes' => DB::table('orphan_change_requests')->whereIn('status', ['submitted', 'in_review', 'returned'])->orderBy('id')->limit(self::LIMITS['changes'])
                ->get(['id', 'reference_no', 'category', 'status', 'created_at'])
                ->map(fn ($row) => (array) $row + ['category_label' => OrphanChanges::CATEGORY_LABELS[$row->category] ?? $row->category, 'status_label' => OrphanChanges::STATUS_LABELS[$row->status] ?? $row->status]),
            'visits' => DB::table('orphan_social_visits as v')->join('orphan_households as h', 'h.id', '=', 'v.household_id')->leftJoin('users as u', 'u.id', '=', 'v.researcher_id')
                ->whereIn('v.status', ['planned', 'submitted', 'returned'])->orderBy('v.scheduled_on')->limit(self::LIMITS['visits'])
                ->get(['v.id', 'v.reference_no', 'v.status', 'v.scheduled_on', 'h.reference_no as household', 'u.name as researcher'])
                ->map(fn ($row) => (array) $row + ['status_label' => OrphanVisits::STATUS_LABELS[$row->status] ?? $row->status]),
            'emergencies' => $this->emergencies->open(),
            'referrals' => $this->referrals->open($user),
            'documents' => $this->documents->dueForRenewal(self::LIMITS['documents']),
            'followups' => $this->followups->open($user),
            'unscheduled' => $this->followups->unscheduled(),
            'mine' => ['followups' => $this->followups->open($user, (int) $user->id),
                'visits' => DB::table('orphan_social_visits as v')->join('orphan_households as h', 'h.id', '=', 'v.household_id')->where('v.researcher_id', $user->id)
                    ->whereIn('v.status', ['planned', 'returned'])->orderBy('v.scheduled_on')->limit(100)
                    ->get(['v.id', 'v.reference_no', 'v.status', 'v.scheduled_on', 'h.reference_no as household'])
                    ->map(fn ($row) => (array) $row + ['status_label' => OrphanVisits::STATUS_LABELS[$row->status] ?? $row->status,
                        'overdue' => $row->status === 'planned' && substr((string) $row->scheduled_on, 0, 10) < $today])],
        ];
    }
}
