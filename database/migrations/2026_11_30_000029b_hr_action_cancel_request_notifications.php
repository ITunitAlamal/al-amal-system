<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification types for cancelling an administrative action that stands on the executive director's decision
 * (TRANSFORM §3.1: a central decision is not undone by the receiving department). Data only.
 *
 * The HR head no longer cancels such an action; HrActionService::requestCancel() asks the executive director, who
 * cancels it or keeps it. These types tell the executive director that a request arrived, and the requester what was
 * decided. NotificationCenter sends nothing for a type that has no row here, so the code is safe to deploy before
 * this migration runs.
 *
 * Safe to run twice: an existing row (and the administrator's choices on it) is left alone.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['hr_action_cancel_request', 'طلب إلغاء إجراء إداري بانتظار قرارك', 'للمدير التنفيذي عندما يطلب رئيس الموارد البشرية إلغاء إجراء إداري صدر بقراره.'],
        ['hr_action_cancel_request_decided', 'قرار طلب إلغاء الإجراء الإداري', 'لمقدم طلب الإلغاء عندما يلغي المدير التنفيذي الإجراء أو يبقيه.'],
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
     * notice. The system administrator can switch either of them off in «إعدادات الإشعارات».
     */
    public function down(): void
    {
        // Nothing to undo.
    }
};
