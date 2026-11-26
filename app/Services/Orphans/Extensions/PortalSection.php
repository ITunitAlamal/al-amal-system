<?php

namespace App\Services\Orphans\Extensions;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\User;
use App\Services\Orphans\PortalApplications;
use Illuminate\Support\Facades\DB;

/** Portal applications shown in the guardian file («الطلبات وسجل التغييرات»). */
final class PortalSection
{
    public function orphanSection(Orphan $orphan, User $viewer): ?array
    {
        return null;
    }

    public function guardianSection(OrphanGuardian $guardian, User $viewer): ?array
    {
        $rows = DB::table('portal_applications')->where('matched_guardian_id', $guardian->id)
            ->when($guardian->portal_account_id, fn ($q) => $q->orWhere('guardian_account_id', $guardian->portal_account_id))->orderByDesc('id')->limit(20)->get();

        return ['key' => 'portal', 'tab' => 'requests', 'title' => 'طلبات البوابة', 'empty' => 'لا توجد طلبات بوابة.',
            'items' => $rows->map(fn ($a): array => ['text' => $a->reference_no.' · '.(PortalApplications::STATUS[$a->status] ?? $a->status).' · '.substr((string) $a->submitted_at, 0, 10),
                'url' => '/orphans/reviews/'.$a->public_id])->all()];
    }
}
