<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the assets, buildings and rentals file: the approved rentals requirements document and the owner's
 * master prompt, with the owner's decisions of 2026-10-23 (the assets department is the rentals department;
 * collections and postings are made in the external accounting software and Accounts records their references).
 *
 * Additive only. Statuses get CHECK constraints, one unit cannot be occupied by two contracts over overlapping
 * dates (exclusion constraint), external receipts cannot be recorded twice, and the four request types run on
 * the central workflow engine. It refuses to run when the rentals tables already hold records, because it
 * tightens their statuses; they were empty when it was written.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_17_000001 (استكمال ملف الأصول والمباني والإيجارات)';

    private const GUARDED = ['aims_properties', 'aims_property_units', 'aims_rental_tenants', 'aims_rental_applications', 'aims_rental_contracts',
        'aims_rental_contract_units', 'aims_rental_dues', 'aims_rental_cases', 'aims_rental_meter_readings', 'aims_rental_notices', 'aims_rental_receipt_allocations'];

    private const DEFINITIONS = ['RENTAL_APPLICATION', 'RENTAL_CONTRACT', 'RENTAL_TERMINATION', 'RENTAL_SETTLEMENT'];

    public function up(): void
    {
        foreach (self::GUARDED as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ يحتاج هذا الترحيل خطة تحويل للبيانات القائمة قبل تطبيقه.");
            }
        }

        $this->schema();
        $this->constraints();
        $this->constants();
        $this->access();
        $this->workflows();

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'rentals.module.completed', 'subject_type' => 'aims_rental_contracts', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['workflows' => self::DEFINITIONS, 'permission' => 'finance.rentals.record',
                'tables' => ['rentals_module_settings', 'aims_property_unit_status_events', 'aims_rental_settlements', 'aims_rental_contract_services', 'aims_rental_documents', 'aims_rental_maintenance_links']],
                'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('rentals_module_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('renewal_alert_days')->default(90);
            $table->unsignedSmallInteger('overdue_alert_days')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('rentals_module_settings')->insert(['id' => 1, 'renewal_alert_days' => 90, 'overdue_alert_days' => 0, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('aims_properties', function (Blueprint $table): void {
            $table->string('plot_number', 50)->nullable();
            $table->string('parcel_number', 50)->nullable();
        });
        Schema::table('aims_property_units', function (Blueprint $table): void {
            $table->string('directions', 255)->nullable();
            $table->string('availability', 32)->default('draft')->change();
        });
        Schema::create('aims_property_unit_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('aims_property_units')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->string('source', 30);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['unit_id', 'id']);
        });

        Schema::table('aims_rental_tenants', function (Blueprint $table): void {
            $table->string('phone2', 60)->nullable();
        });
        Schema::table('aims_rental_applications', function (Blueprint $table): void {
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('reserved_at')->nullable();
        });
        Schema::table('aims_rental_contracts', function (Blueprint $table): void {
            $table->string('kind', 20)->default('new');
            $table->date('issued_on')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->date('terminated_on')->nullable();
            $table->foreignId('termination_reason_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('aims_rental_contract_units', function (Blueprint $table): void {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('occupies')->default(false);
            $table->decimal('rent_per_m2', 18, 2)->nullable();
        });
        Schema::table('aims_rental_dues', function (Blueprint $table): void {
            $table->string('kind', 20)->default('rent');
            $table->foreignId('service_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });
        Schema::table('aims_rental_receipt_allocations', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('external_system', 120)->nullable();
            $table->foreignId('payment_method_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->string('reversal_reference', 150)->nullable();
        });
        Schema::create('aims_rental_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('aims_rental_contracts')->restrictOnDelete();
            $table->foreignId('due_id')->nullable()->constrained('aims_rental_dues')->restrictOnDelete();
            $table->foreignId('type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->string('effect', 10);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10);
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('posting_reference', 150)->nullable();
            $table->date('posting_date')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('contract_id');
            $table->index('due_id');
        });
        Schema::table('aims_rental_cases', function (Blueprint $table): void {
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->date('departure_on')->nullable();
            $table->foreignId('termination_reason_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->string('outcome_unit_status', 32)->nullable();
            $table->text('damages')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('aims_rental_contract_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('aims_rental_contracts')->restrictOnDelete();
            $table->foreignId('service_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->string('billing', 12);
            $table->decimal('amount', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'service_value_id']);
        });
        Schema::create('aims_rental_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 30);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('document_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('aims_rental_documents')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
        Schema::table('aims_rental_meter_readings', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('purpose', 20)->default('periodic');
            $table->foreignId('case_id')->nullable()->constrained('aims_rental_cases')->restrictOnDelete();
        });
        Schema::create('aims_rental_maintenance_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_instance_id')->unique()->constrained('workflow_instances')->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('aims_properties')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('aims_property_units')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('aims_rental_contracts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('aims_rental_notices', function (Blueprint $table): void {
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    private function constraints(): void
    {
        $in = fn (array $values): string => "'".implode("','", $values)."'";
        foreach ([
            ['rentals_module_settings', 'rentals_module_settings_single', 'id = 1 AND renewal_alert_days BETWEEN 1 AND 365 AND overdue_alert_days BETWEEN 0 AND 90'],
            ['aims_property_units', 'aims_property_units_availability_check', 'availability IN ('.$in(['draft', 'vacant', 'reserved', 'rented', 'maintenance', 'suspended', 'out_of_service']).')'],
            ['aims_property_units', 'aims_property_units_area_check', '(area_m2 IS NULL OR area_m2 >= 0) AND (mezzanine_area_m2 IS NULL OR mezzanine_area_m2 >= 0)'],
            ['aims_rental_tenants', 'aims_rental_tenants_type_check', 'type IN ('.$in(['person', 'company', 'association', 'institution']).')'],
            ['aims_rental_applications', 'aims_rental_applications_status_check', 'status IN ('.$in(['draft', 'in_review', 'returned', 'waitlisted', 'approved', 'rejected', 'cancelled', 'contracted']).')'],
            ['aims_rental_contracts', 'aims_rental_contracts_status_check', 'status IN ('.$in(['draft', 'pending_approval', 'returned', 'approved', 'active', 'expired', 'renewed', 'superseded', 'terminated', 'rejected', 'cancelled']).')'],
            ['aims_rental_contracts', 'aims_rental_contracts_kind_check', 'kind IN ('.$in(['new', 'renewal', 'amendment']).') AND (kind = '."'new'".' OR parent_contract_id IS NOT NULL)'],
            ['aims_rental_contracts', 'aims_rental_contracts_frequency_check', 'frequency IN ('.$in(['monthly', 'quarterly', 'semiannual', 'annual', 'custom']).')'],
            ['aims_rental_contracts', 'aims_rental_contracts_dates_check', 'ends_on >= starts_on AND total_rent > 0 AND (terminated_on IS NULL OR terminated_on BETWEEN starts_on AND ends_on)'],
            ['aims_rental_contract_units', 'aims_rental_contract_units_occupancy_check', 'rent_share > 0 AND (NOT occupies OR (starts_on IS NOT NULL AND ends_on IS NOT NULL AND ends_on >= starts_on))'],
            ['aims_rental_dues', 'aims_rental_dues_status_check', 'status IN ('.$in(['open', 'closed', 'cancelled']).') AND kind IN ('.$in(['rent', 'service']).') AND amount > 0 AND (kind = '."'rent'".' OR service_value_id IS NOT NULL)'],
            ['aims_rental_receipt_allocations', 'aims_rental_receipt_allocations_amount_check', 'amount > 0'],
            ['aims_rental_settlements', 'aims_rental_settlements_check', 'amount > 0 AND effect IN ('.$in(['reduce', 'increase']).') AND status IN ('.$in(['draft', 'in_review', 'returned', 'approved', 'rejected', 'posted', 'cancelled']).')'],
            ['aims_rental_cases', 'aims_rental_cases_status_check', 'status IN ('.$in(['draft', 'in_review', 'returned', 'approved', 'rejected', 'completed', 'cancelled']).') AND kind IN ('.$in(['renewal', 'adjustment', 'settlement', 'eviction', 'maintenance', 'inspection']).')'],
            ['aims_rental_cases', 'aims_rental_cases_outcome_check', 'outcome_unit_status IS NULL OR outcome_unit_status IN ('.$in(['vacant', 'maintenance', 'reserved', 'suspended']).')'],
            ['aims_rental_contract_services', 'aims_rental_contract_services_billing_check', "billing IN ('included','separate') AND (billing = 'included' OR amount > 0)"],
            ['aims_rental_documents', 'aims_rental_documents_owner_check', 'owner_type IN ('.$in(['property', 'unit', 'tenant', 'application', 'contract', 'case', 'settlement', 'reading']).') AND size_bytes > 0 AND version >= 1'],
            ['aims_rental_meter_readings', 'aims_rental_meter_readings_purpose_check', 'purpose IN ('.$in(['handover', 'periodic', 'eviction']).') AND reading >= 0'],
            ['aims_rental_notices', 'aims_rental_notices_status_check', 'status IN ('.$in(['draft', 'issued', 'cancelled']).')'],
        ] as [$table, $name, $check]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        // One unit, one occupying contract at a time: enforced by the database, whatever path writes the rows.
        DB::statement("ALTER TABLE aims_rental_contract_units ADD CONSTRAINT aims_rental_contract_units_no_overlap
            EXCLUDE USING gist (unit_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&) WHERE (occupies)");
        DB::statement('ALTER TABLE aims_rental_dues DROP CONSTRAINT aims_rental_dues_contract_id_due_on_unique');
        DB::statement('CREATE UNIQUE INDEX aims_rental_dues_item_unique ON aims_rental_dues (contract_id, due_on, kind, COALESCE(service_value_id, 0))');
        DB::statement('ALTER TABLE aims_rental_receipt_allocations DROP CONSTRAINT aims_rental_receipt_allocations_accounting_reference_due_id_uni');
        DB::statement('CREATE UNIQUE INDEX aims_rental_receipts_live_unique ON aims_rental_receipt_allocations (accounting_reference, due_id) WHERE reversed_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX aims_rental_tenants_identity_unique ON aims_rental_tenants (type, lower(btrim(identity_number)))
            WHERE identity_number IS NOT NULL AND btrim(identity_number) <> ''");
        DB::statement("CREATE UNIQUE INDEX aims_rental_contracts_open_successor ON aims_rental_contracts (parent_contract_id)
            WHERE parent_contract_id IS NOT NULL AND status IN ('draft','pending_approval','returned','approved')");
        DB::statement('CREATE INDEX aims_rental_dues_open_idx ON aims_rental_dues (status, due_on)');
    }

    /** Reference lists. Values are seeded only where the approved document or the owner's prompt names them. */
    private function constants(): void
    {
        $group = (int) DB::table('master_data_groups')->where('code', 'assets')->value('id');
        if ($group <= 0) {
            throw new RuntimeException('مجموعة «ثوابت الأصول والإيجارات» غير موجودة.');
        }
        foreach ([
            ['asset_types', 'أنواع الأصول', [['land', 'أراضٍ'], ['building', 'مبانٍ'], ['operational_facility', 'مرافق عقارية تشغيلية'], ['investment_facility', 'مرافق عقارية استثمارية']]],
            ['property_ownership_types', 'أنواع ملكية الأصول', []],
            ['rental_unit_types', 'أنواع الأعيان المؤجرة', []],
            ['rental_unit_usages', 'استخدامات الأعيان', []],
            ['rental_services', 'خدمات الإيجار', [['electricity', 'الكهرباء'], ['internet', 'الإنترنت'], ['billboard', 'اللوحة الإعلانية'], ['roof_space', 'تأجير مساحة على السطح']]],
            ['rental_document_types', 'مستندات ملف الإيجار', [['identity', 'الهوية'], ['registration', 'تسجيل المؤسسة'], ['authorization', 'التفويض'], ['references', 'المراجع'],
                ['plans', 'المخططات'], ['signed_contract', 'العقد الموقع'], ['handover_record', 'محضر التسليم'], ['eviction_record', 'محضر الاستلام والإخلاء'],
                ['inspection_record', 'محضر الفحص'], ['meter_photo', 'صور العداد'], ['clearance', 'المخالصة'], ['other', 'مستند آخر']]],
            ['rental_settlement_types', 'أنواع تسويات الإيجار', [['payment_error', 'تصحيح خطأ دفع'], ['amount_difference', 'فرق مبلغ'], ['overpayment', 'زيادة سداد / رصيد دائن'],
                ['reallocation', 'إعادة توزيع دفعة'], ['discount', 'خصم أو إعفاء معتمد'], ['opening_balance', 'تسوية رصيد افتتاحي'], ['due_adjustment', 'مديونية / تعديل استحقاق']]],
            ['rental_termination_reasons', 'أسباب إنهاء عقد الإيجار', [['non_renewal', 'عدم التجديد عند انتهاء المدة'], ['tenant_request', 'طلب المستأجر الإخلاء']]],
        ] as $order => [$code, $name, $values]) {
            $typeId = DB::table('master_data_types')->where('code', $code)->value('id') ?: DB::table('master_data_types')->insertGetId([
                'group_id' => $group, 'code' => $code, 'name_ar' => $name, 'sort_order' => 100 + $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($values as $index => [$valueCode, $valueName]) {
                if (! DB::table('master_data_values')->where('type_id', $typeId)->where('code', $valueCode)->exists()) {
                    DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $valueCode, 'name_ar' => $valueName, 'sort_order' => ($index + 1) * 10,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    private function access(): void
    {
        $permission = DB::table('permissions')->insertGetId(['code' => 'finance.rentals.record',
            'name' => 'تسجيل قبض الإيجارات وترحيل تسوياتها من البرنامج المحاسبي', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->whereIn('code', ['finance_accountant', 'finance_head'])->pluck('id') as $role) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]);
        }
        foreach ([
            ['rental_contract_expiring', 'اقتراب انتهاء عقد إيجار', 'لرئيس قسم الأصول ومسؤولي ملف الإيجارات عند دخول العقد فترة التنبيه المهيأة (افتراضيًا ثلاثة أشهر)، مرة واحدة لكل عقد.'],
            ['rental_contract_expired', 'انتهاء مدة عقد إيجار', 'عند انتهاء مدة العقد دون تجديد مفعّل؛ تبقى العين مشغولة حتى محضر الاستلام.'],
            ['rental_due_overdue', 'استحقاق إيجار متأخر', 'لمسؤولي ملف الإيجارات عند تجاوز الاستحقاق موعده دون قبض مسجل من الحسابات، مرة واحدة لكل استحقاق.'],
        ] as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore(['event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function workflows(): void
    {
        $finance = (int) DB::table('departments')->where('code', 'FIN')->value('id');
        $hr = (int) DB::table('departments')->where('code', 'HR')->value('id');
        if ($finance <= 0 || $hr <= 0) {
            throw new RuntimeException('قسما الحسابات والموارد البشرية مطلوبان لمسارات الإيجار.');
        }
        $any = ['department_scope' => 'any'];
        $ed = $any;
        $accounts = [...$any, 'fallback_department_id' => $finance];
        $services = ['department_id' => $hr, 'fallback_role_code' => 'executive_director'];

        $this->publish('RENTAL_APPLICATION', 'rental_application', 'طلب استئجار', 'دراسة مسؤول الملف ثم قرار المدير التنفيذي: قبول وتخصيص، أو استكمال، أو قائمة انتظار، أو رفض مسبب.', 'RAP', [
            ['officer', 'دراسة الطلب والتوصية (مسؤول الملف)', 10, 'start', 'requester', null, null],
            ['ed_decision', 'قرار المدير التنفيذي', 20, 'decision', 'role', 'executive_director', $ed],
            ['waitlist', 'قائمة الانتظار (مسؤول الملف)', 30, 'task', 'requester', null, null],
            ['approved', 'قبول الطلب وتخصيص العين', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
            ['withdrawn', 'إغلاق الطلب دون تعاقد', 920, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['officer', 'ed_decision', 'submit', 'رفع الدراسة والتوصية إلى المدير التنفيذي', false],
            ['ed_decision', 'approved', 'approve', 'قبول الطلب', false],
            ['ed_decision', 'officer', 'return', 'طلب استكمال من مسؤول الملف', true],
            ['ed_decision', 'waitlist', 'waitlist', 'وضع الطلب في قائمة الانتظار (اذكر السبب)', true],
            ['ed_decision', 'rejected', 'reject', 'رفض الطلب (اذكر السبب)', true],
            ['waitlist', 'ed_decision', 'resubmit', 'إعادة الرفع عند توفر عين مناسبة', false],
            ['waitlist', 'withdrawn', 'withdraw', 'إغلاق الطلب (انسحاب المستأجر أو انتهاء الحاجة)', true],
        ]);

        $this->publish('RENTAL_CONTRACT', 'rental_contract', 'عقد إيجار', 'العقد الجديد والتجديد والملحق: إعداد مسؤول الملف، ثم اعتماد المدير التنفيذي، ثم التوقيع والتسليم والتفعيل.', 'RCT', [
            ['officer', 'إعداد العقد ورفعه للاعتماد (مسؤول الملف)', 10, 'start', 'requester', null, null],
            ['ed_approval', 'اعتماد المدير التنفيذي', 20, 'decision', 'role', 'executive_director', $ed],
            ['signing', 'التوقيع والتسليم: النسخة الموقعة وقراءة العداد ثم التفعيل', 30, 'task', 'requester', null, null],
            ['active', 'تفعيل العقد', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض العقد', 910, 'end', null, null, ['outcome' => 'rejected']],
            ['cancelled', 'إلغاء العقد قبل التفعيل', 920, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['officer', 'ed_approval', 'submit', 'رفع العقد إلى المدير التنفيذي', false],
            ['ed_approval', 'signing', 'approve', 'اعتماد العقد', false],
            ['ed_approval', 'officer', 'return', 'إعادة لمسؤول الملف للتعديل', true],
            ['ed_approval', 'rejected', 'reject', 'رفض العقد (اذكر السبب)', true],
            ['signing', 'active', 'activate', 'تفعيل العقد بعد التوقيع والتسليم', false],
            ['signing', 'cancelled', 'cancel', 'إلغاء العقد قبل التفعيل (اذكر السبب)', true],
        ]);

        $this->publish('RENTAL_TERMINATION', 'rental_case', 'إنهاء عقد إيجار وإخلاء', 'طلب الإنهاء أو الإخلاء: فحص العين والعدادات، ثم مخالصة الحسابات، ثم قرار المدير التنفيذي، ثم محضر الاستلام وإعادة حالة العين.', 'RTM', [
            ['officer', 'طلب الإنهاء وتحديد موعد المغادرة (مسؤول الملف)', 10, 'start', 'requester', null, null],
            ['inspection', 'فحص العين والعدادات (الخدمات العامة والصيانة)', 20, 'task', 'department_head_of', null, $services],
            ['clearance', 'مخالصة الحسابات وتسجيل المتأخرات', 30, 'task', 'role', 'finance_accountant', $accounts],
            ['ed_decision', 'قرار المدير التنفيذي بالإنهاء', 40, 'decision', 'role', 'executive_director', $ed],
            ['handover', 'محضر الاستلام وإعادة حالة العين (مسؤول الملف)', 50, 'task', 'requester', null, null],
            ['completed', 'إنهاء العقد وإغلاق الملف', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الإنهاء', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['officer', 'inspection', 'submit', 'إحالة للفحص', false],
            ['inspection', 'clearance', 'inspected', 'تم الفحص (اذكر حالة العين والعدادات والأضرار)', true],
            ['inspection', 'officer', 'return', 'إعادة لمسؤول الملف', true],
            ['clearance', 'ed_decision', 'cleared', 'تمت المخالصة أو سُجلت المتأخرات (اذكر النتيجة)', true],
            ['clearance', 'officer', 'return', 'إعادة لمسؤول الملف', true],
            ['ed_decision', 'handover', 'approve', 'اعتماد الإنهاء', false],
            ['ed_decision', 'officer', 'return', 'إعادة لمسؤول الملف', true],
            ['ed_decision', 'rejected', 'reject', 'رفض الإنهاء (اذكر السبب)', true],
            ['handover', 'completed', 'complete', 'إتمام الاستلام وإعادة حالة العين', false],
        ]);

        $this->publish('RENTAL_SETTLEMENT', 'rental_settlement', 'تسوية إيجار', 'إعفاء أو خصم أو تصحيح: طلب مسؤول الملف، ثم مراجعة الحسابات، ثم قرار المدير التنفيذي، ثم تسجيل الترحيل من البرنامج المحاسبي.', 'RST', [
            ['officer', 'طلب التسوية مع المستندات (مسؤول الملف)', 10, 'start', 'requester', null, null],
            ['finance_review', 'مراجعة الحسابات والتوصية', 20, 'task', 'role', 'finance_accountant', $accounts],
            ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed],
            ['posting', 'تسجيل الترحيل من البرنامج المحاسبي (الحسابات)', 40, 'task', 'role', 'finance_accountant', $accounts],
            ['completed', 'ترحيل التسوية وانعكاسها على الرصيد', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض التسوية', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['officer', 'finance_review', 'submit', 'إرسال إلى الحسابات للمراجعة', false],
            ['finance_review', 'ed_decision', 'recommend', 'رفع التوصية إلى المدير التنفيذي (اكتب التوصية والأثر المالي)', true],
            ['finance_review', 'officer', 'return', 'إعادة لمسؤول الملف للاستكمال', true],
            ['ed_decision', 'posting', 'approve', 'اعتماد التسوية وإحالتها للترحيل', false],
            ['ed_decision', 'finance_review', 'return', 'إعادة إلى الحسابات', true],
            ['ed_decision', 'rejected', 'reject', 'رفض التسوية (اذكر السبب)', true],
            ['posting', 'completed', 'post', 'تم الترحيل في البرنامج المحاسبي', false],
            ['posting', 'ed_decision', 'return', 'تعذر الترحيل: إعادة إلى المدير التنفيذي', true],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>  $transitions
     */
    private function publish(string $code, string $subject, string $name, string $description, string $prefix, array $steps, array $transitions): void
    {
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => $code, 'subject_type' => $subject, 'name' => $name, 'description' => $description, 'is_active' => true,
            'is_user_startable' => false, 'reference_prefix' => $prefix, 'is_confidential' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stepIds = [];
        foreach ($steps as [$stepCode, $stepName, $order, $type, $assignment, $role, $config]) {
            $stepIds[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $stepName, 'sort_order' => $order, 'step_type' => $type,
                'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $transitionName, $requiresComment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to], 'action_code' => $action,
                'name' => $transitionName, 'conditions' => null, 'requires_comment' => $requiresComment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
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
     * Rolled back only while no rental record, request or reference value of this package is in use.
     */
    public function down(): void
    {
        foreach ([...self::GUARDED, 'aims_rental_settlements', 'aims_rental_documents', 'aims_rental_contract_services', 'aims_rental_maintenance_links'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("الجدول {$table} يحتوي سجلات؛ لا يمكن التراجع.");
            }
        }
        $definitions = DB::table('workflow_definitions')->whereIn('code', self::DEFINITIONS)->pluck('id');
        $versions = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitions)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
            throw new RuntimeException('توجد معاملات إيجار على المحرك؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_versions')->whereIn('id', $versions)->delete();
        DB::table('workflow_definitions')->whereIn('id', $definitions)->delete();
        DB::table('notification_settings')->whereIn('event_code', ['rental_contract_expiring', 'rental_contract_expired', 'rental_due_overdue'])->delete();
        $permission = DB::table('permissions')->where('code', 'finance.rentals.record')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        DB::table('permissions')->where('id', $permission)->delete();

        DB::statement('DROP INDEX IF EXISTS aims_rental_dues_open_idx');
        DB::statement('DROP INDEX IF EXISTS aims_rental_contracts_open_successor');
        DB::statement('DROP INDEX IF EXISTS aims_rental_tenants_identity_unique');
        DB::statement('DROP INDEX IF EXISTS aims_rental_receipts_live_unique');
        DB::statement('ALTER TABLE aims_rental_receipt_allocations ADD CONSTRAINT aims_rental_receipt_allocations_accounting_reference_due_id_uni UNIQUE (accounting_reference, due_id)');
        DB::statement('DROP INDEX IF EXISTS aims_rental_dues_item_unique');
        DB::statement('ALTER TABLE aims_rental_dues ADD CONSTRAINT aims_rental_dues_contract_id_due_on_unique UNIQUE (contract_id, due_on)');
        DB::statement('ALTER TABLE aims_rental_contract_units DROP CONSTRAINT IF EXISTS aims_rental_contract_units_no_overlap');
        foreach (['aims_property_units' => ['aims_property_units_availability_check', 'aims_property_units_area_check'], 'aims_rental_tenants' => ['aims_rental_tenants_type_check'],
            'aims_rental_applications' => ['aims_rental_applications_status_check'], 'aims_rental_contracts' => ['aims_rental_contracts_status_check', 'aims_rental_contracts_kind_check', 'aims_rental_contracts_frequency_check', 'aims_rental_contracts_dates_check'],
            'aims_rental_contract_units' => ['aims_rental_contract_units_occupancy_check'], 'aims_rental_dues' => ['aims_rental_dues_status_check'],
            'aims_rental_receipt_allocations' => ['aims_rental_receipt_allocations_amount_check'], 'aims_rental_cases' => ['aims_rental_cases_status_check', 'aims_rental_cases_outcome_check'],
            'aims_rental_meter_readings' => ['aims_rental_meter_readings_purpose_check'], 'aims_rental_notices' => ['aims_rental_notices_status_check']] as $table => $checks) {
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$check}");
            }
        }

        Schema::dropIfExists('aims_rental_maintenance_links');
        Schema::dropIfExists('aims_rental_documents');
        Schema::dropIfExists('aims_rental_contract_services');
        Schema::dropIfExists('aims_rental_settlements');
        Schema::dropIfExists('aims_property_unit_status_events');
        Schema::dropIfExists('rentals_module_settings');
        foreach ([
            'aims_rental_notices' => ['issued_by'], 'aims_rental_meter_readings' => ['recorded_by', 'case_id'], 'aims_rental_cases' => ['workflow_instance_id', 'termination_reason_value_id'],
            'aims_rental_receipt_allocations' => ['recorded_by', 'payment_method_value_id', 'reversed_by'], 'aims_rental_dues' => ['service_value_id'],
            'aims_rental_contracts' => ['workflow_instance_id', 'termination_reason_value_id', 'created_by'], 'aims_rental_applications' => ['workflow_instance_id'],
        ] as $table => $foreign) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreign): void {
                foreach ($foreign as $column) {
                    $blueprint->dropConstrainedForeignId($column);
                }
            });
        }
        foreach ([
            'aims_rental_notices' => [], 'aims_rental_meter_readings' => ['purpose'], 'aims_rental_cases' => ['departure_on', 'outcome_unit_status', 'damages', 'completed_at'],
            'aims_rental_receipt_allocations' => ['external_system', 'note', 'request_key', 'reversed_at', 'reversal_reason', 'reversal_reference'],
            'aims_rental_dues' => ['kind', 'cancelled_at', 'cancel_reason'], 'aims_rental_contract_units' => ['starts_on', 'ends_on', 'occupies', 'rent_per_m2'],
            'aims_rental_contracts' => ['kind', 'issued_on', 'change_reason', 'terminated_on', 'closed_at'], 'aims_rental_applications' => ['decision_reason', 'reserved_at'],
            'aims_rental_tenants' => ['phone2'], 'aims_property_units' => ['directions'], 'aims_properties' => ['plot_number', 'parcel_number'],
        ] as $table => $columns) {
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
            }
        }
        Schema::table('aims_property_units', fn (Blueprint $table) => $table->string('availability', 32)->default('vacant')->change());
    }
};
