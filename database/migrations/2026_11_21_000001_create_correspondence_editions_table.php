<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('correspondence_editions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('submission_key')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('correspondence_id')->constrained('official_correspondences')->restrictOnDelete();
            $table->foreignId('predecessor_id')->nullable()->constrained('correspondence_editions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('action_id')->constrained('correspondence_actions')->restrictOnDelete();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->string('origin_role', 30);
            $table->string('status', 30)->default('pending');
            $table->boolean('board_related')->default(false);
            $table->boolean('substantive')->default(false);
            $table->string('subject', 250);
            $table->text('body');
            $table->text('reason');
            $table->jsonb('differences');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['correspondence_id', 'version']);
        });
        DB::statement("CREATE UNIQUE INDEX correspondence_one_pending_edition ON correspondence_editions (correspondence_id) WHERE status = 'pending'");
        foreach (['staff', 'head', 'executive', 'board'] as $origin) {
            $definition = DB::table('workflow_definitions')->insertGetId(['code' => 'CORRESPONDENCE_EDITION_'.strtoupper($origin), 'name' => 'اعتماد نسخة مراسلة — '.$origin, 'subject_type' => 'correspondence_edition', 'is_active' => true, 'is_user_startable' => false, 'is_confidential' => true, 'reference_prefix' => 'EDITION', 'created_at' => now(), 'updated_at' => now()]);
            $version = DB::table('workflow_versions')->insertGetId(['workflow_definition_id' => $definition, 'version_number' => 1, 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $steps = [['submit', 'اقتراح نسخة بديلة', 'start', 'requester', null]];
            if ($origin === 'board') {
                $steps[] = ['pending_board', 'معلّقة لحين توثيق سلطة اعتماد المجلس', 'task', 'requester', null];
            } else {
                $steps[] = $origin === 'staff' ? ['review', 'مراجعة رئيس القسم', 'task', 'department_head', null] : ['register', 'تسجيل وتدقيق السكرتاريا — ليس اعتمادًا', 'task', 'role', 'secretariat'];
                $steps[] = ['approve', 'اعتماد المدير التنفيذي للنص البديل', 'decision', 'role', 'executive_director'];
                $steps[] = ['published', 'نسخة معتمدة منشورة', 'end', null, null];
                $steps[] = ['rejected', 'نسخة مرفوضة', 'end', null, null];
            }
            $ids = [];
            foreach ($steps as $index => [$code, $label, $type, $assignment, $role]) {
                $ids[$code] = DB::table('workflow_steps')->insertGetId(['workflow_version_id' => $version, 'code' => $code, 'name' => $label, 'sort_order' => $index * 10, 'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role, 'assignment_config' => json_encode(['department_scope' => 'any', 'requester_may_act' => $origin === 'executive' && $code === 'approve', 'outcome' => $code === 'rejected' ? 'rejected' : 'completed']), 'is_required' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $review = $origin === 'staff' ? 'review' : 'register';
            $transitions = $origin === 'board' ? [['submit', 'pending_board', 'submit', 'تسجيل الاقتراح دون تفويض تعديل المجلس']] : [
                ['submit', $review, 'submit', 'إرسال للمراجعة'], [$review, 'approve', 'reviewed', 'استكمال التدقيق وإرسال للاعتماد'],
                ['approve', 'published', 'approve', 'أعتمد النص وأقر بعدم تغيير مضمون أي قرار مجلس مشار إليه'],
                [$review, 'rejected', 'reject', 'رفض مع السبب'], ['approve', 'rejected', 'reject', 'رفض مع السبب'],
            ];
            foreach ($transitions as [$from, $to, $code, $label]) {
                DB::table('workflow_transitions')->insert(['workflow_version_id' => $version, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to], 'action_code' => $code, 'name' => $label, 'requires_comment' => $from !== 'submit', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Retain official editions and approval history; roll back code without dropping records.');
    }
};
