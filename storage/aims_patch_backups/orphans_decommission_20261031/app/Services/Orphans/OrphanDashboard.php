<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\Orphans\Import\RegistryStaging;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OrphanDashboard
{
    public function __construct(private readonly OrphanAccess $access) {}

    /** @return array<string, mixed> */
    public function insights(User $user): array
    {
        $this->access->assert($user, 'view');
        $geography = DB::table('orphan_children as c')
            ->leftJoin('orphan_addresses as a', function (JoinClause $join): void {
                $join->on('a.household_id', '=', 'c.household_id')->where('a.kind', 'current')->whereNull('a.valid_to');
            })
            ->leftJoin('master_data_values as v', 'v.id', '=', 'a.governorate_value_id')
            ->where('c.status', 'active')
            ->selectRaw("coalesce(nullif(trim(v.name_ar), ''), nullif(trim(a.governorate), ''), 'غير محدد') as label, count(*) as count")
            ->groupBy('label')->orderByDesc('count')->orderBy('label')->get()
            ->map(fn (object $row): array => ['label' => $row->label, 'count' => (int) $row->count])->all();

        $batch = Schema::hasTable('orphan_import_batches')
            ? DB::table('orphan_import_batches')->where('status', 'staged')->orderByDesc('id')->first(['id', 'public_id', 'file_name', 'completed_at']) : null;
        $staged = null;
        if ($batch) {
            $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id);
            $staged = [
                'batch' => ['public_id' => $batch->public_id, 'file_name' => $batch->file_name, 'completed_at' => $batch->completed_at],
                'geography' => (clone $rows)->selectRaw('governorate_key, count(*) as count')->groupBy('governorate_key')
                    ->orderByDesc('count')->orderBy('governorate_key')->get()->map(fn (object $row): array => [
                        'label' => $row->governorate_key ?? 'غير محدد', 'count' => (int) $row->count,
                        'href' => route('orphans.records', ['batch' => $batch->public_id, 'governorate' => $row->governorate_key ?? RegistryStaging::MISSING], false),
                    ])->all(),
                'review' => (clone $rows)->selectRaw('review_status, count(*) as count')->groupBy('review_status')->orderByDesc('count')->get()
                    ->map(fn (object $row): array => [
                        'label' => RegistryStaging::REVIEW_STATUSES[$row->review_status] ?? 'غير محدد', 'count' => (int) $row->count,
                        'key' => $row->review_status,
                        'href' => route('orphans.records', ['batch' => $batch->public_id, 'review_status' => $row->review_status], false),
                    ])->all(),
            ];
        }

        $sponsorships = null;
        if (($this->access->allows($user, 'sponsorships') || $this->access->isExecutive($user)) && Schema::hasTable('sponsorships')) {
            $sponsorships = DB::table('sponsorships')->whereNotIn('status', ['superseded', 'cancelled'])
                ->selectRaw('status, count(*) as count')->groupBy('status')->orderByDesc('count')->get()->map(fn (object $row): array => [
                    'label' => Sponsorships::STATUS_LABELS[$row->status] ?? $row->status, 'count' => (int) $row->count,
                    'href' => route('orphans.sponsorships.index', ['status' => $row->status], false),
                ])->all();
        }

        return ['approved_geography' => $geography, 'staged' => $staged, 'sponsorships' => $sponsorships];
    }
}
