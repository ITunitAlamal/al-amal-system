<?php

namespace App\Services\Kpi;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saved KPI readings. Each reading is a new row that is never edited; it keeps the version, the data scope,
 * the filters and the figure exactly as shown. A viewer reads back only readings of the same data scope and
 * filters they are allowed to see now.
 */
final class KpiSnapshots
{
    public function hash(string $scopeKey, array $filters): string
    {
        ksort($filters);

        return hash('sha256', json_encode([$scopeKey, $filters]));
    }

    /**
     * @param  iterable<array<string, mixed>>  $cards  figures from KpiEngine::evaluate
     * @return int readings saved (figures that could not be computed are skipped)
     */
    public function save(iterable $cards, KpiPeriod $period, string $reason, ?int $actorId): int
    {
        $saved = 0;
        DB::transaction(function () use ($cards, $period, $reason, $actorId, &$saved): void {
            foreach ($cards as $card) {
                if ($card['status'] === 'unavailable' || ! $card['version_id'] || ! $card['scope_key']) {
                    continue;
                }
                $stored = array_diff_key($card, array_flip(['urls', 'lineage', 'basis']));
                DB::table('kpi_results')->insert([
                    'version_id' => $card['version_id'], 'scope_key' => mb_substr($card['scope_key'], 0, 255),
                    'scope_hash' => $this->hash($card['scope_key'], $card['filters']),
                    'period_from' => $period->from->toDateString(), 'period_until' => $period->lastDay(),
                    'filters' => json_encode((object) $card['filters']), 'result' => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'reason' => mb_substr($reason, 0, 500), 'created_by' => $actorId, 'created_at' => now(),
                ]);
                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Readings of one definition for the same data scope and filters, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(int $definitionId, string $scopeKey, array $filters, int $limit = 24): Collection
    {
        return DB::table('kpi_results as r')->join('kpi_versions as v', 'v.id', '=', 'r.version_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->where('v.definition_id', $definitionId)->where('r.scope_hash', $this->hash($scopeKey, $filters))
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.created_at', 'r.period_from', 'r.period_until', 'r.result', 'r.reason', 'v.version', 'u.name as saved_by'])
            ->map(function (object $row): array {
                $result = json_decode($row->result, true) ?: [];

                return ['id' => (int) $row->id, 'saved_at' => $row->created_at, 'from' => $row->period_from, 'to' => $row->period_until,
                    'value' => $result['value'] ?? null, 'numerator' => $result['numerator'] ?? null, 'denominator' => $result['denominator'] ?? null,
                    'unit' => $result['unit'] ?? null, 'unit_code' => $result['unit_code'] ?? null, 'currency' => $result['currency'] ?? null,
                    'target' => $result['target'] ?? null, 'status' => $result['status'] ?? null, 'version' => (int) $row->version,
                    'reason' => $row->reason, 'saved_by' => $row->saved_by ?? 'قراءة مجدولة'];
            });
    }
}
