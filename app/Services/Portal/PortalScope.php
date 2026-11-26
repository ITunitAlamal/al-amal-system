<?php

namespace App\Services\Portal;

use App\Models\Guardian;
use Illuminate\Support\Facades\DB;

/**
 * What a beneficiary account may read. The only access path to an official child record is an APPROVED
 * guardianship that is in force today, owned by this account's guardian record. Household membership, a request,
 * or kinship never grants access. Every portal read goes through these methods (missing = 404).
 */
final class PortalScope
{
    /** True when the account itself may use the official areas (approved, active, linked to a person). */
    public function linked(Guardian $account): bool
    {
        return $account->is_active && $account->account_status === 'approved' && $account->person_id !== null;
    }

    /** The account's guardian registry id (orphan_guardians), when linked. */
    public function guardianId(Guardian $account): ?int
    {
        return $this->linked($account) ? DB::table('orphan_guardians')->where('portal_account_id', $account->id)->where('person_id', $account->person_id)->value('id') : null;
    }

    /** @return list<int> orphan ids visible now */
    public function orphanIds(Guardian $account): array
    {
        $guardian = $this->guardianId($account);
        if (! $guardian) {
            return [];
        }
        $today = today()->toDateString();

        return DB::table('orphan_guardianships as g')->join('orphans as o', 'o.id', '=', 'g.orphan_id')->where('g.guardian_id', $guardian)->where('g.status', 'approved')
            ->where(fn ($q) => $q->whereNull('g.starts_on')->orWhere('g.starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('g.ends_on')->orWhere('g.ends_on', '>=', $today))
            // «معتمد مبدئيًا» (imported, not yet verified) never reaches the portal, even with an approved guardianship.
            ->whereNotIn('o.status', ['ended', 'archived', 'provisional'])->pluck('o.id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** A visible child by its public id, or 404. */
    public function orphan(Guardian $account, string $publicId): object
    {
        $orphan = DB::table('orphans')->where('public_id', $publicId)->first();
        abort_unless($orphan && in_array((int) $orphan->id, $this->orphanIds($account), true), 404);

        return $orphan;
    }

    /** Current households of the visible children (social context for display only, never an access scope). @return list<int> */
    public function householdIds(Guardian $account): array
    {
        $ids = $this->orphanIds($account);

        return $ids === [] ? [] : DB::table('household_members as m')->join('orphans as o', 'o.person_id', '=', 'm.person_id')->whereIn('o.id', $ids)->whereNull('m.ends_on')
            ->distinct()->pluck('m.household_id')->map(fn ($id) => (int) $id)->all();
    }

    /** A request owned by this account, or 404. */
    public function application(Guardian $account, string $publicId): object
    {
        return DB::table('portal_applications')->where('public_id', $publicId)->where('source', 'portal')->where('guardian_account_id', $account->id)
            ->where('status', '<>', 'withdrawn')->firstOrFail();
    }
}
