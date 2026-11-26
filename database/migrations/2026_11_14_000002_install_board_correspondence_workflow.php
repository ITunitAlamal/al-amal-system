<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $definition = DB::table('workflow_definitions')->insertGetId(['code' => 'BOARD_CORRESPONDENCE', 'name' => 'تبليغ ومتابعة قرار مجلس الإدارة', 'subject_type' => 'official_correspondence', 'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => 'BOARD', 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('workflow_versions')->insertGetId(['workflow_definition_id' => $definition, 'version_number' => 1, 'status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $steps = [
            ['record', 'تسجيل قرار المجلس', 'start', 'requester', null],
            ['register', 'تسجيل السكرتاريا وإرساله للمدير', 'task', 'pick', 'secretariat'],
            ['allocate', 'توزيع التنفيذ من المدير التنفيذي', 'task', 'pick', 'executive_director'],
            ['execute', 'تنفيذ القسم ورفع تقرير الإنجاز', 'task', 'pick', 'department_head'],
            ['review', 'مراجعة المدير التنفيذي للتنفيذ', 'decision', 'pick', 'executive_director'],
            ['file', 'توثيق السكرتاريا ورفع نتيجة التنفيذ', 'task', 'pick', 'secretariat'],
            ['authorized_close', 'توثيق الإغلاق لدى أمين سر المجلس', 'task', 'requester', null],
            ['closed', 'مغلق بعد مراجعة التنفيذ', 'end', null, null],
        ];
        $ids = [];
        foreach ($steps as $index => [$code, $name, $type, $assignment, $role]) {
            $ids[$code] = DB::table('workflow_steps')->insertGetId(['workflow_version_id' => $version, 'code' => $code, 'name' => $name, 'sort_order' => ($index + 1) * 10, 'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role, 'assignment_config' => json_encode(['department_scope' => 'any']), 'is_required' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['record', 'register', 'register', 'إرسال للتسجيل'], ['register', 'allocate', 'transmit', 'إرسال إلى المدير التنفيذي'], ['allocate', 'execute', 'assign', 'تكليف قسم بالتنفيذ'], ['execute', 'review', 'report', 'رفع تقرير التنفيذ'], ['review', 'file', 'accept_execution', 'قبول تقرير التنفيذ وإرساله للحفظ'], ['review', 'execute', 'return_execution', 'إعادة لاستكمال التنفيذ'], ['file', 'authorized_close', 'file_report', 'توثيق ورفع لأمين سر المجلس'], ['authorized_close', 'closed', 'close', 'توثيق الإغلاق']] as [$from,$to,$code,$name]) {
            DB::table('workflow_transitions')->insert(['workflow_version_id' => $version, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $code, 'name' => $name, 'requires_comment' => $from !== 'record', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Published institutional workflow versions and official records must be retained.');
    }
};
