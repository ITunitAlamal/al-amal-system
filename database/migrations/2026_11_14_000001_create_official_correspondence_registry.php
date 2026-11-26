<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_user_roles', function (Blueprint $t): void {
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
        });
        Schema::create('correspondence_policies', function (Blueprint $t): void {
            $t->id();
            $t->string('policy_key', 100);
            $t->unsignedInteger('version');
            $t->string('kind', 40);
            $t->jsonb('configuration');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('approval_reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['policy_key', 'version']);
        });
        Schema::create('official_correspondences', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->uuid('creation_key')->unique();
            $t->string('reference', 150)->nullable()->unique();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_department_id')->constrained('departments')->restrictOnDelete();
            foreach (['type_value_id', 'priority_value_id', 'classification_value_id'] as $field) {
                $t->foreignId($field)->constrained('master_data_values')->restrictOnDelete();
            }
            $t->foreignId('retention_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('official_correspondences')->restrictOnDelete();
            $t->string('subject', 250);
            $t->text('body');
            $t->date('letter_date');
            $t->timestamp('due_at')->nullable();
            $t->boolean('acknowledgment_required')->default(true);
            $t->string('status', 30)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->string('board_reference')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['department_id', 'status']);
        });
        Schema::create('correspondence_actions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('correspondence_id')->constrained('official_correspondences')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->string('action', 40);
            $t->boolean('is_original')->default(false);
            $t->text('note')->nullable();
            $t->jsonb('metadata');
            $t->timestamp('created_at');
            $t->unique(['correspondence_id', 'idempotency_key']);
        });
        Schema::create('correspondence_access', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('action_id')->constrained('correspondence_actions')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $t->string('authority_role', 50);
            $t->date('valid_until')->nullable();
            $t->timestamp('created_at');
            $t->unique(['action_id', 'user_id']);
        });
        Schema::create('correspondence_tasks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('correspondence_id')->unique()->constrained('official_correspondences')->restrictOnDelete();
            $t->foreignId('action_id')->constrained('correspondence_actions')->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $t->string('status', 30)->default('open');
            $t->timestamp('due_at')->nullable();
            foreach (['received_at', 'read_at', 'acknowledged_at', 'completed_at'] as $field) {
                $t->timestamp($field)->nullable();
            }
            $t->timestamps();
            $t->index(['assigned_to', 'status']);
        });
        Schema::create('correspondence_sequences', function (Blueprint $t): void {
            $t->string('scope', 150)->primary();
            $t->unsignedBigInteger('last_number')->default(0);
        });
        Schema::table('workflow_event_attachments', function (Blueprint $t): void {
            $t->unsignedBigInteger('workflow_event_id')->nullable()->change();
            $t->foreignId('correspondence_action_id')->nullable()->constrained('correspondence_actions')->restrictOnDelete();
            $t->unsignedInteger('document_version')->default(1);
            $t->foreignId('replaces_attachment_id')->nullable()->constrained('workflow_event_attachments')->restrictOnDelete();
        });
        $this->installCatalog();
    }

    public function down(): void
    {
        throw new RuntimeException('Official records must be retained; disable the module and roll back code without dropping data.');
    }

    private function installReferences(): void
    {
        $now = now();
        $group = DB::table('master_data_groups')->where('code', 'correspondence')->value('id')
            ?? DB::table('master_data_groups')->insertGetId(['code' => 'correspondence', 'name_ar' => 'المراسلات الرسمية', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $catalog = [
            'correspondence_types' => ['أنواع المراسلات', ['memo' => 'مذكرة داخلية', 'letter' => 'كتاب رسمي', 'inquiry' => 'استفسار', 'response' => 'رد رسمي', 'direction' => 'توجيه تنفيذي', 'circular' => 'تعميم', 'referral' => 'إحالة', 'report_request' => 'طلب تقرير', 'task_assignment' => 'تكليف إداري', 'board_transmission' => 'تبليغ قرار مجلس الإدارة', 'document_transmittal' => 'إرسال مستندات', 'acknowledgment' => 'إقرار استلام']],
            'correspondence_classifications' => ['سرية المراسلات', ['internal' => 'داخلي', 'confidential' => 'سري', 'restricted' => 'مقيد']],
            'correspondence_priorities' => ['أولوية المراسلات', ['normal' => 'عادي', 'urgent' => 'عاجل']],
            'correspondence_retention' => ['فئات الاحتفاظ', ['pending_approval' => 'احتفاظ دون إتلاف — لحين اعتماد السياسة']],
        ];
        foreach ($catalog as $code => [$label, $values]) {
            $type = DB::table('master_data_types')->where('code', $code)->value('id')
                ?? DB::table('master_data_types')->insertGetId(['group_id' => $group, 'code' => $code, 'name_ar' => $label, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($values as $value => $name) {
                if (! DB::table('master_data_values')->where('type_id', $type)->where('code', $value)->exists()) {
                    DB::table('master_data_values')->insert(['type_id' => $type, 'code' => $value, 'name_ar' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    private function installCatalog(): void
    {
        $now = now();
        $this->installReferences();
        foreach (['secretariat' => 'السكرتاريا', 'acting_department_head' => 'قائم بأعمال رئيس قسم'] as $code => $name) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                DB::table('roles')->insert(compact('code', 'name') + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
        DB::table('correspondence_policies')->insert(['policy_key' => 'system', 'version' => 1, 'kind' => 'settings', 'starts_on' => '1970-01-01', 'configuration' => json_encode(['prefix' => 'AIMS', 'file_count' => 10, 'file_kb' => 5120, 'total_kb' => 20480, 'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'txt'], 'confirmed' => false, 'letterhead' => 'جمعية معهد الأمل للأيتام', 'signature_label' => 'اسم المصدر — ليس توقيعًا رقميًا']), 'created_at' => $now]);
        DB::table('notification_settings')->insert(['event_code' => 'correspondence.assigned', 'label_ar' => 'مراسلة رسمية تحتاج متابعة', 'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('navigation_items')->insert(['code' => 'official_correspondence', 'label_ar' => 'المراسلات الرسمية', 'route_name' => 'correspondence.index', 'access_rule' => 'authenticated', 'icon' => 'folder', 'is_active' => true, 'sort_order' => 35, 'created_at' => $now, 'updated_at' => $now]);
    }
};
