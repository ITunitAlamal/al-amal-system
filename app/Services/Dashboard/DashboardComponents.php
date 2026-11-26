<?php

namespace App\Services\Dashboard;

/**
 * Registry of the home-page («مساحتي») components a layout template can arrange. Keys are stable; adding a
 * component means adding it here and rendering it in Home.vue. A template only orders, sizes, shows or hides
 * components: whether a component can appear for a user is decided by the server from that user's own access
 * (see DashboardLayoutResolver::availability) and never by the template.
 *
 * Section «base» is what every employee has (their own work and records); section «role» is added by the user's
 * own roles, assignments and scope. A locked component cannot be hidden by any template (work obligations).
 */
final class DashboardComponents
{
    public const SIZES = ['full' => 'عرض كامل', 'two_thirds' => 'ثلثا العرض', 'half' => 'نصف العرض', 'third' => 'ثلث العرض'];

    public const SIZE_RANK = ['third' => 1, 'half' => 2, 'two_thirds' => 3, 'full' => 4];

    public const SECTIONS = ['base' => 'مساحتي الأساسية', 'role' => 'أعمال أدواري'];

    /**
     * @return array<string, array{label: string, description: string, order: int, size: string, visible: bool, locked: bool, section: string, requirement: string, options: array<string, array{label: string, min: int, max: int}>}>
     */
    public static function all(): array
    {
        $none = [];
        $limit = fn (string $label, int $min, int $max): array => ['limit' => ['label' => $label, 'min' => $min, 'max' => $max]];

        return [
            'welcome' => ['label' => 'الترحيب', 'description' => 'اسم المستخدم وقسمه وأدواره وتاريخ اليوم.', 'order' => 10, 'size' => 'full', 'visible' => true, 'locked' => true, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'action_center' => ['label' => 'يحتاج إجرائي الآن', 'description' => 'عدادات ما ينتظر المستخدم: مهام رسمية، طلبات معادة، مراسلات، مهام شخصية مستحقة، إشعارات غير مقروءة.', 'order' => 15, 'size' => 'full', 'visible' => true, 'locked' => true, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'readiness' => ['label' => 'جاهزية الإعدادات', 'description' => 'نواقص الأدوار والملفات والأرصدة.', 'order' => 20, 'size' => 'full', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'مدير النظام', 'options' => $none],
            'start_options' => ['label' => 'طلب جديد', 'description' => 'أنواع الطلبات المتاحة للمستخدم: الإجازة والسلفة والشكوى والصيانة والخدمات.', 'order' => 30, 'size' => 'full', 'visible' => true, 'locked' => true, 'section' => 'base', 'requirement' => 'موظف مرتبط بقسم', 'options' => $none],
            'stats' => ['label' => 'ملخص مهامي وطلباتي', 'description' => 'أربع بطاقات: بانتظار إجرائي، المتأخرة، طلباتي الجارية والمنجزة. حلّت محلها «يحتاج إجرائي الآن».', 'order' => 40, 'size' => 'full', 'visible' => false, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'shortcuts' => ['label' => 'اختصارات', 'description' => 'روابط سريعة يختارها القالب من القائمة، ويظهر منها ما يملك المستخدم فتحه فقط.', 'order' => 45, 'size' => 'full', 'visible' => false, 'locked' => false, 'section' => 'base', 'requirement' => 'رابط واحد مسموح له على الأقل', 'options' => $none],
            'tasks' => ['label' => 'مهامي الرسمية — بانتظار إجرائي', 'description' => 'المهام المسندة إليه الآن من مسارات العمل مع مهلها؛ إنجازها بإجراء المسار نفسه.', 'order' => 100, 'size' => 'two_thirds', 'visible' => true, 'locked' => true, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'todo' => ['label' => 'قائمة مهامي الشخصية', 'description' => 'مهام خاصة بالمستخدم وحده مع مواعيدها وتذكيراتها.', 'order' => 102, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $limit('عدد المهام المعروضة', 3, 10)],
            'my_requests' => ['label' => 'طلباتي — أين وصلت؟', 'description' => 'آخر طلباته مع مراحلها.', 'order' => 105, 'size' => 'two_thirds', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'correspondence' => ['label' => 'مراسلاتي', 'description' => 'المراسلات الرسمية الواردة والصادرة وما ينتظر إجراءه منها.', 'order' => 107, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم (ضمن صلاحيات سجل المراسلات)', 'options' => $none],
            'leave_balances' => ['label' => 'رصيد إجازاتي', 'description' => 'أرصدة السنة الحالية حسب أنواع الإجازات المعرّفة لملفه.', 'order' => 110, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'calendar' => ['label' => 'التقويم الشخصي', 'description' => 'إجازاته والعطل الرسمية ومواعيد مهامه الرسمية ومراسلاته ومهامه الشخصية لأسبوعين.', 'order' => 112, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'notifications' => ['label' => 'الإشعارات والتذكيرات', 'description' => 'أحدث إشعارات المستخدم نفسه.', 'order' => 114, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $limit('عدد الإشعارات', 3, 10)],
            'activity' => ['label' => 'آخر نشاطاتي', 'description' => 'إجراءاته الأخيرة على المعاملات ومهامه الشخصية.', 'order' => 116, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'evaluation' => ['label' => 'تقييمي الذاتي', 'description' => 'نسبة إنجاز المهام المسندة هذا الشهر.', 'order' => 118, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'مهام مسندة إليه هذا الشهر', 'options' => $none],
            'profile' => ['label' => 'ملفي الوظيفي', 'description' => 'بيانات ملفه الأساسية.', 'order' => 130, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'base', 'requirement' => 'كل مستخدم', 'options' => $none],
            'executive' => ['label' => 'لوحة المدير التنفيذي', 'description' => 'المعاملات الجارية والمتأخرة والقرارات المنتظرة.', 'order' => 200, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'دور المدير التنفيذي', 'options' => $none],
            'team' => ['label' => 'فريقي', 'description' => 'إجازات الفريق وطلبات القسم، مع رابط «قسمي».', 'order' => 210, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'رئيس قسم معيّن في الهيكل', 'options' => $none],
            'hr_services' => ['label' => 'الموارد البشرية والخدمات العامة', 'description' => 'الموظفون الفعالون والحضور والإجازات المعلقة وطلبات الخدمات والصيانة، مع رابط اللوحة الكاملة.', 'order' => 220, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'رئيس الموارد البشرية أو مدير النظام أو المدير التنفيذي', 'options' => $none],
            'rentals' => ['label' => 'الأصول والمباني والإيجارات', 'description' => 'الأعيان المؤجرة والشاغرة والعقود الفعالة، وما ينتظر الإعداد أو قرار المدير التنفيذي، والعقود القريبة من الانتهاء والاستحقاقات المتأخرة.', 'order' => 225, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'رئيس قسم الأصول والمباني أو مسؤول ملف الإيجارات أو المدير التنفيذي (عرض وحدة الإيجارات)', 'options' => $none],
            'finance' => ['label' => 'الحسابات', 'description' => 'صندوق الاعتمادات المالية بحسب الصلاحية.', 'order' => 230, 'size' => 'third', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'صلاحية في وحدة الحسابات', 'options' => $none],
            'kpis' => ['label' => 'مؤشرات الأداء', 'description' => 'بطاقات المؤشرات بنطاق المستخدم.', 'order' => 240, 'size' => 'full', 'visible' => true, 'locked' => false, 'section' => 'role', 'requirement' => 'نطاق مؤشرات أداء (الإدارة العليا ورؤساء الأقسام)', 'options' => $limit('أقصى عدد للبطاقات', 1, 12)],
        ];
    }
}
