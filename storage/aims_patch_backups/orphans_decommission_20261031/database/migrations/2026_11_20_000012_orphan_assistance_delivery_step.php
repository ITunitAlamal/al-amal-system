<?php

use App\Services\Orphans\OrphansWorkflowInstaller as Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The delivery step of ORPHAN_ASSISTANCE goes back to the requester, whom the engine excludes from later steps unless
 * the step allows it. Corrects version 1 in place only while no request has used it; otherwise a new version is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setDeliveryConfig(['step_code' => 'requester', 'requester_may_act' => true]);
    }

    public function down(): void
    {
        $this->setDeliveryConfig(['step_code' => 'requester']);
    }

    /** @param array<string, mixed> $config */
    private function setDeliveryConfig(array $config): void
    {
        $versionIds = DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', 'ORPHAN_ASSISTANCE')->pluck('v.id');
        if ($versionIds->isEmpty()) {
            return;
        }
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->exists()) {
            throw new RuntimeException('توجد طلبات مساعدة على المسار؛ يُصحح بإصدار جديد من «مسارات العمل».');
        }
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)->where('code', 'delivery')
            ->update(['assignment_config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.corrected', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionIds->first(),
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => 'ORPHAN_ASSISTANCE', 'step' => 'delivery', 'config' => $config], 'source' => Installer::SOURCE],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
