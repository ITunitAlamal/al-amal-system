<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Digital assignment of workflow steps («إسناد إلى موظف», App\Services\Workflow\StepAssignments). Data only.
 *
 * The unified department (code HR: human resources, general services and maintenance) has one head. A step owned
 * by that head ("department_head_of") is supervised by the department already; this migration adds what the
 * configuration cannot say by itself:
 *
 * - "supervisor_department_id" on the two maintenance steps held by the technician, so the head can take the
 *   request back or move it when the technician is away;
 * - "assignable" on the steps the head normally hands to an employee («جاهز للإسناد» on the dashboards).
 *
 * The keys are merged into assignment_config (a json column) of the published version, and of an open draft so
 * publishing it later does not drop them; every other key is kept. Two notification types are registered.
 * Safe to run twice: a step that already carries the key, whatever its value, is left alone.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000021 (إسناد مراحل القسم إلى موظفيه)';

    /** @var array<string, array<string, string>> workflow code => step code => key to set */
    private const STEPS = [
        'MAINTENANCE' => ['technician' => 'supervisor_department_id', 'execution' => 'supervisor_department_id', 'hr_head' => 'assignable'],
        'SERVICE' => ['internal_execution' => 'assignable'],
        'LEAVE_EMPLOYEE' => ['hr_registration' => 'assignable'],
        'LEAVE_HEAD' => ['hr_registration' => 'assignable'],
        'LEAVE_EXECUTIVE' => ['hr_registration' => 'assignable'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['workflow_assignment_withdrawn', 'سحب مهمة مسندة إليك', 'للموظف عندما يسحب رئيس القسم مهمة أسندها إليه أو ينقلها إلى موظف آخر.'],
        ['workflow_assignment_completed', 'إنجاز مرحلة أسندتها', 'لرئيس القسم المشرف على المرحلة عندما ينجزها الموظف المكلَّف بها.'],
    ];

    public function up(): void
    {
        $hr = (int) DB::table('departments')->where('code', 'HR')->whereNull('deleted_at')->value('id');
        if ($hr <= 0) {
            throw new RuntimeException('قسم الموارد البشرية (HR) مطلوب لتهيئة الإشراف على مراحل المسارات.');
        }
        $now = now();

        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::STEPS as $workflow => $keys) {
            $steps = DB::table('workflow_steps as s')
                ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->whereIn('v.status', ['published', 'draft'])->whereIn('s.code', array_keys($keys))
                ->orderBy('s.id')
                ->get(['s.id', 's.code', 's.assignment_config', 'v.version_number', 'v.status']);

            foreach ($steps as $step) {
                $config = json_decode((string) $step->assignment_config, true);
                $config = is_array($config) ? $config : [];
                $key = $keys[$step->code];
                // A value already stored under the key (by an earlier run or by the administrator) is kept.
                if (array_key_exists($key, $config)) {
                    continue;
                }
                $merged = [...$config, $key => $key === 'supervisor_department_id' ? $hr : true];
                DB::table('workflow_steps')->where('id', $step->id)->update([
                    'assignment_config' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => $now,
                ]);
                DB::table('audit_logs')->insert([
                    'actor_id' => null, 'action' => 'workflow.step.updated', 'subject_type' => 'workflow_steps', 'subject_id' => (string) $step->id,
                    'metadata' => json_encode([
                        'before' => ['assignment_config' => $config],
                        'after' => ['assignment_config' => $merged, 'workflow' => $workflow, 'step' => $step->code, 'version' => $step->version_number, 'version_status' => $step->status],
                        'source' => self::SOURCE,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * The keys only add an option for the department head; removing them changes no request and no record, so the
     * rollback keeps the configuration and the notification types (assignments already made stay readable).
     */
    public function down(): void
    {
        // Nothing to undo.
    }
};
