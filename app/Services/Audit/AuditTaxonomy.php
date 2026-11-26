<?php

namespace App\Services\Audit;

/**
 * Arabic labels of the «سجل النظام» classification. The classification itself is done in the database
 * (aims_audit_module / aims_audit_fill, migration 2026_11_30_000012) so every writer is treated the same way.
 */
final class AuditTaxonomy
{
    public const MODULES = [
        'security' => 'الأمن والدخول', 'organization' => 'الهيكل التنظيمي', 'hr' => 'الموارد البشرية', 'attendance' => 'الدوام',
        'leaves' => 'الإجازات', 'workflow' => 'مسارات العمل والاعتمادات', 'correspondence' => 'المراسلات', 'orphans' => 'شؤون الأيتام والكفالات',
        'portal' => 'بوابة المستفيدين', 'field' => 'التسليم الميداني', 'finance' => 'الحسابات', 'assets' => 'الأصول والإيجارات',
        'settings' => 'الإعدادات', 'tasks' => 'المهام', 'other' => 'أخرى',
    ];

    public const OUTCOMES = ['success' => 'نجحت', 'failure' => 'فشلت', 'denied' => 'رُفضت (صلاحية)'];

    public const SEVERITIES = ['info' => 'عادي', 'notice' => 'مهم', 'warning' => 'تنبيه', 'critical' => 'حرج'];

    public const SENSITIVITIES = ['normal' => 'عادية', 'personal' => 'بيانات شخصية', 'restricted' => 'مقيدة (صحية/مالية/هوية)'];

    public const ORIGINS = ['staff' => 'موظف', 'portal' => 'بوابة المستفيدين', 'worker' => 'مهمة خلفية', 'api' => 'تطبيق ميداني', 'system' => 'النظام'];

    /** Keys whose values are never stored (credentials). */
    public const SECRET_KEYS = '/pass(word)?|token|secret|authorization|credential|private.?key|otp|cookie|session|api.?key|signature|^pin$/i';

    /** Keys whose values are stored as «changed» only (identity, health, restricted notes). */
    public const PROTECTED_KEYS = '/id_number|national_id|identity_number|id_hash|id_last4|health|medical|diagnos|disab|loss_circ|restricted_notes|researcher_notes|form_data|original/i';
}
