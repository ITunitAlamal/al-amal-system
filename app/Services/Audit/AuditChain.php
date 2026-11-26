<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tamper evidence for audit_logs: rows are sealed in a hash chain in the order they are sealed (seal_seq), and the
 * last hash of every run is kept in audit_seals and appended to storage/app/private/audit-anchors (copy those files
 * off the server to make the anchors independent of the database). A changed, removed or inserted row breaks the
 * chain on verification. This detects tampering; it does not prevent a database owner from it.
 */
final class AuditChain
{
    private const LOCK = [42019, 1];

    private const BATCH = 1000;

    /** @return array{sealed: int, last_seq: int|null} */
    public function seal(int $maxBatches = 20): array
    {
        if (! DB::selectOne('SELECT pg_try_advisory_lock(?, ?) AS ok', self::LOCK)->ok) {
            return ['sealed' => 0, 'last_seq' => null];
        }
        $sealed = 0;
        $lastSeq = null;
        try {
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $done = DB::transaction(function () use (&$lastSeq): array|int {
                    $tip = DB::selectOne('SELECT seal_seq, row_hash FROM audit_logs WHERE seal_seq IS NOT NULL ORDER BY seal_seq DESC LIMIT 1');
                    $seq = (int) ($tip->seal_seq ?? 0);
                    $prev = $tip->row_hash ?? str_repeat('0', 64);
                    $rows = DB::select(self::SELECT.' WHERE row_hash IS NULL ORDER BY id LIMIT '.self::BATCH);
                    if ($rows === []) {
                        return 0;
                    }
                    $from = $seq + 1;
                    foreach ($rows as $row) {
                        $seq++;
                        $hash = self::hash($seq, $row, $prev);
                        DB::update('UPDATE audit_logs SET seal_seq = ?, prev_hash = ?, row_hash = ? WHERE id = ? AND row_hash IS NULL', [$seq, $prev, $hash, $row->id]);
                        $prev = $hash;
                    }
                    DB::table('audit_seals')->insert(['from_seq' => $from, 'to_seq' => $seq, 'rows' => count($rows), 'last_hash' => $prev, 'sealed_at' => now()]);
                    $lastSeq = $seq;

                    return ['rows' => count($rows), 'line' => now()->toIso8601String()." seq {$from}-{$seq} {$prev}"];
                });
                if ($done === 0) {
                    break;
                }
                // The off-database anchor is written only once the seal is committed.
                Storage::disk('local')->append('audit-anchors/'.now()->format('Y-m').'.log', $done['line']);
                $sealed += $done['rows'];
                if ($done['rows'] < self::BATCH) {
                    break;
                }
            }
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?, ?)', self::LOCK);
        }

        return ['sealed' => $sealed, 'last_seq' => $lastSeq];
    }

    /**
     * Recomputes the whole chain and checks every anchor. Result is cached for the «سجل النظام» header.
     *
     * @return array{ok: bool, checked: int, unsealed: int, problems: list<string>, at: string}
     */
    public function verify(): array
    {
        $problems = [];
        $checked = 0;
        $expected = 1;
        $prev = str_repeat('0', 64);
        $hashes = [];
        $lastId = 0;
        do {
            $rows = DB::select('SELECT '.self::COLUMNS.', seal_seq, prev_hash, row_hash FROM audit_logs WHERE seal_seq IS NOT NULL AND seal_seq >= ? ORDER BY seal_seq LIMIT 2000', [$expected]);
            foreach ($rows as $row) {
                if ((int) $row->seal_seq !== $expected) {
                    $problems[] = "فجوة في التسلسل عند {$expected} (وُجد {$row->seal_seq})";
                    $expected = (int) $row->seal_seq;
                }
                if ($row->prev_hash !== $prev) {
                    $problems[] = "انقطاع السلسلة عند {$row->seal_seq}";
                }
                if (self::hash((int) $row->seal_seq, $row, $row->prev_hash) !== $row->row_hash) {
                    $problems[] = "تغيّر محتوى الحدث {$row->seal_seq} (#{$row->id})";
                }
                $hashes[(int) $row->seal_seq] = $row->row_hash;
                $prev = $row->row_hash;
                $expected++;
                $checked++;
                $lastId = (int) $row->seal_seq;
            }
            if (count($problems) > 20) {
                break;
            }
        } while (count($rows) === 2000);
        foreach (DB::table('audit_seals')->orderBy('id')->get() as $seal) {
            if (($hashes[(int) $seal->to_seq] ?? null) !== $seal->last_hash) {
                $problems[] = "مرساة الختم #{$seal->id} لا تطابق الحدث {$seal->to_seq}";
            }
        }
        $result = ['ok' => $problems === [], 'checked' => $checked, 'unsealed' => DB::table('audit_logs')->whereNull('row_hash')->count(),
            'problems' => array_slice($problems, 0, 20), 'at' => now()->toIso8601String(), 'last_seq' => $lastId];
        Cache::forever('audit.chain.last_verification', $result);

        return $result;
    }

    private const COLUMNS = 'id, event_id::text AS event_id, occurred_at::text AS occurred_at, actor_id, actor_guardian_id, action, subject_type, subject_id, outcome, metadata::text AS metadata, host(ip_address) AS ip';

    private const SELECT = 'SELECT '.self::COLUMNS.' FROM audit_logs';

    private static function hash(int $seq, object $row, string $prev): string
    {
        return hash('sha256', implode("\x1F", [$seq, $row->event_id, $row->occurred_at, $row->actor_id, $row->actor_guardian_id, $row->action,
            $row->subject_type, $row->subject_id, $row->outcome, $row->metadata, $row->ip, $prev]));
    }
}
