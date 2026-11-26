<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the attendance module without a parallel engine (owner's directive 2026-10-29):
 *
 * - Shift templates gain their own settings (kind fixed/flexible/rotation, grace, break, punch windows, effective dates).
 * - Approved exceptions gain an effect: work hours, rest day, «لا يوجد دوام مقرر» (no duty) or «معفى من البصمة» (works
 *   without punching), a reason from system constants, a link to a shift swap and an audited revocation.
 * - «تبديل المناوبة» (shift swaps) and «طلب إذن» (permission requests) run on the central workflow engine.
 * - «الإجراءات الإدارية» register with an append-only timeline; no sanction or payroll effect is automatic.
 * - Shift-swap limits (same department, rest, daily hours, days per request) in one audited settings row.
 *
 * Purely additive: no existing row changes meaning. New reference values are seeded only where the directive names them.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_10_29_120423 (الورديات والإعفاءات وتبديل المناوبة والأذونات والإجراءات الإدارية)';

    /** Tables that hold personnel records once used; down() refuses to drop them while they have rows. */
    private const GUARDED = ['attendance_shift_swaps', 'attendance_permission_requests', 'employee_hr_actions'];

    private const PERMISSIONS = [
        'hr.actions.view' => 'عرض سجل الإجراءات الإدارية للموظفين',
        'hr.actions.manage' => 'إعداد الإجراءات الإدارية للموظفين ورفعها للمراجعة',
        'hr.actions.decide' => 'مراجعة الإجراءات الإدارية وإصدارها أو رفضها',
    ];

    private const EVENTS = [
        'attendance_swap_requested' => ['طلب تبديل مناوبة بانتظار موافقتك', 'للموظف الذي طُلب منه تبديل مناوبة، ليوافق أو يعتذر.'],
        'attendance_swap_decided' => ['قرار طلب تبديل المناوبة', 'لطرفي التبديل عند الاعتماد أو الرفض أو الاعتذار أو الإلغاء.'],
        'attendance_permission_decided' => ['قرار طلب الإذن', 'لمقدم طلب الإذن عند اعتماده أو رفضه أو إلغائه من الموارد البشرية.'],
        'attendance_schedule_changed' => ['تغيير على جدول دوامك', 'للموظف عند اعتماد إعفاء أو تغيير طارئ أو تبديل يمس جدول دوامه.'],
        'hr_action_review' => ['إجراء إداري بانتظار المراجعة', 'لمن يملك مراجعة الإجراءات الإدارية عند رفع إجراء جديد.'],
        'hr_action_issued' => ['إجراء إداري صادر بحقك', 'للموظف عند إصدار إجراء إداري بحقه، ليطلع عليه ويرد إن رغب.'],
    ];

    public function up(): void
    {
        $this->shiftColumns();
        $this->overrideColumns();
        $this->swapTable();
        $this->permissionTable();
        $this->hrActionTables();
        $this->settingsTable();
        $this->constants();
        $this->access();
        $hr = (int) DB::table('departments')->where('code', 'HR')->value('id');
        if ($hr <= 0) {
            throw new RuntimeException('قسم الموارد البشرية مطلوب لتهيئة مسارات الحضور.');
        }
        $this->workflow('ATTENDANCE_PERMISSION', 'attendance_permission_request', 'طلب إذن (تأخير، انصراف مبكر، خروج مؤقت)', 'PRM',
            'يقدمه الموظف من ملفه، ويقرره رئيس قسمه، أو الموارد البشرية إن لم يكن له رئيس قسم. الإذن المعتمد يفسّر التأخير دون تغيير البصمة الأصلية.', [
                ['requester', 'تقديم طلب الإذن', 10, 'start', 'requester', null, null],
                ['head_decision', 'قرار رئيس القسم', 20, 'decision', 'department_head', null, ['fallback_department_id' => $hr]],
                ['hr_decision', 'قرار الموارد البشرية', 30, 'decision', 'department_head_of', null, ['department_id' => $hr, 'fallback_role_code' => 'executive_director']],
                ['approved', 'إذن معتمد', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الإذن', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['requester', 'head_decision', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
                ['requester', 'hr_decision', 'submit_hr', 'إرسال إلى الموارد البشرية (لا يوجد رئيس قسم)', false, ['requester_has_department_head' => false]],
                ['head_decision', 'approved', 'approve', 'اعتماد الإذن', false, null],
                ['head_decision', 'rejected', 'reject', 'رفض الإذن (اذكر السبب)', true, null],
                ['hr_decision', 'approved', 'approve', 'اعتماد الإذن', false, null],
                ['hr_decision', 'rejected', 'reject', 'رفض الإذن (اذكر السبب)', true, null],
            ]);
        $this->workflow('ATTENDANCE_SHIFT_SWAP', 'attendance_shift_swap', 'تبديل مناوبة بين موظفين', 'SWP',
            'يبدأ بعد موافقة الزميل؛ يوصي رئيس القسم ثم تعتمده الموارد البشرية. لا يتغير الجدول إلا عند الاعتماد النهائي.', [
                ['requester', 'طلب التبديل بعد موافقة الزميل', 10, 'start', 'requester', null, null],
                ['head_decision', 'توصية رئيس القسم', 20, 'decision', 'department_head', null, ['fallback_department_id' => $hr]],
                ['hr_decision', 'اعتماد الموارد البشرية', 30, 'decision', 'department_head_of', null, ['department_id' => $hr, 'fallback_role_code' => 'executive_director']],
                ['approved', 'تبديل معتمد ومطبق على الجدول', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض التبديل', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['requester', 'head_decision', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
                ['requester', 'hr_decision', 'submit_hr', 'إرسال إلى الموارد البشرية (لا يوجد رئيس قسم)', false, ['requester_has_department_head' => false]],
                ['head_decision', 'hr_decision', 'recommend', 'موافقة ورفع إلى الموارد البشرية', false, null],
                ['head_decision', 'rejected', 'reject', 'رفض التبديل (اذكر السبب)', true, null],
                ['hr_decision', 'approved', 'approve', 'اعتماد التبديل وتطبيقه على الجدول', false, null],
                ['hr_decision', 'rejected', 'reject', 'رفض التبديل (اذكر السبب)', true, null],
            ]);

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'attendance.completion.installed', 'subject_type' => 'attendance', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => [...self::GUARDED, 'employee_hr_action_events', 'attendance_module_settings'],
                'permissions' => array_keys(self::PERMISSIONS), 'workflows' => ['ATTENDANCE_PERMISSION', 'ATTENDANCE_SHIFT_SWAP']], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shiftColumns(): void
    {
        Schema::table('attendance_schedules', function (Blueprint $table): void {
            $table->string('kind', 16)->default('fixed')->comment('fixed | flexible | rotation');
            $table->unsignedSmallInteger('grace_minutes')->nullable()->comment('null: the HR grace constant applies');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('break_paid')->default(true);
            $table->unsignedSmallInteger('required_minutes')->nullable()->comment('flexible shifts: minutes to work inside the band');
            $table->unsignedSmallInteger('required_punches')->default(2)->comment('1: entry only, 2: entry and exit');
            $table->unsignedSmallInteger('window_before_minutes')->nullable()->comment('null: 240');
            $table->unsignedSmallInteger('window_after_minutes')->nullable()->comment('null: 240');
            $table->unsignedSmallInteger('cycle_days')->nullable()->comment('rotation: days in one cycle; schedule_days.weekday holds the cycle day');
            $table->date('cycle_anchor')->nullable()->comment('rotation: the date that is cycle day 1');
            $table->string('location', 150)->nullable();
            $table->string('timezone', 64)->nullable()->comment('null: application time zone');
            $table->text('notes')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
        });
        DB::statement("ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedules_kind_check CHECK (kind IN ('fixed','flexible','rotation'))");
        DB::statement('ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedules_limits_check CHECK ((grace_minutes IS NULL OR grace_minutes <= 240) AND break_minutes <= 600 AND required_punches IN (1,2)'
            .' AND (window_before_minutes IS NULL OR window_before_minutes <= 720) AND (window_after_minutes IS NULL OR window_after_minutes <= 720)'
            .' AND (required_minutes IS NULL OR required_minutes BETWEEN 1 AND 1440) AND (cycle_days IS NULL OR cycle_days BETWEEN 1 AND 56))');
        DB::statement("ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedules_kind_fields_check CHECK ((kind <> 'rotation' OR (cycle_days IS NOT NULL AND cycle_anchor IS NOT NULL)) AND (kind <> 'flexible' OR required_minutes IS NOT NULL))");
        DB::statement('ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedules_effective_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
    }

    private function overrideColumns(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table): void {
            $table->string('effect', 16)->default('schedule')->comment('schedule | off | no_duty | no_punch');
            $table->foreignId('reason_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->unsignedBigInteger('swap_id')->nullable()->index();
            $table->string('location', 150)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->index(['status', 'starts_on', 'ends_on']);
        });
        DB::statement("ALTER TABLE attendance_overrides ADD CONSTRAINT attendance_overrides_effect_check CHECK (effect IN ('schedule','off','no_duty','no_punch'))");
    }

    private function swapTable(): void
    {
        Schema::create('attendance_shift_swaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requester_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('counterpart_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 24);
            $table->jsonb('days')->comment('[{date, requester_before, counterpart_before}] snapshots at request time');
            $table->text('reason');
            $table->text('counterpart_note')->nullable();
            $table->timestampTz('counterpart_responded_at')->nullable();
            $table->unsignedBigInteger('workflow_instance_id')->nullable()->index();
            $table->jsonb('applied')->nullable()->comment('override ids and before/after snapshots written on approval');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['requester_employee_id', 'status']);
            $table->index(['counterpart_employee_id', 'status']);
        });
        DB::statement("ALTER TABLE attendance_shift_swaps ADD CONSTRAINT attendance_shift_swaps_status_check CHECK (status IN ('awaiting_counterpart','counterpart_declined','pending_approval','approved','rejected','cancelled','reversed'))");
        DB::statement('ALTER TABLE attendance_shift_swaps ADD CONSTRAINT attendance_shift_swaps_people_check CHECK (requester_employee_id <> counterpart_employee_id)');
        Schema::table('attendance_overrides', function (Blueprint $table): void {
            $table->foreign('swap_id')->references('id')->on('attendance_shift_swaps')->restrictOnDelete();
        });
    }

    private function permissionTable(): void
    {
        Schema::create('attendance_permission_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->date('work_date');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('minutes');
            $table->text('reason');
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('status', 16);
            $table->string('source', 16)->default('employee')->comment('employee: self-service workflow; hr: registered by HR with a justification');
            $table->unsignedBigInteger('workflow_instance_id')->nullable()->index();
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'work_date']);
            $table->index(['status', 'work_date']);
        });
        DB::statement("ALTER TABLE attendance_permission_requests ADD CONSTRAINT attendance_permission_requests_status_check CHECK (status IN ('submitted','approved','rejected','cancelled','revoked'))");
        DB::statement('ALTER TABLE attendance_permission_requests ADD CONSTRAINT attendance_permission_requests_period_check CHECK (ends_at > starts_at AND minutes BETWEEN 1 AND 1440)');
    }

    private function hrActionTables(): void
    {
        Schema::create('employee_hr_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_no', 30)->nullable()->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->date('incident_date');
            $table->date('attendance_date')->nullable()->comment('the attendance day the action was raised from');
            $table->text('facts');
            $table->text('evidence')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('requires_acknowledgment')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('employee_response')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['status', 'incident_date']);
        });
        DB::statement("ALTER TABLE employee_hr_actions ADD CONSTRAINT employee_hr_actions_status_check CHECK (status IN ('draft','in_review','issued','rejected','cancelled'))");
        Schema::create('employee_hr_action_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_action_id')->constrained('employee_hr_actions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 40);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->jsonb('snapshot');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['hr_action_id', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION employee_hr_action_events_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'The HR action timeline is append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER employee_hr_action_events_append_only BEFORE UPDATE OR DELETE ON employee_hr_action_events FOR EACH ROW EXECUTE FUNCTION employee_hr_action_events_append_only();
SQL);
        }
    }

    /**
     * Shift-swap limits managed by the HR head or the executive director from «الحضور والانصراف» (one row, audited).
     * The seeded values are provisional (owner's decision C, 2026-10-29) until a policy reference is recorded.
     */
    private function settingsTable(): void
    {
        Schema::create('attendance_module_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('swap_same_department')->default(true);
            $table->unsignedSmallInteger('swap_min_rest_hours')->default(8);
            $table->unsignedSmallInteger('swap_max_hours_per_day')->default(16);
            $table->unsignedSmallInteger('swap_max_days')->default(7);
            $table->string('policy_reference', 255)->nullable()->comment('the employment policy or decision these limits were reviewed against');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE attendance_module_settings ADD CONSTRAINT attendance_module_settings_limits_check CHECK (swap_min_rest_hours <= 24'
            .' AND swap_max_hours_per_day BETWEEN 4 AND 24 AND swap_max_days BETWEEN 1 AND 31)');
        DB::table('attendance_module_settings')->insert(['id' => 1, 'swap_same_department' => true, 'swap_min_rest_hours' => 8, 'swap_max_hours_per_day' => 16,
            'swap_max_days' => 7, 'policy_reference' => null, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function constants(): void
    {
        $group = DB::table('master_data_groups')->where('code', 'hr')->value('id');
        if (! $group) {
            throw new RuntimeException('مجموعة ثوابت شؤون الموظفين غير موجودة.');
        }
        $now = now();
        $types = [
            'attendance_permission_types' => ['أنواع أذونات الدوام', 'Attendance permission types', 910,
                'أنواع «طلب إذن». قواعد كل نوع (الأثر، الحد الأقصى، المهلة المسبقة، المرفق، السقف الشهري) تُضبط من صفحة الحضور والانصراف. لا خصم ولا جزاء تلقائي.'],
            'attendance_exemption_reasons' => ['أسباب الإعفاء من الدوام أو البصمة', 'Attendance exemption reasons', 920,
                'سبب «إعفاء من الدوام» (لا يوجد دوام مقرر) أو «معفى من البصمة» (يعمل دون بصمة بموجب تكليف معتمد).'],
        ];
        foreach ($types as $code => [$nameAr, $nameEn, $order, $description]) {
            DB::table('master_data_types')->insertOrIgnore(['group_id' => $group, 'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                'description' => $description, 'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $typeId = fn (string $code): int => (int) DB::table('master_data_types')->where('code', $code)->value('id');
        $values = [
            'attendance_permission_types' => [
                ['late_arrival', 'إذن تأخير', 10, ['effect' => 'late']],
                ['early_departure', 'إذن انصراف مبكر', 20, ['effect' => 'early']],
                ['temporary_exit', 'إذن خروج مؤقت', 30, ['effect' => 'temporary_exit']],
            ],
            'attendance_exemption_reasons' => [
                ['offsite_duty', 'مهمة خارجية معتمدة', 10, ['effect' => 'no_punch']],
                ['administrative_assignment', 'تكليف إداري', 20, ['effect' => 'no_punch']],
                ['remote_duty', 'عمل عن بعد', 30, ['effect' => 'no_punch']],
                ['non_punch_role', 'وظيفة لا تتطلب البصمة', 40, ['effect' => 'no_punch']],
                ['emergency_closure', 'إغلاق طارئ', 50, ['effect' => 'no_duty']],
                ['approved_exception', 'استثناء معتمد من الإدارة', 60, ['effect' => 'no_duty']],
            ],
            // «أنواع العقوبات» exists empty; the directive names these two. Others are added by HR.
            'disciplinary_action_types' => [
                ['notice', 'تنبيه', 10, ['decider' => 'hr', 'requires_acknowledgment' => true]],
                ['attention', 'لفت نظر', 20, ['decider' => 'hr', 'requires_acknowledgment' => true]],
            ],
        ];
        $permissionRules = ['max_minutes' => null, 'lead_hours' => null, 'retro_days' => null, 'attachment' => 'optional', 'monthly_cap_count' => null,
            'monthly_cap_minutes' => null, 'excuses' => true, 'pay_effect' => 'none'];
        foreach ($values as $type => $rows) {
            $id = $typeId($type);
            if ($id <= 0) {
                throw new RuntimeException("نوع الثوابت {$type} غير موجود.");
            }
            foreach ($rows as [$code, $name, $order, $metadata]) {
                $metadata = $type === 'attendance_permission_types' ? $metadata + $permissionRules : $metadata;
                DB::table('master_data_values')->insertOrIgnore(['type_id' => $id, 'code' => $code, 'name_ar' => $name, 'sort_order' => $order, 'is_active' => true,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    private function access(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        // The executive director reviews and decides; the HR head holds all three through headship (User::DEPARTMENT_HEAD_PERMISSIONS).
        $director = DB::table('roles')->where('code', 'executive_director')->value('id');
        if ($director) {
            foreach (DB::table('permissions')->whereIn('code', ['hr.actions.view', 'hr.actions.decide'])->pluck('id') as $permission) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $director]);
            }
        }
        foreach (self::EVENTS as $code => [$label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>  $transitions
     */
    private function workflow(string $code, string $subjectType, string $name, string $prefix, string $description, array $steps, array $transitions): void
    {
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => $code, 'subject_type' => $subjectType, 'name' => $name, 'description' => $description,
            'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => $prefix, 'is_confidential' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ids = [];
        foreach ($steps as [$stepCode, $stepName, $order, $stepType, $assignment, $role, $config]) {
            $ids[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $stepName, 'sort_order' => $order, 'step_type' => $stepType,
                'assignment_type' => $assignment, 'assigned_role_code' => $role, 'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $transitionName, $comment, $conditions]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $action, 'name' => $transitionName,
                'conditions' => $conditions === null ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE), 'requires_comment' => $comment, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
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

    /**
     * Rolled back only while the new registers are empty and no request runs on the new workflows.
     */
    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        if (DB::table('attendance_overrides')->where(fn ($query) => $query->where('effect', '<>', 'schedule')->orWhereNotNull('reason_value_id')->orWhereNotNull('revoked_at'))->exists()) {
            throw new RuntimeException('توجد إعفاءات أو استثناءات بالأعمدة الجديدة؛ لا يمكن التراجع.');
        }
        if (DB::table('attendance_schedules')->where(fn ($query) => $query->where('kind', '<>', 'fixed')->orWhereNotNull('grace_minutes')->orWhere('break_minutes', '>', 0))->exists()) {
            throw new RuntimeException('توجد قوالب دوام تستخدم الإعدادات الجديدة؛ لا يمكن التراجع.');
        }
        if (Schema::hasTable('attendance_module_settings') && DB::table('attendance_module_settings')->whereNotNull('updated_by')->exists()) {
            throw new RuntimeException('عُدّلت إعدادات تبديل المناوبة بعد التشغيل؛ لا يمكن التراجع.');
        }
        $definitions = DB::table('workflow_definitions')->whereIn('code', ['ATTENDANCE_PERMISSION', 'ATTENDANCE_SHIFT_SWAP'])->pluck('id');
        $versions = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitions)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
            throw new RuntimeException('توجد معاملات على مسارات الحضور؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_versions')->whereIn('id', $versions)->delete();
        DB::table('workflow_definitions')->whereIn('id', $definitions)->delete();
        DB::table('notification_settings')->whereIn('event_code', array_keys(self::EVENTS))->delete();
        $permissions = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissions)->delete();
        DB::table('permissions')->whereIn('id', $permissions)->delete();
        // Seeded reference values are removed only while unused; HR may have added more since.
        foreach (['attendance_permission_types', 'attendance_exemption_reasons'] as $type) {
            $typeId = DB::table('master_data_types')->where('code', $type)->value('id');
            DB::table('master_data_values')->where('type_id', $typeId)->delete();
            DB::table('master_data_types')->where('id', $typeId)->delete();
        }
        $disciplinary = DB::table('master_data_types')->where('code', 'disciplinary_action_types')->value('id');
        DB::table('master_data_values')->where('type_id', $disciplinary)->whereIn('code', ['notice', 'attention'])->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS employee_hr_action_events_append_only ON employee_hr_action_events; DROP FUNCTION IF EXISTS employee_hr_action_events_append_only();');
        }
        Schema::dropIfExists('attendance_module_settings');
        Schema::dropIfExists('employee_hr_action_events');
        Schema::dropIfExists('employee_hr_actions');
        Schema::dropIfExists('attendance_permission_requests');
        Schema::table('attendance_overrides', function (Blueprint $table): void {
            $table->dropForeign(['swap_id']);
            $table->dropForeign(['reason_value_id']);
            $table->dropForeign(['revoked_by']);
            $table->dropIndex(['status', 'starts_on', 'ends_on']);
            $table->dropColumn(['effect', 'reason_value_id', 'swap_id', 'location', 'revoked_at', 'revoked_by', 'revocation_reason']);
        });
        Schema::dropIfExists('attendance_shift_swaps');
        foreach (['attendance_schedules_kind_check', 'attendance_schedules_limits_check', 'attendance_schedules_kind_fields_check', 'attendance_schedules_effective_check'] as $constraint) {
            DB::statement("ALTER TABLE attendance_schedules DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('attendance_schedules', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'grace_minutes', 'break_minutes', 'break_paid', 'required_minutes', 'required_punches', 'window_before_minutes',
                'window_after_minutes', 'cycle_days', 'cycle_anchor', 'location', 'timezone', 'notes', 'effective_from', 'effective_to']);
        });
    }
};
