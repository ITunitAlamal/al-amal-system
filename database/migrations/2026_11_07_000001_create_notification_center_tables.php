<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent in-app notifications (read / unread) and the central notification settings.
 *
 * "notifications" keeps Laravel's standard columns so $user->notifications works, plus a dedupe key
 * (one row per recipient per event occurrence, so retries never notify twice) and the SMS outcome.
 * "notification_settings" lets the system admin switch each event's in-app and SMS channels.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: ?string}> code, label, description, in-app, sms, sms template */
    private const EVENTS = [
        ['workflow_task_assigned', 'مهمة جديدة بانتظار إجرائك', 'عند وصول معاملة إلى مرحلة مسندة للمستخدم.', true, false, null],
        ['workflow_task_urgent', 'مهمة عاجلة بانتظار إجرائك', 'عند وصول معاملة أولويتها «عاجلة» أو «طارئة» إلى المستخدم.', true, true, 'AIMS: لديك معاملة عاجلة بانتظار إجرائك. يرجى الدخول إلى النظام.'],
        ['workflow_task_overdue', 'معاملة تجاوزت المهلة', 'عندما تتجاوز معاملة مهلة مرحلتها، للمسؤول ولجهة التصعيد.', true, true, 'AIMS: لديك معاملة تجاوزت المهلة المحددة. يرجى الدخول إلى النظام.'],
        ['workflow_request_returned', 'إعادة طلبك للاستكمال', 'لمقدم الطلب عند إعادته إليه.', true, false, null],
        ['workflow_request_closed', 'إغلاق طلبك', 'لمقدم الطلب عند إنجاز الطلب أو رفضه.', true, false, null],
        ['leave_awaiting_approval', 'طلب إجازة بانتظار قرارك', 'لصاحب صلاحية اعتماد الإجازة عند تقديم الطلب.', true, false, null],
        ['leave_decided', 'قرار على طلب إجازتك', 'لمقدم طلب الإجازة عند اعتماده أو رفضه.', true, false, null],
        ['leave_prorata_proposed', 'سياسة احتساب نسبي بانتظار القرار', 'للمدير التنفيذي عند اقتراح معايير الاحتساب النسبي.', true, false, null],
    ];

    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->string('event_code', 60);
            $table->string('dedupe_key', 191)->nullable();
            $table->string('sms_status', 20)->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_recipient_dedupe_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_recipient_unread_index');
        });

        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('event_code', 60)->unique();
            $table->string('label_ar', 200);
            $table->string('description', 500)->nullable();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_template', 300)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        foreach (self::EVENTS as [$code, $label, $description, $inApp, $sms, $template]) {
            DB::table('notification_settings')->insert([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => $inApp, 'sms_enabled' => $sms, 'sms_template' => $template,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
