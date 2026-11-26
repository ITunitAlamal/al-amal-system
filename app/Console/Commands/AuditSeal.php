<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditChain;
use App\Services\AuditService;
use Illuminate\Console\Command;

/** Seals new «سجل النظام» rows into the hash chain; with --verify recomputes the whole chain and its anchors. */
final class AuditSeal extends Command
{
    protected $signature = 'audit:seal {--verify : recompute the whole chain and the anchors}';

    protected $description = 'Seal audit_logs rows into the tamper-evidence chain (and verify it)';

    public function handle(AuditChain $chain, AuditService $audit): int
    {
        $sealed = $chain->seal();
        $this->line('sealed '.$sealed['sealed'].' rows'.($sealed['last_seq'] ? ' (last seq '.$sealed['last_seq'].')' : ''));
        if (! $this->option('verify')) {
            return self::SUCCESS;
        }
        $result = $chain->verify();
        $this->line(($result['ok'] ? 'chain OK' : 'chain BROKEN').": {$result['checked']} checked, {$result['unsealed']} not yet sealed");
        foreach ($result['problems'] as $problem) {
            $this->error($problem);
        }
        if (! $result['ok']) {
            $audit->event('audit.chain.broken', ['actor_id' => null, 'outcome' => 'failure', 'severity' => 'critical', 'reason_code' => 'hash_mismatch',
                'metadata' => ['checked' => $result['checked'], 'problems' => array_slice($result['problems'], 0, 5)]]);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
