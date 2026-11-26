<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. تعريف نوع الطلب ومسار العمل
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. إصدارات مسارات العمل
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_definition_id')
                ->constrained('workflow_definitions')
                ->restrictOnDelete();

            $table->unsignedInteger('version_number');

            // draft, published, retired
            $table->string('status', 30)->default('draft');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workflow_definition_id', 'version_number'],
                'workflow_version_unique'
            );
        });

        // 3. المراحل ضمن كل إصدار
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_version_id')
                ->constrained('workflow_versions')
                ->restrictOnDelete();

            $table->string('code', 100);
            $table->string('name', 200);

            // للعرض فقط، وليس لتحديد الانتقالات
            $table->unsignedInteger('sort_order');

            // start, task, decision, end
            $table->string('step_type', 30)->default('task');

            // requester, department_head,
            // executive_director, role, user
            $table->string('assignment_type', 40)->nullable();

            $table->string('assigned_role_code', 100)
                ->nullable();

            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->unique(
                ['workflow_version_id', 'code'],
                'workflow_step_code_unique'
            );

            $table->unique(
                ['workflow_version_id', 'sort_order'],
                'workflow_step_order_unique'
            );
        });

        // 4. الانتقالات والتفرعات بين المراحل
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_version_id')
                ->constrained('workflow_versions')
                ->restrictOnDelete();

            $table->foreignId('from_step_id')
                ->constrained('workflow_steps')
                ->restrictOnDelete();

            $table->foreignId('to_step_id')
                ->constrained('workflow_steps')
                ->restrictOnDelete();

            // submit, approve, return, reject,
            // assign, complete, etc.
            $table->string('action_code', 60);

            $table->string('name', 200);

            // شروط التفرع مثل توفر المخزون
            // أو حدود الصلاحية المالية
            $table->json('conditions')->nullable();

            $table->boolean('requires_comment')
                ->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index([
                'workflow_version_id',
                'from_step_id',
                'action_code',
            ], 'workflow_transition_lookup');
        });

        // 5. حالة سير العمل لطلب فعلي
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->foreignId('workflow_version_id')
                ->constrained('workflow_versions')
                ->restrictOnDelete();

            // ربط الطلب بوحدته الأصلية
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            // القسم الذي بدأ منه الطلب
            $table->foreignId('department_id')
                ->constrained('departments')
                ->restrictOnDelete();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('current_step_id')
                ->nullable()
                ->constrained('workflow_steps')
                ->restrictOnDelete();

            $table->foreignId('current_assignee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // pending, in_progress, completed,
            // rejected, cancelled
            $table->string('status', 30)->default('pending');

            // منع الكتابة فوق عمليات متزامنة
            $table->unsignedInteger('revision')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->index(['requested_by', 'status']);
            $table->index(['current_assignee_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        // 6. السجل الزمني للتحويلات والإجراءات
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_instance_id')
                ->constrained('workflow_instances')
                ->restrictOnDelete();

            $table->foreignId('workflow_transition_id')
                ->nullable()
                ->constrained('workflow_transitions')
                ->restrictOnDelete();

            $table->foreignId('from_step_id')
                ->nullable()
                ->constrained('workflow_steps')
                ->restrictOnDelete();

            $table->foreignId('to_step_id')
                ->nullable()
                ->constrained('workflow_steps')
                ->restrictOnDelete();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('assigned_to_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 60);

            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['workflow_instance_id', 'occurred_at'],
                'workflow_events_timeline_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};
