<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complete employee master profile (owner's staff workbook, 33 columns): the personal and contact fields the
 * profile lacked, effective-dated pay and bank records, placement history, leave baselines with an as-of date,
 * the profile photo, versioned personnel documents and a staged, reviewable import. Purely additive; the only
 * relaxation is that a qualification's institution becomes optional (the workbook and the owner's prompt list it
 * as optional). Reference values are seeded only where the workbook or the prompt names them.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_18_000001 (الملف الشامل للموظف والاستيراد الآمن والصورة والوثائق)';

    /** Tables that hold personnel data once used; down() refuses to drop them while they have rows. */
    private const GUARDED = ['employee_import_batches', 'employee_compensations', 'employee_bank_accounts', 'employee_leave_baselines',
        'employee_photos', 'employee_documents', 'employee_pay_changes'];

    private const PERMISSIONS = [
        'hr.employees.pay.view' => 'عرض رواتب الموظفين وعلاواتهم',
        'hr.employees.bank.view' => 'عرض الحسابات البنكية للموظفين كاملة',
        'hr.employees.pay.request' => 'طلب تعديل راتب موظف أو حسابه البنكي',
    ];

    public function up(): void
    {
        $this->employeeColumns();
        $this->qualificationColumns();
        $this->importTables();
        $this->payAndLeaveTables();
        $this->fileTables();
        $this->constants();
        $this->access();
        $this->workflow();
        $this->navigation();

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'hr.employee_master_profile.installed', 'subject_type' => 'employees', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => [...self::GUARDED, 'employee_placements', 'employee_import_rows', 'employee_import_aliases', 'hr_employee_file_settings'],
                'permissions' => array_keys(self::PERMISSIONS), 'workflow' => 'EMPLOYEE_PAY_CHANGE'], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function employeeColumns(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('full_name_ar', 255)->nullable()->comment('الاسم الكامل كما ورد في المصدر');
            $table->string('original_family_name_ar', 150)->nullable();
            $table->string('name_review_status', 20)->default('reviewed');
            $table->unsignedSmallInteger('children_count')->nullable();
            $table->string('landline_phone', 30)->nullable();
            $table->text('residence_address')->nullable()->comment('عنوان السكن الكامل كما ورد في المصدر');
            $table->string('attendance_number', 50)->nullable()->comment('رقم الموظف على جهاز البصمة');
            $table->string('job_title_source', 255)->nullable();
            $table->string('department_source', 255)->nullable();
            $table->string('grade_source', 100)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_name_review_status_check CHECK (name_review_status IN ('reviewed','suggested'))");
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_children_count_check CHECK (children_count IS NULL OR children_count <= 40)');
        DB::statement('CREATE UNIQUE INDEX employees_attendance_number_live_unique ON employees (attendance_number) WHERE attendance_number IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX employees_national_id_live_unique ON employees (national_id) WHERE national_id IS NOT NULL AND deleted_at IS NULL');
    }

    private function qualificationColumns(): void
    {
        DB::statement('ALTER TABLE employee_qualifications ALTER COLUMN institution_name DROP NOT NULL');
        Schema::table('employee_qualifications', function (Blueprint $table): void {
            $table->foreignId('education_level_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('discipline_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->date('valid_until')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('verification_status', 20)->default('unverified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 1000)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
        });
        DB::statement("ALTER TABLE employee_qualifications ADD CONSTRAINT employee_qualifications_source_check CHECK (source IN ('manual','import'))");
        DB::statement("ALTER TABLE employee_qualifications ADD CONSTRAINT employee_qualifications_verification_check CHECK (verification_status IN ('unverified','verified','rejected'))");
    }

    private function importTables(): void
    {
        Schema::create('employee_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('staged');
            $table->string('file_name', 255);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('file_size');
            $table->string('sheet_name', 150)->nullable();
            $table->unsignedSmallInteger('header_row');
            $table->unsignedInteger('data_rows');
            $table->jsonb('headers');
            $table->jsonb('column_map');
            $table->jsonb('value_map')->nullable();
            $table->jsonb('options')->nullable();
            $table->jsonb('summary')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->text('revert_reason')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE employee_import_batches ADD CONSTRAINT employee_import_batches_status_check
            CHECK (status IN ('staged','validated','committing','committed','failed','cancelled','reverted'))");

        Schema::create('employee_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('employee_import_batches')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->char('fingerprint', 64);
            $table->text('raw_payload')->comment('مشفّر: قيم الخلايا كما وردت');
            $table->text('normalized_payload')->nullable()->comment('مشفّر: القيم بعد التوحيد');
            $table->jsonb('issues')->nullable()->comment('رموز المشكلات دون قيم شخصية');
            $table->string('status', 20)->default('pending');
            $table->string('match_type', 30)->nullable();
            $table->foreignId('matched_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('action', 20)->default('skip');
            $table->boolean('action_reviewed')->default(false);
            $table->jsonb('update_fields')->nullable();
            $table->string('result', 20)->default('pending');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->text('before_payload')->nullable()->comment('مشفّر: القيم السابقة للحقول المحدّثة');
            $table->jsonb('created_records')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'row_number']);
            $table->index(['batch_id', 'status']);
        });
        DB::statement("ALTER TABLE employee_import_rows ADD CONSTRAINT employee_import_rows_status_check CHECK (status IN ('pending','valid','warning','error'))");
        DB::statement("ALTER TABLE employee_import_rows ADD CONSTRAINT employee_import_rows_action_check CHECK (action IN ('insert','skip','update','reject'))");
        DB::statement("ALTER TABLE employee_import_rows ADD CONSTRAINT employee_import_rows_result_check
            CHECK (result IN ('pending','inserted','updated','skipped','rejected','failed','reverted','revert_blocked'))");

        Schema::create('employee_import_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('field_key', 50);
            $table->string('source_normalized', 255);
            $table->unsignedBigInteger('target_id')->nullable()->comment('فارغ = لا يُربط بقيمة (يُحفظ النص المصدر فقط)');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['field_key', 'source_normalized']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('import_batch_id')->references('id')->on('employee_import_batches')->restrictOnDelete();
        });
        Schema::table('employee_qualifications', function (Blueprint $table): void {
            $table->foreign('import_batch_id')->references('id')->on('employee_import_batches')->restrictOnDelete();
        });
    }

    private function payAndLeaveTables(): void
    {
        Schema::create('employee_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->restrictOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->restrictOnDelete();
            $table->string('department_source', 255)->nullable();
            $table->string('job_title_source', 255)->nullable();
            $table->string('grade_source', 100)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source', 20);
            $table->text('reason')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('employee_import_batches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE employee_placements ADD CONSTRAINT employee_placements_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement("ALTER TABLE employee_placements ADD CONSTRAINT employee_placements_source_check CHECK (source IN ('baseline','import','profile'))");
        DB::statement("ALTER TABLE employee_placements ADD CONSTRAINT employee_placements_no_overlap
            EXCLUDE USING gist (employee_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)");

        Schema::create('employee_compensations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('base_salary', 12, 2)->nullable();
            $table->decimal('spouse_allowance', 12, 2)->nullable();
            $table->decimal('children_allowance', 12, 2)->nullable();
            $table->decimal('cost_of_living_rate', 7, 4)->nullable()->comment('نسبة مئوية: 15 تعني 15%');
            $table->decimal('nature_of_work_rate', 7, 4)->nullable()->comment('نسبة مئوية: 15 تعني 15%');
            $table->decimal('source_total_salary', 12, 2)->nullable()->comment('جملة الراتب كما وردت في المصدر، لا تُحسب');
            $table->string('status', 20);
            $table->string('source', 20);
            $table->foreignId('import_batch_id')->nullable()->constrained('employee_import_batches')->restrictOnDelete();
            $table->unsignedBigInteger('pay_change_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_values_check CHECK (
            (base_salary IS NULL OR base_salary >= 0) AND (spouse_allowance IS NULL OR spouse_allowance >= 0) AND (children_allowance IS NULL OR children_allowance >= 0)
            AND (source_total_salary IS NULL OR source_total_salary >= 0)
            AND (cost_of_living_rate IS NULL OR cost_of_living_rate BETWEEN 0 AND 100) AND (nature_of_work_rate IS NULL OR nature_of_work_rate BETWEEN 0 AND 100)
            AND (effective_to IS NULL OR effective_to >= effective_from))');
        DB::statement("ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_status_check CHECK (status IN ('baseline','approved') AND source IN ('import','request'))");
        DB::statement("ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_no_overlap
            EXCLUDE USING gist (employee_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)");

        Schema::create('employee_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('bank_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->string('bank_source', 150)->nullable();
            $table->text('account_number')->nullable()->comment('مشفّر');
            $table->char('account_hash', 64)->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20);
            $table->string('source', 20);
            $table->foreignId('import_batch_id')->nullable()->constrained('employee_import_batches')->restrictOnDelete();
            $table->unsignedBigInteger('pay_change_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('account_hash');
        });
        DB::statement("ALTER TABLE employee_bank_accounts ADD CONSTRAINT employee_bank_accounts_status_check CHECK (status IN ('baseline','approved') AND source IN ('import','request')
            AND (effective_to IS NULL OR effective_to >= effective_from))");
        DB::statement("ALTER TABLE employee_bank_accounts ADD CONSTRAINT employee_bank_accounts_no_overlap
            EXCLUDE USING gist (employee_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)");

        Schema::create('employee_pay_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->string('status', 20)->default('in_review');
            $table->date('effective_from');
            $table->boolean('changes_pay')->default(false);
            $table->boolean('changes_bank')->default(false);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('base_salary', 12, 2)->nullable();
            $table->decimal('spouse_allowance', 12, 2)->nullable();
            $table->decimal('children_allowance', 12, 2)->nullable();
            $table->decimal('cost_of_living_rate', 7, 4)->nullable();
            $table->decimal('nature_of_work_rate', 7, 4)->nullable();
            $table->foreignId('bank_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->text('account_number')->nullable()->comment('مشفّر');
            $table->char('account_hash', 64)->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->text('reason');
            $table->string('reference', 255);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE employee_pay_changes ADD CONSTRAINT employee_pay_changes_status_check CHECK (status IN ('in_review','returned','approved','rejected','withdrawn')
            AND (changes_pay OR changes_bank)
            AND (base_salary IS NULL OR base_salary >= 0) AND (spouse_allowance IS NULL OR spouse_allowance >= 0) AND (children_allowance IS NULL OR children_allowance >= 0)
            AND (cost_of_living_rate IS NULL OR cost_of_living_rate BETWEEN 0 AND 100) AND (nature_of_work_rate IS NULL OR nature_of_work_rate BETWEEN 0 AND 100))");
        DB::statement("CREATE UNIQUE INDEX employee_pay_changes_open_unique ON employee_pay_changes (employee_id) WHERE status IN ('in_review','returned')");
        foreach (['employee_compensations', 'employee_bank_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('pay_change_id')->references('id')->on('employee_pay_changes')->restrictOnDelete());
        }

        Schema::create('employee_leave_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->date('as_of_date');
            $table->decimal('annual_entitlement_days', 8, 2)->nullable();
            $table->decimal('carried_over_days', 8, 2)->nullable();
            $table->decimal('accrued_days', 8, 2)->nullable();
            $table->decimal('used_days', 8, 2)->nullable();
            $table->decimal('balance_days', 8, 2)->nullable();
            $table->string('consistency', 20);
            $table->foreignId('opening_balance_id')->nullable()->constrained('employee_leave_opening_balances')->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('employee_import_batches')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_value_id', 'as_of_date'], 'employee_leave_baselines_unique');
        });
        DB::statement("ALTER TABLE employee_leave_baselines ADD CONSTRAINT employee_leave_baselines_check CHECK (consistency IN ('consistent','inconsistent','incomplete')
            AND (annual_entitlement_days IS NULL OR annual_entitlement_days >= 0) AND (carried_over_days IS NULL OR carried_over_days >= 0)
            AND (accrued_days IS NULL OR accrued_days >= 0) AND (used_days IS NULL OR used_days >= 0))");

        // The current placement of each existing profile starts the history, so later transfers never overwrite it.
        foreach (DB::table('employees')->whereNull('deleted_at')->get(['id', 'department_id', 'job_title_id', 'job_grade_id', 'work_start_date', 'hire_date', 'created_at']) as $employee) {
            DB::table('employee_placements')->insert([
                'employee_id' => $employee->id, 'department_id' => $employee->department_id, 'job_title_id' => $employee->job_title_id, 'job_grade_id' => $employee->job_grade_id,
                'effective_from' => $employee->work_start_date ?? $employee->hire_date ?? substr((string) $employee->created_at, 0, 10),
                'source' => 'baseline', 'reason' => 'بداية سجل التنقلات من الملف القائم', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function fileTables(): void
    {
        Schema::create('hr_employee_file_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('photo_max_kb')->default(2048);
            $table->unsignedSmallInteger('photo_min_px')->default(200);
            $table->unsignedSmallInteger('photo_max_px')->default(6000);
            $table->unsignedInteger('document_max_kb')->default(10240);
            $table->unsignedInteger('employee_quota_mb')->default(200);
            $table->string('self_photo_upload', 20)->default('review');
            $table->boolean('self_view_pay')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE hr_employee_file_settings ADD CONSTRAINT hr_employee_file_settings_check CHECK (id = 1 AND self_photo_upload IN ('disabled','review')
            AND photo_max_kb BETWEEN 50 AND 20480 AND document_max_kb BETWEEN 100 AND 51200 AND photo_min_px BETWEEN 50 AND 2000 AND photo_max_px BETWEEN 500 AND 12000)");
        DB::table('hr_employee_file_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('employee_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('original_path', 255);
            $table->string('display_path', 255);
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64);
            $table->string('status', 20);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE employee_photos ADD CONSTRAINT employee_photos_status_check CHECK (status IN ('current','pending_review','replaced','removed','rejected'))");
        DB::statement("CREATE UNIQUE INDEX employee_photos_current_unique ON employee_photos (employee_id) WHERE status = 'current'");
        DB::statement("CREATE UNIQUE INDEX employee_photos_pending_unique ON employee_photos (employee_id) WHERE status = 'pending_review'");

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('document_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('qualification_id')->nullable()->constrained('employee_qualifications')->restrictOnDelete();
            $table->string('issuer', 255)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('classification', 20);
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 1000)->nullable();
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('employee_documents')->restrictOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('scan_status', 20)->default('not_scanned');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('archive_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'is_current']);
        });
        DB::statement("ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_check CHECK (classification IN ('normal','confidential','identity')
            AND verification_status IN ('pending','verified','rejected') AND scan_status IN ('not_scanned','clean','infected')
            AND (expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on))");
    }

    /** Reference lists the workbook or the prompt names. Admins maintain them in «ثوابت النظام». */
    private function constants(): void
    {
        $group = fn (string $code): int => (int) DB::table('master_data_groups')->where('code', $code)->value('id')
            ?: throw new RuntimeException("مجموعة الثوابت {$code} غير موجودة.");
        $type = function (string $groupCode, string $code, string $name, int $order) use ($group): int {
            return (int) (DB::table('master_data_types')->where('code', $code)->value('id') ?: DB::table('master_data_types')->insertGetId([
                'group_id' => $group($groupCode), 'code' => $code, 'name_ar' => $name, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]));
        };
        $values = function (int $typeId, array $rows): void {
            foreach ($rows as $index => $row) {
                [$code, $name, $metadata] = [$row[0], $row[1], $row[2] ?? null];
                if (! DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->exists()) {
                    DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $code, 'name_ar' => $name, 'sort_order' => ($index + 1) * 10,
                        'metadata' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        };

        // classification: normal = education and training records; confidential = contracts and letters; identity = identity documents.
        $values($type('hr', 'employee_document_types', 'أنواع وثائق الموظفين', 200), [
            ['education_certificate', 'شهادة علمية', ['classification' => 'normal', 'qualification' => true, 'mandatory' => false]],
            ['transcript', 'كشف علامات', ['classification' => 'normal', 'qualification' => true, 'mandatory' => false]],
            ['equivalence', 'معادلة شهادة', ['classification' => 'normal', 'qualification' => true, 'mandatory' => false]],
            ['professional_license', 'ترخيص مهني', ['classification' => 'normal', 'qualification' => true, 'mandatory' => false]],
            ['training_certificate', 'شهادة دورة تدريبية', ['classification' => 'normal', 'qualification' => false, 'mandatory' => false]],
            ['experience_certificate', 'شهادة خبرة', ['classification' => 'normal', 'qualification' => false, 'mandatory' => false]],
            ['cv', 'سيرة ذاتية', ['classification' => 'confidential', 'qualification' => false, 'mandatory' => false]],
            ['employment_contract', 'عقد عمل', ['classification' => 'confidential', 'qualification' => false, 'mandatory' => false]],
            ['appointment_letter', 'كتاب تعيين', ['classification' => 'confidential', 'qualification' => false, 'mandatory' => false]],
            ['identity_document', 'وثيقة هوية', ['classification' => 'identity', 'qualification' => false, 'mandatory' => false]],
            ['other', 'وثيقة أخرى', ['classification' => 'confidential', 'qualification' => false, 'mandatory' => false]],
        ]);
        $values($type('education', 'education_levels', 'المستويات التعليمية', 100), [
            ['primary', 'ابتدائي'], ['preparatory', 'إعدادي'], ['secondary', 'ثانوي'], ['diploma', 'دبلوم'],
            ['bachelor', 'بكالوريوس'], ['master', 'ماجستير'], ['doctorate', 'دكتوراه'],
        ]);
        $type('education', 'academic_disciplines', 'التخصصات العلمية', 110);
        $values($type('money', 'banks', 'البنوك والمحافظ', 100), [
            ['palestine_islamic_bank', 'البنك الإسلامي الفلسطيني'], ['bank_of_palestine', 'بنك فلسطين'], ['palpay', 'بال باي (محفظة إلكترونية)'],
        ]);
        $values((int) DB::table('master_data_types')->where('code', 'marital_statuses')->value('id'), [['widowed', 'أرمل/ة'], ['divorced', 'مطلق/ة']]);
        $values((int) DB::table('master_data_types')->where('code', 'employment_terms')->value('id'), [['contract_employee', 'موظف بعقد']]);
    }

    private function access(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        // The executive director decides pay changes, so sees pay; the HR head holds all three through headship (User::DEPARTMENT_HEAD_PERMISSIONS).
        $director = DB::table('roles')->where('code', 'executive_director')->value('id');
        $payView = DB::table('permissions')->where('code', 'hr.employees.pay.view')->value('id');
        if ($director && $payView) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $payView, 'role_id' => $director]);
        }
        DB::table('notification_settings')->insertOrIgnore(['event_code' => 'employee_pay_change_decided', 'label_ar' => 'قرار تعديل بيانات الراتب أو الحساب البنكي',
            'description' => 'لمقدم الطلب في الموارد البشرية وللموظف المعني عند اعتماد التعديل أو رفضه، دون ذكر المبالغ.',
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function workflow(): void
    {
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => 'EMPLOYEE_PAY_CHANGE', 'subject_type' => 'employee_pay_change', 'name' => 'تعديل راتب موظف أو حسابه البنكي',
            'description' => 'طلب الموارد البشرية مع المستند المرجعي، ثم قرار المدير التنفيذي. لا يسري التعديل قبل الاعتماد، ولا يُعد قيدًا محاسبيًا.',
            'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => 'EPC', 'is_confidential' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $steps = [
            ['hr', 'طلب التعديل مع المستند المرجعي (الموارد البشرية)', 10, 'start', 'requester', null, null],
            ['ed_decision', 'قرار المدير التنفيذي', 20, 'decision', 'role', 'executive_director', ['department_scope' => 'any']],
            ['approved', 'اعتماد التعديل وسريانه من التاريخ المحدد', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض التعديل', 910, 'end', null, null, ['outcome' => 'rejected']],
        ];
        $ids = [];
        foreach ($steps as [$code, $name, $order, $stepType, $assignment, $role, $config]) {
            $ids[$code] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $code, 'name' => $name, 'sort_order' => $order, 'step_type' => $stepType,
                'assignment_type' => $assignment, 'assigned_role_code' => $role, 'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([
            ['hr', 'ed_decision', 'submit', 'رفع الطلب إلى المدير التنفيذي', false],
            ['ed_decision', 'approved', 'approve', 'اعتماد التعديل', false],
            ['ed_decision', 'hr', 'return', 'إعادة إلى الموارد البشرية (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض التعديل (اذكر السبب)', true],
        ] as [$from, $to, $action, $name, $comment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $action, 'name' => $name,
                'conditions' => null, 'requires_comment' => $comment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException('تعذر نشر مسار EMPLOYEE_PAY_CHANGE: '.implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => 'EMPLOYEE_PAY_CHANGE', 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function navigation(): void
    {
        $hr = DB::table('navigation_items')->where('code', 'hr_group')->value('id');
        if (! $hr) {
            throw new RuntimeException('مجموعة الموارد البشرية غير موجودة في القائمة.');
        }
        $now = now();
        DB::table('navigation_items')->insert([
            ['code' => 'employee_imports', 'parent_id' => $hr, 'label_ar' => 'استيراد الموظفين', 'label_en' => 'Employee import', 'icon' => 'list', 'route_name' => 'admin.employee-imports.index',
                'route_params' => null, 'access_rule' => 'manage_employees', 'sort_order' => 25, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'my_profile', 'parent_id' => null, 'label_ar' => 'ملفي الوظيفي', 'label_en' => 'My employee file', 'icon' => 'users', 'route_name' => 'my.profile',
                'route_params' => null, 'access_rule' => 'employee_profile', 'sort_order' => 26, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        if (DB::table('employee_placements')->where('source', '<>', 'baseline')->exists()) {
            throw new RuntimeException('سجل التنقلات يحتوي حركات؛ لا يمكن التراجع.');
        }
        $definition = DB::table('workflow_definitions')->where('code', 'EMPLOYEE_PAY_CHANGE')->value('id');
        $versions = DB::table('workflow_versions')->where('workflow_definition_id', $definition)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
            throw new RuntimeException('توجد طلبات تعديل رواتب على المحرك؛ لا يمكن التراجع.');
        }
        DB::table('navigation_items')->whereIn('code', ['employee_imports', 'my_profile'])->delete();
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_versions')->whereIn('id', $versions)->delete();
        DB::table('workflow_definitions')->where('id', $definition)->delete();
        DB::table('notification_settings')->where('event_code', 'employee_pay_change_decided')->delete();
        $permissions = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissions)->delete();
        DB::table('permissions')->whereIn('id', $permissions)->delete();

        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_photos');
        Schema::dropIfExists('hr_employee_file_settings');
        Schema::dropIfExists('employee_leave_baselines');
        foreach (['employee_compensations', 'employee_bank_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['pay_change_id']));
        }
        Schema::dropIfExists('employee_pay_changes');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('employee_placements');
        Schema::table('employee_qualifications', function (Blueprint $table): void {
            $table->dropForeign(['import_batch_id']);
            $table->dropConstrainedForeignId('education_level_value_id');
            $table->dropConstrainedForeignId('discipline_value_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['valid_until', 'source', 'verification_status', 'verified_at', 'verification_note', 'import_batch_id']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['import_batch_id']);
        });
        Schema::dropIfExists('employee_import_aliases');
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_import_batches');
        DB::statement('DROP INDEX IF EXISTS employees_attendance_number_live_unique');
        DB::statement('DROP INDEX IF EXISTS employees_national_id_live_unique');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_name_review_status_check');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_children_count_check');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['full_name_ar', 'original_family_name_ar', 'name_review_status', 'children_count', 'landline_phone',
            'residence_address', 'attendance_number', 'job_title_source', 'department_source', 'grade_source', 'import_batch_id']));
        if (! DB::table('employee_qualifications')->whereNull('institution_name')->exists()) {
            DB::statement('ALTER TABLE employee_qualifications ALTER COLUMN institution_name SET NOT NULL');
        }
        foreach (['employee_document_types', 'education_levels', 'academic_disciplines', 'banks'] as $code) {
            $typeId = DB::table('master_data_types')->where('code', $code)->value('id');
            DB::table('master_data_values')->where('type_id', $typeId)->delete();
            DB::table('master_data_types')->where('id', $typeId)->delete();
        }
        DB::table('master_data_values')->whereIn('code', ['widowed', 'divorced', 'contract_employee'])
            ->whereIn('type_id', DB::table('master_data_types')->whereIn('code', ['marital_statuses', 'employment_terms'])->pluck('id'))->delete();
    }
};
