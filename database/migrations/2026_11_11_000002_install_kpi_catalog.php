<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installs the KPI catalog: unit and period constants, the kpi.view permission, two menu links and the
 * initial definitions. Targets and thresholds stay empty until the owner approves them. Definitions whose
 * module or data does not exist yet are registered inactive, with the reason.
 */
return new class extends Migration
{
    private const NOTE = 'تثبيت كتالوج مؤشرات الأداء (ترحيل 2026_11_11_000002).';

    private const UNITS = ['count' => 'عدد', 'percent' => '%', 'hours' => 'ساعة', 'days' => 'يوم', 'money' => 'مبلغ'];

    private const PERIODS = ['monthly' => ['شهري', 1], 'quarterly' => ['ربع سنوي', 3], 'yearly' => ['سنوي', 12]];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $groupId = $this->ensure('master_data_groups', ['code' => 'kpi'], ['name_ar' => 'ثوابت مؤشرات الأداء', 'is_active' => true, 'sort_order' => 90]);
            $unitType = $this->ensure('master_data_types', ['code' => 'kpi_units'], ['group_id' => $groupId, 'name_ar' => 'وحدات قياس المؤشرات', 'is_active' => true, 'sort_order' => 10]);
            $periodType = $this->ensure('master_data_types', ['code' => 'kpi_periods'], ['group_id' => $groupId, 'name_ar' => 'دورية قياس المؤشرات', 'is_active' => true, 'sort_order' => 20]);
            $units = [];
            $order = 10;
            foreach (self::UNITS as $code => $label) {
                $units[$code] = $this->ensure('master_data_values', ['type_id' => $unitType, 'code' => $code], ['name_ar' => $label, 'is_active' => true, 'sort_order' => $order += 10]);
            }
            $periods = [];
            foreach (self::PERIODS as $code => [$label, $months]) {
                $periods[$code] = $this->ensure('master_data_values', ['type_id' => $periodType, 'code' => $code], ['name_ar' => $label, 'is_active' => true, 'sort_order' => $months, 'metadata' => json_encode(['months' => $months])]);
            }
            $this->ensure('permissions', ['code' => 'kpi.view'], ['name' => 'عرض مؤشرات الأداء ضمن أقسام الدور']);

            $settings = DB::table('navigation_items')->where('code', 'system_settings')->value('id');
            $this->ensure('navigation_items', ['code' => 'kpi_dashboard'], ['label_ar' => 'مؤشرات الأداء', 'icon' => 'chart', 'route_name' => 'kpi.index', 'access_rule' => 'kpi_view', 'is_active' => true, 'sort_order' => 27]);
            $this->ensure('navigation_items', ['code' => 'kpi_definitions'], ['label_ar' => 'إدارة مؤشرات الأداء', 'icon' => 'chart', 'parent_id' => $settings, 'route_name' => 'admin.kpi.index', 'access_rule' => 'system_admin', 'is_active' => true, 'sort_order' => 95]);

            $departments = DB::table('departments')->whereNull('deleted_at')->pluck('id', 'code');
            $installed = [];
            foreach ($this->catalog() as $order => $row) {
                if (DB::table('kpi_definitions')->where('code', $row['code'])->exists()) {
                    continue;
                }
                $owner = isset($row['owner']) ? ($departments[$row['owner']] ?? null) : null;
                $viewers = array_values(array_filter(array_map(fn (string $code) => $departments[$code] ?? null, $row['viewers'] ?? [])));
                $available = $row['source'] !== 'unavailable' && ($row['audience'] !== 'owner' || $owner !== null);
                $id = DB::table('kpi_definitions')->insertGetId([
                    'code' => $row['code'], 'domain' => $row['domain'], 'is_active' => $available,
                    'sort_order' => ($order + 1) * 10, 'owner_department_id' => $owner, 'audience' => $row['audience'],
                    'viewer_department_ids' => json_encode($viewers), 'visible_role_codes' => json_encode([]),
                    'on_executive_home' => in_array('executive', $row['home'] ?? [], true) && $available,
                    'on_department_home' => in_array('department', $row['home'] ?? [], true) && $available,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('kpi_versions')->insert([
                    'definition_id' => $id, 'version' => 1, 'effective_from' => '2000-01-01',
                    'change_reason' => 'الإصدار الأول عند تثبيت الكتالوج؛ ينطبق على الفترات السابقة لعدم وجود تعريف قبله.',
                    'configuration' => json_encode([
                        'title' => $row['title'], 'title_en' => null, 'description' => $row['description'] ?? '',
                        'source' => $row['source'], 'formula' => $row['formula'] ?? 'count',
                        'numerator' => $row['numerator'] ?? null, 'denominator' => $row['denominator'] ?? null,
                        'unit_value_id' => $units[$row['unit'] ?? 'count'], 'period_value_id' => $periods['monthly'],
                        'polarity' => $row['polarity'] ?? 'higher', 'target' => null, 'warning_threshold' => null, 'critical_threshold' => null,
                        'filters' => (object) ($row['filters'] ?? []), 'params' => (object) ($row['params'] ?? []),
                        'unavailable_reason' => $row['reason'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
                $installed[] = $row['code'];
            }

            DB::table('audit_logs')->insert([
                'actor_id' => null, 'action' => 'kpi.catalog.installed', 'subject_type' => 'kpi_definitions', 'subject_id' => null,
                'metadata' => json_encode(['before' => null, 'after' => [
                    'definitions' => $installed, 'units' => array_keys(self::UNITS), 'periods' => array_keys(self::PERIODS),
                    'permission' => 'kpi.view', 'menu' => ['kpi_dashboard', 'kpi_definitions'],
                    'targets' => 'غير محددة؛ تُعتمد من صاحب الصلاحية', 'source' => self::NOTE,
                ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (DB::table('kpi_results')->exists()) {
            throw new RuntimeException('توجد قراءات مؤشرات محفوظة؛ لا يُلغى التثبيت. عطّل المؤشرات من الإدارة بدل التراجع.');
        }
        DB::transaction(function (): void {
            $codes = array_column($this->catalog(), 'code');
            $ids = DB::table('kpi_definitions')->whereIn('code', $codes)->pluck('id');
            DB::table('kpi_versions')->whereIn('definition_id', $ids)->delete();
            DB::table('kpi_definitions')->whereIn('id', $ids)->delete();
            DB::table('navigation_items')->whereIn('code', ['kpi_dashboard', 'kpi_definitions'])->delete();
            $permission = DB::table('permissions')->where('code', 'kpi.view')->value('id');
            if ($permission && ! DB::table('permission_role')->where('permission_id', $permission)->exists()) {
                DB::table('permissions')->where('id', $permission)->delete();
            }
        });
    }

    private function ensure(string $table, array $key, array $values): int
    {
        $id = DB::table($table)->where($key)->value('id');

        return $id ? (int) $id : (int) DB::table($table)->insertGetId([...$key, ...$values, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $orphans = 'وحدة الأيتام والكفالات خارج نطاق هذه المرحلة بقرار صاحب المشروع، وتسجيل الأيتام متوقف حتى ربط سجل الأوصياء؛ لا يُحسب رقم.';
        $shelter = 'وحدة الرعاية الإيوائية غير منفذة وخارج نطاق هذه المرحلة؛ لا يُحسب رقم.';
        $media = 'قسم العلاقات العامة والإعلام معتمد ولم يُنفذ بعد؛ لا توجد سجلات منشورات أو تغطيات أو فعاليات.';
        $secretariat = 'وحدة السكرتاريا خارج نطاق هذه المرحلة؛ لا يوجد سجل مراسلات أو محاضر أو إحالات.';
        $unavailable = fn (string $code, string $domain, ?string $owner, string $title, string $reason, string $unit = 'percent'): array => ['code' => $code, 'domain' => $domain, 'owner' => $owner, 'audience' => $owner ? 'owner' : 'executive', 'title' => $title, 'source' => 'unavailable', 'unit' => $unit, 'reason' => $reason];

        return [
            ['code' => 'EXEC_DUE_ON_TIME', 'domain' => 'institution', 'audience' => 'shared', 'home' => ['executive', 'department'],
                'title' => 'المهام المستحقة المنجزة في موعدها', 'source' => 'workflow_tasks', 'formula' => 'percentage', 'numerator' => 'due_on_time', 'denominator' => 'due', 'unit' => 'percent',
                'description' => 'من مهام المعاملات التي حلّت مهلتها خلال الفترة، نسبة ما أُنجز قبل انتهاء المهلة. المهلة = وقت دخول المرحلة + مهلة المرحلة في نسخة المسار المنشورة.'],
            ['code' => 'EXEC_SLA_COMPLIANCE', 'domain' => 'institution', 'audience' => 'shared', 'home' => ['executive'],
                'title' => 'الالتزام بمهلة المراحل', 'source' => 'workflow_tasks', 'formula' => 'percentage', 'numerator' => 'handled_on_time', 'denominator' => 'handled_with_deadline', 'unit' => 'percent',
                'description' => 'من المهام المنجزة خلال الفترة ولها مهلة معتمدة، نسبة ما أُنجز ضمن المهلة.'],
            ['code' => 'TASKS_OPEN_OVERDUE', 'domain' => 'institution', 'audience' => 'shared', 'home' => ['executive', 'department'], 'polarity' => 'lower',
                'title' => 'مهام مفتوحة تجاوزت مهلتها', 'source' => 'workflow_tasks', 'formula' => 'count', 'numerator' => 'open_overdue', 'unit' => 'count',
                'description' => 'مهام قائمة الآن تجاوزت مهلتها، منسوبة إلى قسم من يتولاها حاليًا لا إلى قسم مقدم الطلب.'],
            ['code' => 'EXEC_PENDING_DIRECTOR', 'domain' => 'institution', 'audience' => 'executive', 'home' => ['executive'], 'polarity' => 'lower',
                'title' => 'معاملات بانتظار قرار المدير التنفيذي', 'source' => 'workflow_requests', 'formula' => 'count', 'numerator' => 'open', 'unit' => 'count', 'filters' => ['assignee_role' => 'executive_director'],
                'description' => 'معاملات مفتوحة الآن ومسندة إلى صاحب دور المدير التنفيذي. المعاملات المحولة إلى مفوَّض لا تُحسب هنا.'],
            ['code' => 'EXEC_PENDING_DIRECTOR_WAIT', 'domain' => 'institution', 'audience' => 'executive', 'home' => ['executive'], 'polarity' => 'lower',
                'title' => 'وسيط مدة انتظار قرار المدير التنفيذي', 'source' => 'workflow_requests', 'formula' => 'median', 'numerator' => 'waiting_hours', 'unit' => 'hours', 'filters' => ['assignee_role' => 'executive_director'],
                'description' => 'الوسيط لعدد الساعات منذ وصول كل معاملة مفتوحة إلى المدير التنفيذي حتى الآن.'],
            ['code' => 'REQ_COMPLETED', 'domain' => 'institution', 'audience' => 'shared',
                'title' => 'معاملات أُنجزت في الفترة', 'source' => 'workflow_requests', 'formula' => 'count', 'numerator' => 'completed', 'unit' => 'count',
                'description' => 'معاملات انتهت بالإنجاز خلال الفترة، منسوبة إلى قسم مقدم الطلب. المرفوضة والملغاة لا تدخل.'],
            ['code' => 'REQ_CYCLE_MEDIAN', 'domain' => 'institution', 'audience' => 'shared', 'polarity' => 'lower',
                'title' => 'وسيط زمن إنجاز المعاملة', 'source' => 'workflow_requests', 'formula' => 'median', 'numerator' => 'cycle_hours', 'unit' => 'days',
                'description' => 'الوسيط للمدة من تقديم المعاملة حتى إنجازها، للمعاملات المنجزة خلال الفترة.'],
            ['code' => 'TASK_HANDLING_MEDIAN', 'domain' => 'institution', 'audience' => 'shared', 'polarity' => 'lower',
                'title' => 'وسيط زمن معالجة المرحلة', 'source' => 'workflow_tasks', 'formula' => 'median', 'numerator' => 'handling_hours', 'unit' => 'hours',
                'description' => 'الوسيط للمدة بين وصول المهمة إلى المسؤول وتنفيذ الإجراء عليها، منسوبة إلى قسم منفذ الإجراء.'],
            $unavailable('EXEC_BOARD_DECISIONS', 'institution', null, 'تنفيذ قرارات مجلس الإدارة في موعدها', 'لا يوجد سجل لقرارات مجلس الإدارة ومواعيد تنفيذها؛ مسار إجازة المدير التنفيذي يسجل قرار المجلس للإجازة فقط.'),
            $unavailable('EXEC_COMPLAINTS_SLA', 'institution', null, 'إغلاق الشكاوى ضمن المهلة', 'لا يوجد سجل شكاوى رسمي بمهلة إغلاق معتمدة.'),

            ['code' => 'HR_PROFILE_COMPLETENESS', 'domain' => 'hr', 'owner' => 'HR', 'audience' => 'shared', 'home' => ['department'],
                'title' => 'اكتمال ملفات الموظفين', 'source' => 'employee_profiles', 'formula' => 'percentage', 'numerator' => 'complete', 'denominator' => 'active', 'unit' => 'percent',
                'description' => 'نسبة ملفات الموظفين النشطين التي اجتازت التحقق النهائي في معالج الملف. لا تعني تدقيق صحة الوثائق.'],
            ['code' => 'HR_PENDING_LEAVES', 'domain' => 'hr', 'owner' => 'HR', 'audience' => 'shared', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'طلبات إجازة بانتظار البت', 'source' => 'leave_requests', 'formula' => 'count', 'numerator' => 'pending', 'unit' => 'count',
                'description' => 'طلبات إجازة مقدمة لم يصدر فيها قرار نهائي بعد.'],
            ['code' => 'HR_LEAVE_DECISION_MEDIAN', 'domain' => 'hr', 'owner' => 'HR', 'audience' => 'shared', 'polarity' => 'lower',
                'title' => 'وسيط زمن البت في طلبات الإجازة', 'source' => 'leave_requests', 'formula' => 'median', 'numerator' => 'decision_hours', 'unit' => 'hours',
                'description' => 'الوسيط للمدة من تقديم طلب الإجازة حتى القرار النهائي، للطلبات المبتوت فيها خلال الفترة.'],
            ['code' => 'HR_LEAVE_SLA', 'domain' => 'hr', 'owner' => 'HR', 'audience' => 'shared',
                'title' => 'مراحل الإجازات المنجزة ضمن المهلة', 'source' => 'workflow_tasks', 'formula' => 'percentage', 'numerator' => 'handled_on_time', 'denominator' => 'handled_with_deadline', 'unit' => 'percent', 'filters' => ['subject_type' => 'employee_leave_request'],
                'description' => 'من مراحل مسارات الإجازة المنجزة خلال الفترة ولها مهلة، نسبة ما أُنجز ضمن المهلة.'],
            ['code' => 'HR_ATTENDANCE_ON_TIME', 'domain' => 'hr', 'owner' => 'HR', 'audience' => 'shared', 'home' => ['department'],
                'title' => 'الانتظام في الدوام', 'source' => 'attendance_days', 'formula' => 'percentage', 'numerator' => 'on_time', 'denominator' => 'evaluated', 'unit' => 'percent',
                'description' => 'أيام دوام مكتملة دون تأخير بعد مهلة السماح ودون خروج مبكر، من أيام العمل المجدولة التي قُيّمت.'],
            $unavailable('HR_CONTRACT_RENEWALS', 'hr', 'HR', 'تجديد عقود الموظفين في موعدها', 'ملف الموظف يحفظ نوع العقد دون تاريخ انتهائه أو قرار تجديده؛ لا توجد عينة عقود مستحقة للتجديد.'),

            ['code' => 'MNT_OPEN', 'domain' => 'maintenance', 'owner' => 'HR', 'audience' => 'owner', 'polarity' => 'lower',
                'title' => 'طلبات صيانة مفتوحة', 'source' => 'workflow_requests', 'formula' => 'count', 'numerator' => 'open', 'unit' => 'count', 'filters' => ['workflow_code' => 'MAINTENANCE'],
                'description' => 'طلبات الصيانة المفتوحة الآن من كل الأقسام. مالك المؤشر الجهة التي تدير مسار الصيانة حاليًا.'],
            ['code' => 'MNT_COMPLETED', 'domain' => 'maintenance', 'owner' => 'HR', 'audience' => 'owner',
                'title' => 'طلبات صيانة أُنجزت في الفترة', 'source' => 'workflow_requests', 'formula' => 'count', 'numerator' => 'completed', 'unit' => 'count', 'filters' => ['workflow_code' => 'MAINTENANCE'],
                'description' => 'طلبات الصيانة المنجزة خلال الفترة من كل الأقسام.'],
            ['code' => 'MNT_CYCLE_MEDIAN', 'domain' => 'maintenance', 'owner' => 'HR', 'audience' => 'owner', 'polarity' => 'lower',
                'title' => 'وسيط زمن إنجاز طلب الصيانة', 'source' => 'workflow_requests', 'formula' => 'median', 'numerator' => 'cycle_hours', 'unit' => 'days', 'filters' => ['workflow_code' => 'MAINTENANCE'],
                'description' => 'الوسيط للمدة من تقديم طلب الصيانة حتى إنجازه، للطلبات المنجزة خلال الفترة.'],
            ['code' => 'MNT_SLA', 'domain' => 'maintenance', 'owner' => 'HR', 'audience' => 'owner',
                'title' => 'مراحل الصيانة المنجزة ضمن المهلة', 'source' => 'workflow_tasks', 'formula' => 'percentage', 'numerator' => 'handled_on_time', 'denominator' => 'handled_with_deadline', 'unit' => 'percent', 'filters' => ['workflow_code' => 'MAINTENANCE'],
                'description' => 'من مراحل مسار الصيانة المنجزة خلال الفترة ولها مهلة، نسبة ما أُنجز ضمن المهلة.'],
            $unavailable('MNT_PREVENTIVE', 'maintenance', 'HR', 'إنجاز الصيانة الوقائية المجدولة', 'لا يوجد برنامج صيانة وقائية مجدول بمواعيد استحقاق في النظام.'),
            $unavailable('MNT_REPEAT_FAULTS', 'maintenance', 'HR', 'الأعطال المتكررة', 'التكرار يحتاج ربط الطلب بأصل محدد وتعريفًا معتمدًا لنافذة التكرار؛ الطلب يحفظ الموقع نصًا ونوع العطل فقط.', 'count'),

            ['code' => 'FIN_PAYMENT_TIME_MEDIAN', 'domain' => 'finance', 'owner' => 'FIN', 'audience' => 'owner', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'وسيط زمن معالجة سندات الصرف', 'source' => 'finance_payments', 'formula' => 'median', 'numerator' => 'processing_hours', 'unit' => 'hours',
                'description' => 'الوسيط للمدة من إنشاء سند الصرف حتى تنفيذه، للسندات المنفذة خلال الفترة. التحويلات الداخلية والسندات المعكوسة مستبعدة.'],
            ['code' => 'FIN_OPEN_RECONCILIATIONS', 'domain' => 'finance', 'owner' => 'FIN', 'audience' => 'owner', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'فروقات مطابقة صناديق غير مغلقة', 'source' => 'finance_controls', 'formula' => 'count', 'numerator' => 'open_reconciliations', 'unit' => 'count',
                'description' => 'مطابقات صناديق سُجل فيها فرق ولم تُغلق بعد.'],
            ['code' => 'FIN_UNSETTLED_ADVANCES', 'domain' => 'finance', 'owner' => 'FIN', 'audience' => 'owner', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'سلف مصروفة غير مسوّاة', 'source' => 'finance_controls', 'formula' => 'count', 'numerator' => 'unsettled_advances', 'unit' => 'count',
                'description' => 'سلف صُرفت ولم تُسوَّ بعد.'],
            $unavailable('FIN_DOCUMENT_COMPLETENESS', 'finance', 'FIN', 'اكتمال المستندات المالية', 'لا توجد قائمة مستندات إلزامية لكل نوع سند ولا نتيجة تدقيق محفوظة.'),

            ['code' => 'RENT_COLLECTION_RATE', 'domain' => 'rentals', 'owner' => 'ASSETS', 'viewers' => ['FIN'], 'audience' => 'owner', 'home' => ['executive', 'department'],
                'title' => 'تحصيل مستحقات الإيجار', 'source' => 'rental_dues', 'formula' => 'percentage', 'numerator' => 'collected', 'denominator' => 'due_net', 'unit' => 'percent',
                'description' => 'المقبوض بسندات قبض منفذة وغير معكوسة والمخصص لاستحقاقات الفترة ÷ صافي تلك الاستحقاقات المسجلة في الحسابات. الإعفاء والتسوية ليسا قبضًا.'],
            ['code' => 'RENT_OCCUPANCY', 'domain' => 'rentals', 'owner' => 'ASSETS', 'audience' => 'owner', 'home' => ['department'],
                'title' => 'إشغال الأعيان القابلة للتأجير', 'source' => 'rental_units', 'formula' => 'percentage', 'numerator' => 'occupied', 'denominator' => 'rentable', 'unit' => 'percent',
                'description' => 'الأعيان المرتبطة اليوم بعقد فعال ÷ الأعيان القابلة للتأجير في عقارات فعالة، باستثناء ما هو خارج الخدمة أو تحت الصيانة.'],
            ['code' => 'RENT_OUTSTANDING', 'domain' => 'rentals', 'owner' => 'ASSETS', 'viewers' => ['FIN'], 'audience' => 'owner', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'المتبقي من مستحقات الفترة', 'source' => 'rental_dues', 'formula' => 'sum', 'numerator' => 'outstanding', 'unit' => 'money',
                'description' => 'غير المحصل من استحقاقات الإيجار التي حلّت خلال الفترة.'],
            ['code' => 'RENT_ARREARS_PRIOR', 'domain' => 'rentals', 'owner' => 'ASSETS', 'viewers' => ['FIN'], 'audience' => 'owner', 'polarity' => 'lower',
                'title' => 'متأخرات من فترات سابقة', 'source' => 'rental_dues', 'formula' => 'sum', 'numerator' => 'arrears_prior', 'unit' => 'money',
                'description' => 'غير المحصل من استحقاقات حلّت قبل بداية الفترة، منفصلًا عن مستحقات الفترة.'],
            ['code' => 'RENT_EXPIRING_NO_RENEWAL', 'domain' => 'rentals', 'owner' => 'ASSETS', 'audience' => 'owner', 'home' => ['department'], 'polarity' => 'lower',
                'title' => 'عقود تنتهي قريبًا دون إجراء تجديد', 'source' => 'rental_contracts', 'formula' => 'count', 'numerator' => 'expiring_without_renewal', 'unit' => 'count', 'params' => ['window_days' => 60],
                'description' => 'عقود فعالة تنتهي خلال نافذة الأيام المحددة في المؤشر (60 يومًا عند التثبيت)، ولا يوجد لها عقد تجديد ولا معاملة تجديد قائمة.'],
            ['code' => 'RENT_EVICTION_DECISION_MEDIAN', 'domain' => 'rentals', 'owner' => 'ASSETS', 'audience' => 'owner', 'polarity' => 'lower',
                'title' => 'وسيط زمن البت في طلبات الإخلاء', 'source' => 'rental_cases', 'formula' => 'median', 'numerator' => 'decision_hours', 'unit' => 'days', 'filters' => ['case_kind' => 'eviction'],
                'description' => 'الوسيط للمدة من فتح معاملة الإخلاء حتى القرار الإداري فيها، للمعاملات المبتوت فيها خلال الفترة. تنفيذ التسليم الفعلي غير مسجل بعد.'],
            $unavailable('RENT_RENEWALS_ON_TIME', 'rentals', 'ASSETS', 'تجديد عقود الإيجار في موعدها', 'لا توجد مهلة معتمدة لقرار التجديد قبل نهاية العقد، وانتهاء العقد لا يعني وجوب تجديده. مؤشر «عقود تنتهي قريبًا دون إجراء تجديد» يعرض الحالات القائمة.'),

            $unavailable('ORPHAN_FILE_COMPLETENESS', 'orphans', 'ORPHANS', 'اكتمال ملفات الأيتام المعتمدة', $orphans),
            $unavailable('ORPHAN_DUE_VISITS', 'orphans', 'ORPHANS', 'الزيارات والتقييمات المستحقة المنفذة', $orphans),
            $unavailable('ORPHAN_ACTIVE_SPONSORSHIPS', 'orphans', 'ORPHANS', 'الكفالات الفعالة', $orphans, 'count'),
            $unavailable('ORPHAN_AID_COMPLETED', 'orphans', 'ORPHANS', 'طلبات المساعدة المنجزة', $orphans),
            $unavailable('SHELTER_SHIFT_COVERAGE', 'shelter', 'SHELTER', 'تغطية المناوبات', $shelter),
            $unavailable('SHELTER_DAILY_REPORTS', 'shelter', 'SHELTER', 'التقارير اليومية المطلوبة المنجزة', $shelter),
            $unavailable('SHELTER_URGENT_RESPONSE', 'shelter', 'SHELTER', 'الاستجابة للحالات العاجلة', $shelter),
            $unavailable('SHELTER_OPEN_REQUESTS', 'shelter', 'SHELTER', 'الطلبات التشغيلية المفتوحة', $shelter, 'count'),
            $unavailable('MEDIA_PUBLISHED_ON_SCHEDULE', 'media', null, 'المنشورات المعتمدة المنشورة في موعدها', $media),
            $unavailable('MEDIA_COVERAGE_FULFILLED', 'media', null, 'طلبات التغطية المنفذة', $media),
            $unavailable('MEDIA_EVENTS_EXECUTED', 'media', null, 'الفعاليات المنفذة', $media, 'count'),
            $unavailable('MEDIA_ACTIVE_PARTNERSHIPS', 'media', null, 'الشراكات الفعالة', $media, 'count'),
            $unavailable('MEDIA_OVERDUE_REQUESTS', 'media', null, 'الطلبات الإعلامية المتأخرة', $media, 'count'),
            $unavailable('SEC_CORRESPONDENCE_ON_TIME', 'secretariat', null, 'المراسلات المعالجة في موعدها', $secretariat),
            $unavailable('SEC_BOARD_REFERRALS', 'secretariat', null, 'قرارات المجلس المحالة للتنفيذ', $secretariat, 'count'),
            $unavailable('SEC_MINUTES_COMPLETED', 'secretariat', null, 'المحاضر المكتملة', $secretariat),
            $unavailable('SEC_OVERDUE_TASKS', 'secretariat', null, 'المهام المحالة المتأخرة', $secretariat, 'count'),
        ];
    }
};
