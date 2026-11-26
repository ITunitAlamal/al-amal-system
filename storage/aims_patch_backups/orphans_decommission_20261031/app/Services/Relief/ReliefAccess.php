<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;

/**
 * Campaign-scoped access. Campaigns stay with the Sponsorships & Orphans department: its staff with the campaigns
 * capability prepare them, the head reviews and assigns field work, the executive director decides. Anyone else
 * (a PR officer, a field distributor) acts only through a time-bound membership in a named campaign, and sees
 * campaign metadata and aggregates — at most pseudonymous beneficiary references — never child files.
 */
final class ReliefAccess
{
    public const ROLES = ['coordinator' => 'منسق الحملة', 'outreach' => 'التواصل والإعلام', 'media' => 'المحتوى الإعلامي', 'field_distributor' => 'موزع ميداني', 'reviewer' => 'مراجع'];

    public function __construct(private readonly OrphanAccess $orphans) {}

    public function orphans(): OrphanAccess
    {
        return $this->orphans;
    }

    /** Department staff who prepare campaigns (capability), the head, and the system admin (technical, audited). */
    public function canPrepare(?User $user): bool
    {
        return $this->orphans->allows($user, 'campaigns');
    }

    public function membership(?User $user, int $campaignId, ?string $role = null): ?object
    {
        if (! $user || ! $user->is_active) {
            return null;
        }
        $today = today(config('orphans.timezone'))->toDateString();

        return DB::table('relief_campaign_members')->where('campaign_id', $campaignId)->where('user_id', $user->id)->whereNull('revoked_at')
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->when($role, fn ($q) => $q->where('role', $role))->first();
    }

    public function canView(?User $user, int $campaignId): bool
    {
        return $this->canPrepare($user) || $this->orphans->isExecutive($user) || $this->membership($user, $campaignId) !== null;
    }

    /** Beneficiary identities: department staff with record access, the head and the executive director only. */
    public function canSeeBeneficiaries(?User $user): bool
    {
        return ($this->canPrepare($user) && $this->orphans->allows($user, 'view')) || $this->orphans->isExecutive($user);
    }

    public function canSeePseudonyms(?User $user, int $campaignId): bool
    {
        return $this->canSeeBeneficiaries($user) || (bool) $this->membership($user, $campaignId)?->may_view_pseudonymous;
    }

    public function assertView(?User $user, int $campaignId): void
    {
        abort_unless($this->canView($user, $campaignId), 403, 'لا تملك تكليفًا نافذًا في هذه الحملة.');
    }

    public function assertPrepare(?User $user): void
    {
        abort_unless($this->canPrepare($user), 403, 'إعداد الحملات لموظفي قسم الكفالات المفوضين ورئيس القسم.');
    }
}
