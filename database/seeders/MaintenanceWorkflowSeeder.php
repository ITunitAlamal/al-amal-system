<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MaintenanceWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            $now = now();

            // تعريف سير عمل الصيانة
            $definitionId = DB::table('workflow_definitions')
                ->where('code', 'MAINTENANCE')
                ->value('id');

            if (! $definitionId) {
                $definitionId = DB::table('workflow_definitions')
                    ->insertGetId([
                        'code' => 'MAINTENANCE',
                        'name' => 'طلبات الصيانة',
                        'description' => 'مسار طلبات الصيانة وفق التسلسل الإداري المعتمد.',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // منع تكرار الإصدار الأول
            $existingVersion = DB::table('workflow_versions')
                ->where('workflow_definition_id', $definitionId)
                ->where('version_number', 1)
                ->first();

            if ($existingVersion) {
                $this->command?->info(
                    'Maintenance workflow v1 already exists. Skipped.'
                );

                return;
            }

            // إنشاء الإصدار الأول كمسودة
            $versionId = DB::table('workflow_versions')
                ->insertGetId([
                    'workflow_definition_id' => $definitionId,
                    'version_number' => 1,
                    'status' => 'draft',
                    'created_by' => null,
                    'published_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            // مراحل سير العمل
            $steps = [
                ['requester', 'إنشاء وإرسال الطلب', 'start', 'requester'],
                ['department_head', 'مراجعة رئيس قسم مقدم الطلب', 'task', 'department_head'],
                ['executive_director', 'التوجيه الأولي للمدير التنفيذي', 'task', 'executive_director'],
                ['hr_head', 'تكليف الفني من الموارد البشرية', 'task', 'role'],
                ['technician', 'الفحص الفني وتحديد اللوازم', 'task', 'user'],
                ['accounting', 'المراجعة المالية', 'task', 'role'],
                ['stores', 'فحص توفر المواد', 'decision', 'user'],
                ['procurement', 'تقدير تكلفة المشتريات', 'task', 'user'],
                ['financial_approval', 'الاعتماد المالي للمدير التنفيذي', 'decision', 'executive_director'],
                ['higher_approval', 'اعتماد الجهة الأعلى صلاحية', 'task', 'role'],
                ['purchase', 'تنفيذ الشراء واستلام المواد', 'task', 'user'],
                ['materials', 'تجهيز وصرف اللوازم', 'task', 'user'],
                ['execution', 'تنفيذ أعمال الصيانة', 'task', 'user'],
                ['verification', 'التحقق من إنجاز الصيانة', 'task', 'role'],
                ['completed', 'إغلاق طلب الصيانة', 'end', null],
                ['rejected', 'رفض الطلب', 'end', null],
            ];

            $stepIds = [];

            foreach ($steps as $index => $step) {
                $stepIds[$step[0]] = DB::table('workflow_steps')
                    ->insertGetId([
                        'workflow_version_id' => $versionId,
                        'code' => $step[0],
                        'name' => $step[1],
                        'sort_order' => $index + 1,
                        'step_type' => $step[2],
                        'assignment_type' => $step[3],
                        'assigned_role_code' => $step[0] === 'hr_head'
                                ? 'department_head'
                                : null,
                        'is_required' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // الانتقالات بين المراحل
            $transitions = [
                ['requester', 'department_head', 'submit', 'إرسال إلى رئيس القسم', null, false],
                ['department_head', 'executive_director', 'forward', 'رفع إلى المدير التنفيذي', null, false],
                ['department_head', 'requester', 'return', 'إرجاع للموظف', null, true],
                ['department_head', 'rejected', 'reject', 'رفض الطلب', null, true],
                ['executive_director', 'hr_head', 'assign', 'توجيه إلى الموارد البشرية', null, false],
                ['executive_director', 'department_head', 'return', 'إرجاع لرئيس القسم', null, true],
                ['executive_director', 'rejected', 'reject', 'رفض من المدير التنفيذي', null, true],
                ['hr_head', 'technician', 'assign', 'تكليف الفني', null, false],
                ['technician', 'hr_head', 'return', 'إرجاع التقييم للاستكمال', null, true],
                ['technician', 'accounting', 'submit_assessment', 'إرسال التقييم إلى الحسابات', null, false],
                ['accounting', 'technician', 'return', 'إرجاع التقييم للفني', null, true],
                ['accounting', 'stores', 'check_stock', 'فحص المخزون', null, false],
                ['stores', 'materials', 'available', 'اللوازم متوفرة', ['stock_status' => 'available'], false],
                ['stores', 'procurement', 'unavailable', 'اللوازم غير متوفرة أو ناقصة', ['stock_status' => ['unavailable', 'partial']], false],
                ['procurement', 'financial_approval', 'request_approval', 'إرسال التكلفة للاعتماد', null, false],
                ['financial_approval', 'purchase', 'approve', 'اعتماد شراء ضمن الصلاحية', ['within_executive_limit' => true], false],
                ['financial_approval', 'higher_approval', 'escalate', 'تصعيد للجهة الأعلى', ['within_executive_limit' => false], true],
                ['financial_approval', 'procurement', 'return', 'إرجاع للمشتريات', null, true],
                ['higher_approval', 'purchase', 'approve', 'اعتماد الجهة الأعلى', null, false],
                ['higher_approval', 'procurement', 'return', 'إرجاع للمشتريات', null, true],
                ['purchase', 'materials', 'received', 'استلام المواد', null, false],
                ['materials', 'execution', 'issue', 'صرف المواد للفني', null, false],
                ['execution', 'verification', 'finish', 'إتمام الصيانة', null, false],
                ['verification', 'completed', 'complete', 'إغلاق الطلب', null, false],
                ['verification', 'execution', 'return', 'إرجاع لاستكمال الصيانة', null, true],
            ];

            foreach ($transitions as $transition) {
                [
                    $from,
                    $to,
                    $action,
                    $name,
                    $conditions,
                    $requiresComment
                ] = $transition;

                if (! isset($stepIds[$from], $stepIds[$to])) {
                    throw new RuntimeException(
                        "Undefined workflow step: {$from} -> {$to}"
                    );
                }

                DB::table('workflow_transitions')->insert([
                    'workflow_version_id' => $versionId,
                    'from_step_id' => $stepIds[$from],
                    'to_step_id' => $stepIds[$to],
                    'action_code' => $action,
                    'name' => $name,
                    'conditions' => $conditions === null
                        ? null
                        : json_encode(
                            $conditions,
                            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                        ),
                    'requires_comment' => $requiresComment,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->command?->info(
                'Maintenance workflow v1 created as DRAFT.'
            );
        });
    }
}
