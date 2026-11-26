<?php

namespace App\Services\Portal;

use Illuminate\Support\Facades\DB;

/**
 * In-portal notifications for beneficiary accounts. Titles carry a request reference, never a child's name,
 * identity number or health detail; links are portal paths that authorize again when opened.
 * SMS is not used for notifications (only for verification codes when the provider is enabled).
 */
final class PortalNotifier
{
    public const KINDS = ['action' => 'مطلوب منك إجراء', 'decision' => 'قرارات', 'aid' => 'مساعدات', 'info' => 'معلومات'];

    public function notify(?int $accountId, string $kind, string $title, ?string $url = null, ?string $body = null, ?string $dedupe = null): void
    {
        if (! $accountId) {
            return;
        }
        $row = ['guardian_id' => $accountId, 'kind' => $kind, 'title' => mb_substr($title, 0, 200), 'body' => $body ? mb_substr($body, 0, 500) : null,
            'url' => $url && str_starts_with($url, '/guardian') ? $url : null, 'dedupe' => $dedupe, 'created_at' => now()];
        $dedupe ? DB::table('portal_notifications')->insertOrIgnore($row) : DB::table('portal_notifications')->insert($row);
    }

    /** Accounts whose guardian record holds an approved, current guardianship of the child. @return list<int> */
    public function accountsForOrphan(int $orphanId): array
    {
        $today = today()->toDateString();

        return DB::table('orphan_guardianships as g')->join('orphan_guardians as gg', 'gg.id', '=', 'g.guardian_id')->join('guardians as a', 'a.id', '=', 'gg.portal_account_id')
            ->where('g.orphan_id', $orphanId)->where('g.status', 'approved')->where(fn ($q) => $q->whereNull('g.ends_on')->orWhere('g.ends_on', '>=', $today))
            ->where('a.account_status', 'approved')->where('a.is_active', true)->distinct()->pluck('a.id')->map(fn ($id) => (int) $id)->all();
    }

    public function unread(int $accountId): int
    {
        return DB::table('portal_notifications')->where('guardian_id', $accountId)->whereNull('read_at')->count();
    }
}
