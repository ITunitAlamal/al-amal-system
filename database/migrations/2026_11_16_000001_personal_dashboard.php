<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified personal dashboard «مساحتي» (owner prompt 2026-10-23):
 *
 * - personal_tasks: a private To-Do list. Only its owner reads or changes an item; it never approves, closes or
 *   escalates anything official. Recurring items share a series and are unique per due time (idempotent).
 * - personal_task_events: the readable history of an item (created, updated, completed, reopened, archived...).
 * - user_notification_preferences: notification types a user chose not to receive in the app. Work obligations
 *   (assigned tasks, returns, overdue work) cannot be muted; see NotificationCenter::MANDATORY_EVENTS.
 * - Reference constants «أولويات المهام الشخصية» and «تصنيفات المهام الشخصية», administered in «الثوابت».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('series_id')->nullable();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->foreignId('category_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('priority_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('recurrence', 10)->default('none');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_at']);
        });
        DB::statement("ALTER TABLE personal_tasks ADD CONSTRAINT personal_tasks_status_check CHECK (status IN ('open', 'in_progress', 'done'))");
        DB::statement("ALTER TABLE personal_tasks ADD CONSTRAINT personal_tasks_recurrence_check CHECK (recurrence IN ('none', 'daily', 'weekly', 'monthly'))");
        DB::statement('ALTER TABLE personal_tasks ADD CONSTRAINT personal_tasks_progress_check CHECK (progress BETWEEN 0 AND 100)');
        DB::statement('CREATE UNIQUE INDEX personal_tasks_series_due_unique ON personal_tasks (series_id, due_at) WHERE series_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX personal_tasks_due_reminders ON personal_tasks (remind_at) WHERE reminded_at IS NULL AND deleted_at IS NULL');

        Schema::create('personal_task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_task_id')->constrained('personal_tasks')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->jsonb('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('personal_task_id');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->jsonb('muted_events')->default('[]');
            $table->timestamps();
        });

        $group = (int) DB::table('master_data_groups')->where('code', 'work')->value('id');
        foreach ([
            ['personal_task_priorities', 'أولويات المهام الشخصية', [['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'مهمة'], ['urgent', 'عاجلة']]],
            ['personal_task_categories', 'تصنيفات المهام الشخصية', [['follow_up', 'متابعة عمل'], ['meeting', 'اجتماع أو موعد'], ['admin', 'تذكير إداري'], ['personal', 'شخصي'], ['other', 'أخرى']]],
        ] as $order => [$code, $name, $values]) {
            $typeId = DB::table('master_data_types')->where('code', $code)->value('id') ?: DB::table('master_data_types')->insertGetId([
                'group_id' => $group ?: null, 'code' => $code, 'name_ar' => $name, 'sort_order' => 50 + $order, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($values as $index => [$valueCode, $valueName]) {
                if (! DB::table('master_data_values')->where('type_id', $typeId)->where('code', $valueCode)->exists()) {
                    DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $valueCode, 'name_ar' => $valueName, 'sort_order' => ($index + 1) * 10,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        DB::table('notification_settings')->insertOrIgnore([
            'event_code' => 'personal_task_reminder', 'label_ar' => 'تذكير بمهمة شخصية',
            'description' => 'لصاحب المهمة وحده عند حلول موعد التذكير الذي حدده في «قائمة مهامي»؛ لا يصل لأي شخص آخر.',
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'dashboard.personal.installed', 'subject_type' => 'personal_tasks', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => ['personal_tasks', 'personal_task_events', 'user_notification_preferences'],
                'constants' => ['personal_task_priorities', 'personal_task_categories'], 'notification' => 'personal_task_reminder'],
                'source' => 'ترحيل 2026_11_16_000001 (مساحتي الموحدة وقائمة المهام الشخصية)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('personal_tasks')->exists()) {
            throw new RuntimeException('توجد مهام شخصية للمستخدمين؛ لا يمكن التراجع دون ترحيلها.');
        }
        Schema::dropIfExists('personal_task_events');
        Schema::dropIfExists('personal_tasks');
        Schema::dropIfExists('user_notification_preferences');
        DB::table('notification_settings')->where('event_code', 'personal_task_reminder')->delete();
    }
};
