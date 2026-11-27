<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification types of the single task inbox («مهامي»). Data only.
 *
 * Work that reaches the human-resources department outside the workflow engine (employee documents and photos
 * waiting for review, punch corrections, administrative actions) is now listed in «مهامي» of whoever decides it.
 * These types tell the people concerned: the reviewer when something arrives, and the employee or the requester
 * when it is decided. NotificationCenter sends nothing for a type that has no row here, so the code is safe to
 * deploy before this migration runs.
 *
 * Safe to run twice: an existing row (and the administrator's choices on it) is left alone.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['employee_file_review', 'وثيقة أو صورة موظف بانتظار مراجعتك', 'لرئيس الموارد البشرية عندما تُرفع وثيقة تحتاج توثيقًا أو يرفع موظف صورته الشخصية للمراجعة.'],
        ['employee_document_reviewed', 'نتيجة توثيق وثيقتك', 'للموظف عند توثيق وثيقة في ملفه أو رفضها، مع ملاحظة المراجع.'],
        ['employee_photo_reviewed', 'نتيجة مراجعة صورتك الشخصية', 'للموظف عند اعتماد صورته الشخصية أو رفضها، مع سبب الرفض.'],
        ['attendance_correction_decided', 'قرار طلب تصحيح البصمة', 'لمن طلب تصحيح اتجاه بصمة عند اعتماد الطلب أو رفضه.'],
        ['hr_action_cancelled', 'إلغاء إجراء إداري صادر بحقك', 'للموظف عند إلغاء إجراء إداري سبق إصداره بحقه.'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * The types are kept: notifications already delivered refer to them, and removing a row only silences the
     * notice. The system administrator can switch any of them off in «إعدادات الإشعارات».
     */
    public function down(): void
    {
        // Nothing to undo.
    }
};
