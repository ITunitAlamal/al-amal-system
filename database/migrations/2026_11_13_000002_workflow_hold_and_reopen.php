<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suspend, resume and reopen for requests (ED requirements doc §5 «تعليق» و«إعادة فتح»; SRS §3.1.2.3, CORE06).
 *
 * - A suspended request keeps its stage and holder; it carries the reason, the review date and who suspended it,
 *   and its stage deadline pauses until it is resumed.
 * - Reopening is recorded as a timeline event; no column is needed for it.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['workflow_request_held', 'تعليق طلب', 'لمقدم الطلب عند تعليق معاملته مؤقتًا بانتظار معلومة أو موافقة أو مورد.'],
        ['workflow_request_resumed', 'استئناف طلب معلق', 'لمقدم الطلب عند استئناف معاملته بعد التعليق.'],
        ['workflow_request_reopened', 'إعادة فتح طلب', 'لمقدم الطلب عند إعادة فتح معاملته بعد إغلاقها.'],
        ['workflow_hold_review_due', 'حلول موعد مراجعة طلب معلق', 'للمسؤول الحالي عند حلول موعد مراجعة معاملة معلقة.'],
    ];

    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->timestamp('held_at')->nullable();
            $table->date('hold_until')->nullable();
            $table->text('hold_reason')->nullable();
            $table->foreignId('held_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('workflow_instances')->whereNotNull('held_at')->exists()) {
            throw new RuntimeException('توجد معاملات معلقة؛ استأنفها قبل التراجع.');
        }
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn(['held_at', 'hold_until', 'hold_reason']);
        });
        DB::table('notification_settings')->whereIn('event_code', array_column(self::EVENTS, 0))->delete();
    }
};
