<?php

namespace App\Services\Configuration;

/**
 * Arabic names for the action codes and record types written to audit_logs.
 *
 * Codes look like "entity.verb" or "entity.part.verb". Known codes get an exact name;
 * unknown ones are composed from the entity and verb so a new code never shows raw.
 */
class AuditActionCatalog
{
    /** @var array<string, string> */
    private const ACTIONS = [
        // «سجل النظام» (2026-11-01): security, refusals, integrity and module bridges.
        'auth.login_failed' => 'محاولة دخول فاشلة', 'auth.lockout' => 'إيقاف مؤقت لمحاولات الدخول', 'auth.password_changed' => 'تغيير كلمة المرور',
        'portal.auth.login' => 'دخول وصي إلى البوابة', 'portal.auth.logout' => 'خروج وصي من البوابة', 'portal.auth.login_failed' => 'محاولة دخول فاشلة إلى البوابة',
        'portal.auth.lockout' => 'إيقاف مؤقت لمحاولات دخول البوابة', 'portal.auth.password_changed' => 'تغيير كلمة مرور حساب البوابة',
        'portal.signup.code_sent' => 'إرسال رمز تسجيل حساب في البوابة برسالة نصية',
        'access.denied' => 'رفض وصول (لا صلاحية)', 'orphans.campaign.list.decision_denied' => 'محاولة اعتماد/رفض كشف حملة دون صلاحية المدير التنفيذي',
        'audit.exported' => 'تصدير سجل النظام', 'audit.chain.broken' => 'انقطاع سلسلة ختم السجل',
        'workflow.task.approve' => 'اعتماد في مسار عمل', 'workflow.task.reject' => 'رفض في مسار عمل', 'workflow.task.return' => 'إعادة للتعديل في مسار عمل',
        'workflow.task.submit' => 'تقديم طلب في مسار عمل', 'workflow.task.recommend' => 'توصية في مسار عمل', 'workflow.task.comment' => 'تعليق في مسار عمل',
        'orphans.access.viewed' => 'اطلاع على ملف حساس', 'rentals.unit.status_changed' => 'تغيير حالة عين مؤجرة',
        // «شؤون الأيتام والكفالات» (AIMS 2.0)
        'orphans.department.decommissioned' => 'إزالة التنفيذ القديم لقسم الأيتام والكفالات',
        'orphans.guardian.created' => 'تسجيل وصي', 'orphans.guardian.updated' => 'تحديث سجل وصي', 'orphans.orphan.created' => 'إنشاء ملف يتيم',
        'orphans.orphan.updated' => 'تحديث ملف يتيم', 'orphans.person.updated' => 'تحديث البيانات الأساسية', 'orphans.person.identity_verified' => 'توثيق هوية يدويًا',
        'orphans.person.status_recorded' => 'تسجيل حالة حياة/وفاة', 'orphans.guardianship.created' => 'ربط وصي بيتيم', 'orphans.guardianship.approved' => 'اعتماد وصاية',
        'orphans.guardianship.rejected' => 'رفض ارتباط وصاية', 'orphans.guardianship.replaced' => 'استبدال وصي', 'orphans.guardianship.ended' => 'إنهاء ارتباط وصاية',
        'orphans.parent.linked' => 'ربط أب/أم', 'orphans.economic.recorded' => 'تسجيل تقييم اقتصادي', 'orphans.care.recorded' => 'تسجيل احتياج صحي/دعم',
        'orphans.document.uploaded' => 'رفع مستند', 'orphans.capability.granted' => 'منح قدرة في قسم الأيتام', 'orphans.capability.revoked' => 'سحب قدرة في قسم الأيتام',
        'orphans.setting.updated' => 'تعديل إعداد في قسم الأيتام', 'orphans.portal.account_approve' => 'اعتماد حساب بوابة وصي', 'orphans.portal.account_reject' => 'رفض حساب بوابة وصي',
        'orphans.appeal.filed' => 'تسجيل اعتراض', 'orphans.appeal.accept' => 'قبول اعتراض', 'orphans.appeal.reject' => 'رفض اعتراض', 'orphans.appeal.review' => 'بدء مراجعة اعتراض',
        'orphans.appeal.assign' => 'إسناد اعتراض',
        'auth.login' => 'تسجيل دخول إلى النظام',
        'auth.logout' => 'تسجيل خروج من النظام',

        'department.created' => 'إضافة قسم جديد',
        'department.updated' => 'تعديل بيانات قسم',
        'department.activated' => 'تفعيل قسم',
        'department.deactivated' => 'تعطيل قسم',

        'employee.created' => 'إنشاء حساب مستخدم لموظف',
        'employee.updated' => 'تعديل حساب مستخدم (القسم أو الدور أو الحالة)',
        'employee_profile.created' => 'إنشاء ملف موظف',
        'employee_profile.updated' => 'تعديل ملف موظف',
        'employee_profile.activation_code_sent' => 'إرسال رمز تفعيل الحساب برسالة SMS',
        'employee.board_secretary.assigned' => 'إسناد صفة أمين سر مجلس الإدارة',
        'employee.board_secretary.removed' => 'إلغاء صفة أمين سر مجلس الإدارة',
        'user.deleted' => 'حذف حساب مستخدم نهائيًا',
        'role.created' => 'إضافة دور جديد',

        'employee.assignment.added' => 'إضافة تكليف إضافي لموظف (دور في قسم)',
        'employee.assignment.removed' => 'إزالة تكليف إضافي من موظف',
        'employee.account.deactivated' => 'إيقاف حساب دخول (دون حذف، مع إنهاء جلساته)',
        'auth.session_ended_inactive' => 'إنهاء جلسة مفتوحة لحساب موقوف',
        'organization.assignment_history.installed' => 'تفعيل حفظ تاريخ التكليفات والأدوار',
        'hr_action.attachment.downloaded' => 'تنزيل مرفق إجراء إداري (سري)',
        'attendance.permission.attachment_downloaded' => 'تنزيل مرفق طلب إذن',
        'leave.report.exported' => 'تصدير أرصدة إجازات جميع الموظفين',
        'attendance.ledger.exported' => 'تصدير سجل الدوام (Excel)',
        'attendance.employee_ledger.exported' => 'تصدير دوام موظف من ملفه (Excel)',
        'attendance.report.exported' => 'تصدير تقرير الحضور اليومي (CSV)',
        'finance.purchase.relinked_from_reopened_request' => 'إبقاء طلب الشراء القائم لطلب أُعيد فتحه (دون طلب ثانٍ)',
        // Wave 5 W5C: purchase cancellation, rejected deliveries, duplicate-aid decisions, visit reviews, case-study assignment.
        'finance.purchase.cancelled' => 'إلغاء طلب شراء معتمد', 'finance.purchase_order.cancelled' => 'إلغاء أمر شراء صادر لم يُورَّد',
        'finance.purchase_order.remainder_cancelled' => 'إلغاء المتبقي غير المورَّد من أمر شراء', 'finance.goods_receipt.receive_with_rejection' => 'استلام أصناف مع رفض كمية بسبب موثق',
        'orphans.campaign.duplicate_exception_granted' => 'استثناء من تنبيه تكرار المساعدة (رئيس القسم)', 'orphans.campaign.duplicate_resolved' => 'معالجة تنبيه تكرار المساعدة',
        'orphans.assessment.scheduled' => 'جدولة زيارة اجتماعية', 'orphans.assessment.completed' => 'إنجاز زيارة أو تقييم اجتماعي', 'orphans.assessment.cancelled' => 'إلغاء زيارة اجتماعية',
        'orphans.assessment.review_approved' => 'اعتماد رئيس القسم لزيارة اجتماعية', 'orphans.assessment.review_rejected' => 'عدم اعتماد زيارة اجتماعية',
        'orphans.case_study.assigned' => 'تعيين باحث لدراسة الحالة',
        // Engineering 01 R1-B: field API.
        'orphans.campaign.member_revoked' => 'سحب تكليف بحملة (مع إلغاء دفعات التوزيع المفتوحة للمكلف)', 'orphans.field.batch_revoked' => 'إلغاء دفعة توزيع ميداني',
        'access.configuration.installed' => 'تثبيت إعدادات الوصول وقوالب اللوحات',
        'access.permission.granted' => 'منح صلاحية لدور',
        'dashboard_layout.saved' => 'حفظ إصدار جديد لقالب لوحة',
        'dashboard_layout.restored' => 'استرجاع إصدار سابق لقالب لوحة',
        'dashboard_layout.activated' => 'تفعيل قالب لوحة',
        'dashboard_layout.deactivated' => 'تعطيل قالب لوحة',

        'kpi.catalog.installed' => 'تثبيت كتالوج مؤشرات الأداء',
        'kpi.definition.created' => 'إضافة مؤشر أداء',
        'kpi.definition.updated' => 'تعديل ظهور مؤشر أداء أو ترتيبه أو تفعيله',
        'kpi.version.created' => 'إصدار جديد لتعريف مؤشر أداء أو هدفه',
        'kpi.details.viewed' => 'عرض سجلات مؤشر أداء التفصيلية',
        'kpi.snapshot.saved' => 'حفظ قراءة مؤشرات أداء',
        'kpi.snapshot.scheduled' => 'قراءة مؤشرات أداء دورية مجدولة',

        'career_scale.created' => 'إضافة سلم وظيفي',
        'career_scale.updated' => 'تعديل سلم وظيفي',
        'job_grade.created' => 'إضافة درجة وظيفية',
        'job_grade.updated' => 'تعديل درجة وظيفية',
        'job_category.created' => 'إضافة فئة وظيفية',
        'job_category.updated' => 'تعديل فئة وظيفية',
        'job_title.created' => 'إضافة مسمى وظيفي',
        'job_title.updated' => 'تعديل مسمى وظيفي',

        'master_data.group.created' => 'إضافة مجموعة ثوابت',
        'master_data.group.updated' => 'تعديل مجموعة ثوابت',
        'master_data.type.created' => 'إضافة نوع ثابت',
        'master_data.type.updated' => 'تعديل نوع ثابت',
        'master_data.value.created' => 'إضافة قيمة إلى الثوابت',
        'master_data.value.updated' => 'تعديل قيمة في الثوابت',

        'custom_field.created' => 'إضافة حقل ديناميكي',
        'custom_field.updated' => 'تعديل حقل ديناميكي',
        'navigation.item.created' => 'إضافة عنصر إلى القائمة الجانبية',
        'navigation.item.updated' => 'تعديل عنصر في القائمة الجانبية',
        'navigation.layout.saved' => 'حفظ تنظيم القائمة الرئيسية',
        'organization.structure.installed' => 'تهيئة الهيكل التنظيمي والوحدات والتفويض',
        'organization.structure.applied' => 'تطبيق تعديل على الهيكل التنظيمي',
        'organization.change.proposed' => 'رفع مقترح تعديل الهيكل',
        'organization.change.submit' => 'رفع مقترح تعديل الهيكل إلى المدير التنفيذي',
        'organization.change.approve' => 'اعتماد تعديل الهيكل',
        'organization.change.reject' => 'رفض تعديل الهيكل',
        'organization.change.return' => 'إعادة مقترح تعديل الهيكل للاستكمال',
        'organization.change.withdrawn' => 'سحب مقترح تعديل الهيكل',
        'organization.change.dismissed' => 'استبعاد اقتراح وحدة',
        'organization.details.updated' => 'تعديل وصف قسم أو وحدة ومسؤولياتها',
        'organization.head.assigned' => 'تعيين رئيس قسم أو مكتب',
        'organization.unit.assigned' => 'توزيع موظف على وحدة وظيفية',
        'organization.unit.ended' => 'إنهاء توزيع موظف على وحدة',
        'organization.delegation.created' => 'تفويض صلاحية مؤقتة في نطاق محدد',
        'organization.delegation.revoked' => 'إلغاء تفويض صلاحية',
        'organization.delegation_policy.updated' => 'تعديل سياسة الصلاحيات القابلة للتفويض',
        'navigation.layout.reset' => 'استعادة التنظيم الافتراضي للقائمة الرئيسية',
        'navigation.registry.synced' => 'مزامنة القائمة مع سجل صفحات النظام',
        'navigation.group.created' => 'إضافة مجموعة إلى القائمة الرئيسية',
        'navigation.group.deleted' => 'حذف مجموعة فارغة من القائمة الرئيسية',
        'navigation.item.retired' => 'إزالة عنوان فرعي قديم من القائمة',
        'form_draft.saved' => 'حفظ مسودة نموذج تلقائيًا',

        'workflow.definition.created' => 'إنشاء مسار عمل',
        'workflow.definition.updated' => 'تعديل بيانات مسار عمل',
        'workflow.version.drafted' => 'فتح مسودة جديدة لمسار عمل',
        'workflow.version.published' => 'نشر إصدار مسار عمل',
        'workflow.instance.migrated' => 'نقل معاملة مفتوحة إلى إصدار المسار المعتمد',
        'workflow.instance.reopened' => 'إعادة فتح معاملة منجزة',
        'workflow.instance.assigned' => 'إسناد مرحلة معاملة إلى موظف', 'workflow.instance.assignment_withdrawn' => 'سحب إسناد مرحلة معاملة',
        'workflow.instance.rerouted' => 'إعادة توجيه معاملة مفتوحة إلى صاحب الصلاحية الحالي',
        'workflow.instance.holder_missing' => 'معاملة مفتوحة بلا صاحب صلاحية مؤهل',
        'workflow.task.assigned' => 'إسناد مهمة في مسار عمل', 'workflow.task.assignment_withdrawn' => 'سحب إسناد مهمة في مسار عمل',
        'workflow.attachment.uploaded' => 'إرفاق ملف بطلب', 'workflow.attachment.download' => 'تنزيل مرفق طلب',
        'workflow.request.edited' => 'تعديل طلب مُعاد قبل إعادة إرساله',
        'workflow.fields.cleared' => 'تفريغ بيانات إجراء أُلغي أو أُعيد',
        'dashboard.personal.installed' => 'تثبيت مساحتي الموحدة وقائمة المهام الشخصية',
        'workflow.step.created' => 'إضافة مرحلة إلى مسار عمل',
        'workflow.step.updated' => 'تعديل مرحلة في مسار عمل',
        'workflow.step.deleted' => 'حذف مرحلة من مسار عمل',
        'workflow.transition.created' => 'إضافة إجراء (انتقال) إلى مسار عمل',
        'workflow.transition.updated' => 'تعديل إجراء (انتقال) في مسار عمل',
        'workflow.transition.deleted' => 'حذف إجراء (انتقال) من مسار عمل',

        'leave_settings.updated' => 'تعديل إعدادات الإجازات',
        'leave_holiday.added' => 'إضافة عطلة رسمية',
        'leave_holiday.removed' => 'حذف عطلة رسمية',
        'leave_entitlement_rule.created' => 'إضافة سياسة استحقاق إجازة',
        'leave_entitlement_rule.updated' => 'تعديل سياسة استحقاق إجازة',
        'leave_policy.launched' => 'إطلاق سياسة الإجازات المعتمدة',
        'leave_policy.development_data_cleanup' => 'تنظيف بيانات تجريبية لسياسة الإجازات',
        'leave_policy_correction.created' => 'تسجيل تصحيح لسياسة الإجازات',
        'leave_policy_correction.applied' => 'تطبيق تصحيح سياسة الإجازات',
        'leave_opening_balance.recorded' => 'تسجيل رصيد إجازة افتتاحي',
        'leave_prorata.proposed' => 'اقتراح معايير الاحتساب النسبي للإجازة',
        'leave_prorata.approved' => 'اعتماد سياسة الاحتساب النسبي (المدير التنفيذي)',
        'leave_prorata.returned' => 'إعادة مقترح الاحتساب النسبي',
        'notification_setting.updated' => 'تعديل إعدادات إشعار',
        'approval_delegation.issued' => 'إصدار تفويض إداري (المدير التنفيذي)',
        'approval_delegation.revoked' => 'إلغاء أو انتهاء تفويض إداري',

        'financial_settings.base_currency.updated' => 'تغيير العملة الأساسية',
        'financial_settings.limit.updated' => 'تعديل سقف صلاحية مالية',

        'rental.property.created' => 'إضافة عقار',
        'rental.floor.created' => 'إضافة طابق',
        'rental.unit.created' => 'إضافة وحدة إيجارية',
        'rental.tenant.created' => 'إضافة مستأجر',
        'rental.application.created' => 'تسجيل طلب استئجار',
        'rental.application.decided' => 'البت في طلب استئجار',
        'rental.contract.created' => 'إنشاء عقد إيجار',
        'rental.contract.signed_uploaded' => 'رفع نسخة العقد الموقّعة',
        'rental.due.custom.created' => 'إضافة مستحق إيجار مخصص',
        'rental.meter.reading' => 'تسجيل قراءة عداد',
        'rental.notice.created' => 'إصدار إشعار لمستأجر',
        'rental.case.created' => 'فتح قضية متابعة إيجار',
        'rental.report.export' => 'تصدير تقرير الإيجارات (مع السبب)',
        'rentals.module.completed' => 'تثبيت استكمال ملف الأصول والإيجارات',
        'rental.property.updated' => 'تعديل بيانات عقار',
        'rental.unit.updated' => 'تعديل بيانات وحدة إيجارية',
        'rental.unit.status' => 'تغيير حالة وحدة إيجارية',
        'rental.tenant.updated' => 'تعديل ملف مستأجر',
        'rental.application.updated' => 'تعديل طلب استئجار',
        'rental.application.submit' => 'رفع طلب استئجار للمدير التنفيذي',
        'rental.application.resubmit' => 'إعادة رفع طلب استئجار من قائمة الانتظار',
        'rental.application.approve' => 'قبول طلب استئجار',
        'rental.application.return' => 'إعادة طلب استئجار للاستكمال',
        'rental.application.waitlist' => 'وضع طلب استئجار في قائمة الانتظار',
        'rental.application.reject' => 'رفض طلب استئجار',
        'rental.application.withdraw' => 'إغلاق طلب استئجار دون تعاقد',
        'rental.contract.updated' => 'تعديل مسودة عقد إيجار',
        'rental.contract.submit' => 'رفع عقد إيجار للاعتماد',
        'rental.contract.approve' => 'اعتماد عقد إيجار',
        'rental.contract.return' => 'إعادة عقد إيجار للتعديل',
        'rental.contract.reject' => 'رفض عقد إيجار',
        'rental.contract.activate' => 'تفعيل عقد إيجار',
        'rental.contract.cancel' => 'إلغاء عقد إيجار قبل التفعيل',
        'rental.due.custom.removed' => 'حذف استحقاق من مسودة عقد',
        'rental.termination.created' => 'فتح معاملة إنهاء وإخلاء',
        'rental.termination.updated' => 'تعديل معاملة إنهاء وإخلاء',
        'rental.termination.handover' => 'تسجيل محضر الاستلام والإخلاء',
        'rental.termination.submit' => 'إحالة الإنهاء للفحص',
        'rental.termination.inspected' => 'تسجيل نتيجة فحص العين',
        'rental.termination.cleared' => 'مخالصة الحسابات للإنهاء',
        'rental.termination.approve' => 'اعتماد إنهاء عقد إيجار',
        'rental.termination.return' => 'إعادة معاملة الإنهاء',
        'rental.termination.reject' => 'رفض إنهاء عقد إيجار',
        'rental.termination.complete' => 'إتمام الإنهاء وإعادة حالة العين',
        'rental.settlement.created' => 'طلب تسوية إيجار',
        'rental.settlement.updated' => 'تعديل طلب تسوية إيجار',
        'rental.settlement.submit' => 'إرسال تسوية إيجار للحسابات',
        'rental.settlement.recommend' => 'توصية الحسابات بتسوية إيجار',
        'rental.settlement.approve' => 'اعتماد تسوية إيجار',
        'rental.settlement.return' => 'إعادة تسوية إيجار',
        'rental.settlement.reject' => 'رفض تسوية إيجار',
        'rental.settlement.post' => 'ترحيل تسوية إيجار من البرنامج المحاسبي',
        'rental.receipt.recorded' => 'تسجيل قبض إيجار من البرنامج المحاسبي',
        'rental.receipt.reversed' => 'عكس قبض إيجار مسجل',
        'rental.receipt.voucher_corrected' => 'تصحيح بيانات سند قبض إيجار غير مستخدم',
        'rental.document.uploaded' => 'رفع مستند في ملف الإيجارات',
        'rental.document.downloaded' => 'تنزيل مستند من ملف الإيجارات',
        'rental.maintenance.opened' => 'فتح طلب صيانة من ملف الإيجارات',
        'rental.notice.issued' => 'إصدار إنذار لمستأجر',
        'rental.settings.updated' => 'تعديل مدد تنبيه الإيجارات',
        'rental.maintenance.unit_released' => 'إعادة عين شاغرة بعد إغلاق طلب صيانتها',
        'rental.maintenance.cost_recorded' => 'تحديد من يتحمل تكلفة صيانة عين مؤجرة',
        'finance.receivable.cancelled' => 'إلغاء مستحق في الحسابات من القسم المصدر',
        'finance.receivable.adjusted' => 'تعديل مستحق في الحسابات بتسوية معتمدة من القسم المصدر',

        'hr.employee_master_profile.installed' => 'تفعيل الملف الشامل للموظف والاستيراد',
        'hr.employee_import.staged' => 'رفع ملف موظفين للمراجعة (دون كتابة في الملفات)',
        'hr.employee_import.mapping' => 'ربط أعمدة ملف استيراد الموظفين',
        'hr.employee_import.options' => 'تعديل خيارات دفعة استيراد الموظفين',
        'hr.employee_import.values' => 'ربط قيم مرجعية في استيراد الموظفين',
        'hr.employee_import.row_action' => 'تحديد إجراء صف في استيراد الموظفين',
        'hr.employee_import.committed' => 'تنفيذ دفعة استيراد موظفين',
        'hr.employee_import.failed' => 'تعذر تنفيذ جزء من دفعة استيراد',
        'hr.employee_import.reverted' => 'التراجع عن دفعة استيراد موظفين',
        'hr.employee_import.revert_started' => 'بدء التراجع عن دفعة استيراد موظفين',
        'hr.employee_import.revert_failed' => 'تعذر إكمال التراجع عن دفعة استيراد',
        'hr.employee_import.cancelled' => 'إلغاء دفعة استيراد موظفين',
        'hr.employee_import.report_downloaded' => 'تنزيل تقرير مشكلات الاستيراد',
        'hr.employee_files.settings' => 'تعديل إعدادات صور الموظفين ووثائقهم',
        'attendance.punch.time_review_resolved' => 'تأكيد وقت حركة بصمة ملتبس بتغيير التوقيت',
        'attendance.punch.manual_created' => 'تسجيل حركة بصمة يدويًا',
        'schema.column.added' => 'إضافة حقل إلى جدول (ترحيل)',
        'schema.constraint.updated' => 'تعديل قيد على جدول (ترحيل)',
        'hr.action.cancel_requested' => 'طلب إلغاء إجراء إداري من المدير التنفيذي',
        'hr.action.cancel_request_declined' => 'إبقاء إجراء إداري ورفض طلب إلغائه',
        'correspondence.original_shared' => 'مشاركة أصل مراسلة مع مستلم جديد',
        'employee_file_change.submitted' => 'إعداد تعديل على ملف موظف لاعتماد رئيس الموارد البشرية',
        'employee_file_change.resubmitted' => 'إعادة إرسال تعديل ملف موظف بعد إرجاعه',
        'employee_file_change.approved' => 'اعتماد تعديل ملف موظف وتطبيقه',
        'employee_file_change.returned' => 'إرجاع تعديل ملف موظف لمعدّه',
        'employee_file_change.withdrawn' => 'سحب تعديل ملف موظف قبل اعتماده',
        'orphans.sensitive_change.requested' => 'طلب تعديل حساس في سجل الأيتام',
        'orphans.sensitive_change.approved' => 'اعتماد تعديل حساس في سجل الأيتام وتطبيقه',
        'orphans.sensitive_change.rejected' => 'رفض تعديل حساس في سجل الأيتام',
        'orphans.sponsorship.referred_dues_cancelled' => 'إلغاء استحقاقات كفالة محالة للحسابات بعد إنهائها',
        'board_decision.recorded' => 'تسجيل قرار مجلس الإدارة في السجل',
        'board_decision.corrected' => 'تصحيح بيانات قرار مجلس الإدارة قبل تسجيله',
        'board_decision.assigned' => 'تكليف قسم بتنفيذ قرار مجلس الإدارة',
        'board_decision.assignment_changed' => 'تحديث حالة تكليف بتنفيذ قرار مجلس الإدارة',
        'board_decision.closed' => 'إغلاق قرار مجلس الإدارة',
        'maintenance.addendum.opened' => 'فتح ملحق لأمر صيانة',
        'maintenance.addendum.separated' => 'فصل ملحق صيانة عن الأمر الأصلي',
        'correspondence.confidential_access.granted' => 'منح اطلاع استثنائي على مراسلة سرية',
        'correspondence.confidential_access.revoked' => 'إلغاء اطلاع استثنائي على مراسلة سرية',
        'correspondence.confidential_access.used' => 'اطلاع على مراسلة سرية بتفويض استثنائي',
        'employee_profile.imported' => 'إنشاء ملف موظف من الاستيراد',
        'employee_profile.import_updated' => 'تحديث ملف موظف من الاستيراد',
        'employee_profile.import_reverted' => 'حذف ملف أنشأه استيراد متراجع عنه',
        'employee_profile.import_update_reverted' => 'استرجاع قيم ملف بعد التراجع عن الاستيراد',
        'employee_profile.account_linked' => 'ربط ملف موظف بحساب دخول قائم',
        'employee_profile.account_created' => 'إنشاء حساب دخول لملف موظف',
        'employee_profile.list_exported' => 'تصدير قائمة الموظفين (بيانات وظيفية فقط)',
        'employee_photo.replaced' => 'تحديث الصورة الشخصية لموظف',
        'employee_photo.submitted' => 'رفع صورة شخصية للمراجعة',
        'employee_photo.approved' => 'اعتماد صورة شخصية',
        'employee_photo.rejected' => 'رفض صورة شخصية',
        'employee_photo.removed' => 'إزالة الصورة الشخصية',
        'employee_document.uploaded' => 'رفع وثيقة موظف',
        'employee_document.versioned' => 'رفع إصدار جديد لوثيقة موظف',
        'employee_document.verified' => 'توثيق وثيقة موظف',
        'employee_document.rejected' => 'رفض وثيقة موظف',
        'employee_document.archived' => 'أرشفة وثيقة موظف',
        'employee_document.downloaded' => 'تنزيل وثيقة موظف',
        'employee_pay_change.requested' => 'طلب تعديل راتب أو حساب بنكي',
        'employee_pay_change.revised' => 'تصحيح طلب تعديل راتب',
        'employee_pay_change.submit' => 'رفع طلب تعديل راتب للمدير التنفيذي',
        'employee_pay_change.approve' => 'اعتماد تعديل راتب أو حساب بنكي',
        'employee_pay_change.reject' => 'رفض تعديل راتب أو حساب بنكي',
        'employee_pay_change.return' => 'إعادة طلب تعديل راتب للموارد البشرية',
        'employee_pay_change.withdraw' => 'سحب طلب تعديل راتب',
    ];

    /** @var array<string, string> */
    private const ENTITIES = [
        'auth' => 'الدخول',
        'department' => 'قسم',
        'employee' => 'حساب مستخدم',
        'employee_profile' => 'ملف موظف',
        'career_scale' => 'سلم وظيفي',
        'job_grade' => 'درجة وظيفية',
        'job_category' => 'فئة وظيفية',
        'job_title' => 'مسمى وظيفي',
        'master_data' => 'الثوابت',
        'custom_field' => 'حقل ديناميكي',
        'navigation' => 'القائمة الجانبية',
        'form_draft' => 'مسودة نموذج',
        'workflow' => 'مسار عمل',
        'leave_settings' => 'إعدادات الإجازات',
        'leave_holiday' => 'عطلة رسمية',
        'leave_entitlement_rule' => 'سياسة استحقاق إجازة',
        'leave_policy' => 'سياسة الإجازات',
        'leave_policy_correction' => 'تصحيح سياسة الإجازات',
        'leave_opening_balance' => 'رصيد إجازة افتتاحي',
        'leave_prorata' => 'الاحتساب النسبي للإجازة',
        'notification_setting' => 'إعدادات الإشعارات',
        'approval_delegation' => 'تفويض إداري',
        'financial_settings' => 'الإعدادات المالية',
        'rental' => 'الإيجارات',
    ];

    /** @var array<string, string> */
    private const VERBS = [
        'created' => 'إضافة',
        'updated' => 'تعديل',
        'deleted' => 'حذف',
        'removed' => 'حذف',
        'added' => 'إضافة',
        'activated' => 'تفعيل',
        'deactivated' => 'تعطيل',
        'published' => 'نشر',
        'drafted' => 'فتح مسودة',
        'saved' => 'حفظ',
        'applied' => 'تطبيق',
        'launched' => 'إطلاق',
        'decided' => 'البت في',
        'export' => 'تصدير',
        'approved' => 'اعتماد',
        'rejected' => 'رفض',
        'submitted' => 'إرسال',
    ];

    /** @var array<string, string> */
    private const SUBJECTS = [
        'App\Models\Department' => 'قسم',
        'App\Models\User' => 'حساب مستخدم',
        'App\Models\CurrencySetting' => 'إعداد العملة',
        'App\Models\FinancialAuthorityLimit' => 'سقف صلاحية مالية',
        'App\Models\FormDraft' => 'مسودة نموذج',
        'employees' => 'ملف موظف',
        'career_scales' => 'سلم وظيفي',
        'job_grades' => 'درجة وظيفية',
        'job_categories' => 'فئة وظيفية',
        'job_titles' => 'مسمى وظيفي',
        'master_data_groups' => 'مجموعة ثوابت',
        'master_data_types' => 'نوع ثابت',
        'master_data_values' => 'قيمة ثابت',
        'custom_field_definitions' => 'حقل ديناميكي',
        'navigation_items' => 'عنصر قائمة',
        'workflow_definitions' => 'مسار عمل',
        'workflow_versions' => 'إصدار مسار عمل',
        'workflow_steps' => 'مرحلة مسار',
        'workflow_transitions' => 'إجراء مسار',
        'workflow_event_attachments' => 'مرفق معاملة',
        'aims_requests' => 'طلب',
        'leave_module_settings' => 'إعدادات الإجازات',
        'approval_delegations' => 'تفويض إداري',
        'leave_entitlement_rules' => 'سياسة استحقاق',
        'leave_policy_drafts' => 'مسودة سياسة إجازات',
        'leave_policy_corrections' => 'تصحيح سياسة إجازات',
        'employee_leave_opening_balances' => 'رصيد افتتاحي',
        'notification_settings' => 'إعداد إشعار',
        'aims_rental_dues' => 'مستحقات الإيجار',
        'aims_rental_contracts' => 'عقود الإيجار',
        'employee_import_batches' => 'دفعة استيراد موظفين',
        'employee_photos' => 'صورة موظف',
        'employee_documents' => 'وثيقة موظف',
        'employee_pay_changes' => 'طلب تعديل راتب',
        'hr_employee_file_settings' => 'إعدادات ملفات الموظفين',
    ];

    public function label(string $action): string
    {
        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action];
        }

        $parts = explode('.', $action);
        $verb = self::VERBS[end($parts)] ?? null;
        $entity = self::ENTITIES[$parts[0]] ?? null;

        if ($verb !== null && $entity !== null) {
            return "{$verb} {$entity}";
        }

        return $entity !== null ? "عملية على {$entity} ({$action})" : $action;
    }

    public function subjectLabel(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        return self::SUBJECTS[$subjectType] ?? class_basename($subjectType);
    }

    /**
     * The readable name of the changed record, taken from the stored snapshot.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function subjectName(array $metadata): ?string
    {
        foreach (['after', 'before'] as $side) {
            $snapshot = $metadata[$side] ?? null;

            if (! is_array($snapshot)) {
                continue;
            }

            foreach (['name_ar', 'name', 'label_ar', 'title', 'date', 'code'] as $key) {
                if (! empty($snapshot[$key]) && is_scalar($snapshot[$key])) {
                    return (string) $snapshot[$key];
                }
            }
        }

        return isset($metadata['date']) && is_scalar($metadata['date']) ? (string) $metadata['date'] : null;
    }

    /**
     * @param  iterable<string>  $actions
     * @return list<array{value: string, label: string}>
     */
    public function options(iterable $actions): array
    {
        $options = [];

        foreach ($actions as $action) {
            $options[] = ['value' => $action, 'label' => $this->label($action)];
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }
}
