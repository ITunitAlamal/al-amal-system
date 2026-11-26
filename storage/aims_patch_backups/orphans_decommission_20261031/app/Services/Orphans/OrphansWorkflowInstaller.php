<?php

namespace App\Services\Orphans;

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installs and removes the published workflow definitions of the orphans, sponsorships and campaigns module.
 * Used by its migrations only, so every definition is created the same way and published after the engine's checks.
 */
final class OrphansWorkflowInstaller
{
    public const SOURCE = 'AIMS orphans, sponsorships and relief campaigns mandate (2026-10-24)';

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps  code, name, order, step type, assignment, role, config
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>  $transitions  from, to, action, name, requires comment
     */
    public static function install(string $code, string $subjectType, string $name, string $description, string $prefix, array $steps, array $transitions, bool $confidential = true): void
    {
        if (DB::table('workflow_definitions')->where('code', $code)->exists()) {
            return;
        }
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => $code, 'subject_type' => $subjectType, 'name' => $name, 'description' => $description, 'is_active' => true,
            'is_user_startable' => false, 'reference_prefix' => $prefix, 'is_confidential' => $confidential, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ids = [];
        foreach ($steps as [$stepCode, $stepName, $order, $stepType, $assignment, $role, $config]) {
            $ids[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $stepName, 'sort_order' => $order, 'step_type' => $stepType,
                'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $transitionName, $comment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $action,
                'name' => $transitionName, 'conditions' => null, 'requires_comment' => $comment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException("تعذر نشر مسار {$code}: ".implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => $code, 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Removes definitions that never ran; refuses when any request used them. */
    public static function uninstall(array $codes): void
    {
        foreach ($codes as $code) {
            $definition = DB::table('workflow_definitions')->where('code', $code)->value('id');
            if (! $definition) {
                continue;
            }
            $versions = DB::table('workflow_versions')->where('workflow_definition_id', $definition)->pluck('id');
            if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
                throw new RuntimeException("توجد معاملات على المسار {$code}؛ لا يُتراجع عن الترحيل.");
            }
            DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
            DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
            DB::table('workflow_versions')->whereIn('id', $versions)->delete();
            DB::table('workflow_definitions')->where('id', $definition)->delete();
        }
    }

    /**
     * Seeds a master data group, its types and the values the approved requirements list explicitly.
     *
     * @param  array<string, array{0: string, 1: list<array{0: string, 1: string, 2?: array<string, mixed>}>}>  $types  type code => [Arabic name, values]
     */
    public static function masterData(string $groupCode, string $groupName, array $types): void
    {
        $now = now();
        $groupId = DB::table('master_data_groups')->where('code', $groupCode)->value('id')
            ?? DB::table('master_data_groups')->insertGetId(['code' => $groupCode, 'name_ar' => $groupName, 'sort_order' => 200, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $order = 10;
        foreach ($types as $typeCode => [$typeName, $values]) {
            $typeId = DB::table('master_data_types')->where('code', $typeCode)->value('id')
                ?? DB::table('master_data_types')->insertGetId(['group_id' => $groupId, 'code' => $typeCode, 'name_ar' => $typeName, 'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $order += 10;
            foreach ($values as $index => $value) {
                DB::table('master_data_values')->insertOrIgnore([
                    'type_id' => $typeId, 'code' => $value[0], 'name_ar' => $value[1], 'sort_order' => ($index + 1) * 10, 'is_active' => true,
                    'metadata' => isset($value[2]) ? json_encode($value[2], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                    'effective_from' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public static function notification(string $event, string $label, string $description): void
    {
        DB::table('notification_settings')->insertOrIgnore(['event_code' => $event, 'label_ar' => $label, 'description' => $description,
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
