<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decisions of 2026-10-22 (forms review):
 *
 * 1. Kinship list in «ثوابت النظام»: father, mother, spouse, child, brother, sister, grandparent, grandchild,
 *    paternal and maternal uncle/aunt. The system administrator adds, edits and deactivates entries afterwards.
 * 2. An employee's identity document has a type and the holder's nationality. The 9-digit rule applies only
 *    to the Palestinian identity card; a passport or foreign card keeps its own format.
 * 3. The executive director's own leave is approved by a decision of the board of directors. The decision is
 *    recorded in the system by the board secretary (new role), with the decision number and date.
 *
 * Additive only. Backup taken before running:
 * C:\Projects\al-amal-backups\al_amal_db_20261022_135437_before_identity_kinship_board_leave.dump
 */
return new class extends Migration
{
    private const SOURCE = 'قرار صاحب المشروع 2026-10-22 (مراجعة النماذج)';

    /** @var array<string, list<array{0: string, 1: string, 2: string}>> type code => [code, Arabic name, English name] */
    private const VALUES = [
        'kinship_types' => [
            ['father', 'أب', 'Father'],
            ['mother', 'أم', 'Mother'],
            ['spouse', 'زوج/ة', 'Spouse'],
            ['child', 'ابن/ة', 'Son / Daughter'],
            ['brother', 'أخ', 'Brother'],
            ['sister', 'أخت', 'Sister'],
            ['grandparent', 'جد/ة', 'Grandparent'],
            ['grandchild', 'حفيد/ة', 'Grandchild'],
            ['paternal_uncle_aunt', 'عم/ة', 'Paternal uncle / aunt'],
            ['maternal_uncle_aunt', 'خال/ة', 'Maternal uncle / aunt'],
        ],
        'identity_document_types' => [
            ['palestinian_id', 'الهوية الفلسطينية', 'Palestinian ID card'],
            ['passport', 'جواز سفر', 'Passport'],
            ['foreign_id', 'هوية أجنبية', 'Foreign ID card'],
        ],
        'nationalities' => [
            ['palestinian', 'فلسطيني', 'Palestinian'],
        ],
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('identity_document_type_value_id')->nullable()->after('national_id')
                ->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('nationality_value_id')->nullable()->after('identity_document_type_value_id')
                ->constrained('master_data_values')->restrictOnDelete();
        });

        foreach (self::VALUES as $typeCode => $values) {
            $typeId = DB::table('master_data_types')->where('code', $typeCode)->value('id');
            if (! $typeId) {
                throw new RuntimeException("Master data type not found: {$typeCode}");
            }
            $added = [];
            foreach ($values as $index => [$code, $nameAr, $nameEn]) {
                if (DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('master_data_values')->insert([
                    'type_id' => $typeId, 'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                    'sort_order' => ($index + 1) * 10, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $added[] = $nameAr;
            }
            if ($added) {
                $this->audit('master_data.value.created', 'master_data_types', (int) $typeId, ['type' => $typeCode, 'values' => $added]);
            }
        }

        if (! DB::table('roles')->where('code', 'board_secretary')->exists()) {
            $roleId = DB::table('roles')->insertGetId(['code' => 'board_secretary', 'name' => 'أمين سر مجلس الإدارة', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('role.created', 'roles', (int) $roleId, ['code' => 'board_secretary', 'name' => 'أمين سر مجلس الإدارة']);
        }

        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->string('executive_workflow_code', 100)->nullable()->after('head_workflow_code');
        });

        $this->createLeaveWorkflow();
    }

    /**
     * Rollback for a failed rollout only. Refused once an employee has a document type or nationality,
     * or once an executive leave request runs on the new path.
     */
    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('identity_document_type_value_id')->orWhereNotNull('nationality_value_id')->exists()) {
            throw new RuntimeException('توجد ملفات موظفين تحمل نوع وثيقة أو جنسية؛ لا يمكن التراجع دون ترحيل معتمد.');
        }
        $definitionId = DB::table('workflow_definitions')->where('code', 'LEAVE_EXECUTIVE')->value('id');
        $versionIds = $definitionId ? DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->pluck('id') : collect();
        if ($versionIds->isNotEmpty() && DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->exists()) {
            throw new RuntimeException('توجد طلبات على مسار إجازة المدير التنفيذي؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
        if ($definitionId) {
            DB::table('workflow_definitions')->where('id', $definitionId)->delete();
        }

        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->dropColumn('executive_workflow_code');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nationality_value_id');
            $table->dropConstrainedForeignId('identity_document_type_value_id');
        });
        // The constants and the role stay: the administrator may already use or edit them.
    }

    /**
     * Version 1 as a draft; it is published from «مسارات العمل» (validated and audited) before use.
     */
    private function createLeaveWorkflow(): void
    {
        if (DB::table('workflow_definitions')->where('code', 'LEAVE_EXECUTIVE')->exists()) {
            return;
        }
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => 'LEAVE_EXECUTIVE', 'subject_type' => 'employee_leave_request', 'name' => 'طلب إجازة المدير التنفيذي',
            'description' => 'إجازة المدير التنفيذي تُعتمد بقرار من مجلس الإدارة، يسجّله أمين سر المجلس مع رقم القرار وتاريخه، ثم تُشعَر الموارد البشرية.',
            'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => 'LV', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft',
            'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $steps = [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['board_decision', 'قرار مجلس الإدارة', 20, 'decision', 'role', 'board_secretary', ['department_scope' => 'any']],
            ['approved', 'اعتماد الإجازة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ];
        $stepIds = [];
        foreach ($steps as [$code, $name, $order, $type, $assignment, $role, $config]) {
            $stepIds[$code] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $code, 'name' => $name, 'sort_order' => $order,
                'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config), 'sla_hours' => null,
                'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // The board's decision is recorded with its number and date, so approving also requires a note.
        $transitions = [
            ['requester', 'board_decision', 'submit', 'إرسال إلى مجلس الإدارة', false],
            ['board_decision', 'approved', 'approve', 'اعتماد بقرار مجلس الإدارة', true],
            ['board_decision', 'requester', 'return', 'إعادة للمدير التنفيذي للتعديل', true],
            ['board_decision', 'rejected', 'reject', 'رفض بقرار مجلس الإدارة', true],
        ];
        foreach ($transitions as [$from, $to, $action, $label, $requiresComment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to],
                'action_code' => $action, 'name' => $label, 'conditions' => null, 'requires_comment' => $requiresComment,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->audit('workflow.definition.created', 'workflow_definitions', (int) $definitionId, ['code' => 'LEAVE_EXECUTIVE', 'name' => 'طلب إجازة المدير التنفيذي', 'version' => 'draft']);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => $id,
            'metadata' => json_encode(['before' => null, 'after' => $after, 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
