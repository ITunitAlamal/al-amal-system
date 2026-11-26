<?php

namespace App\Services\Finance;

/**
 * The finance permission keys grouped by functional area of «قسم الحسابات», with their Arabic display names.
 * The keys already exist in `permissions`; this catalog only labels and groups them for the system administrator's
 * per-user grant/revoke screen and for the department's navigation. No permission is inherited across areas.
 */
final class FinancePermissionCatalog
{
    /**
     * area code => [Arabic area name, [permission code => Arabic label]].
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const AREAS = [
        'general' => ['عام', [
            'finance.view' => 'الدخول إلى قسم الحسابات',
            'finance.reports.view' => 'عرض تقارير الحسابات (ضمن مجال صلاحياته)',
            'finance.report.export' => 'تصدير التقارير وطباعتها (ضمن مجال صلاحياته)',
        ]],
        'procurement' => ['المشتريات', [
            'finance.procurement.manage' => 'إعداد طلبات الشراء وعروض الأسعار وأوامر الشراء والموردين',
            'finance.procurement.review' => 'مراجعة طلبات الشراء (رئيس قسم الحسابات)',
            'finance.procurement.director_approve' => 'اعتماد المدير التنفيذي لطلبات الشراء',
            'finance.procurement.board_approve' => 'اعتماد أمين صندوق مجلس الإدارة لطلبات الشراء',
            'finance.procurement.policy' => 'سياسة الحد الأدنى لعروض الأسعار',
        ]],
        'stores' => ['المخازن', [
            'finance.warehouse.manage' => 'تعريف المخازن والأصناف',
            'finance.purchase.receive' => 'استلام أصناف أوامر الشراء',
            'finance.stock.receive' => 'إدخال المخزون والمرتجعات الداخلة',
            'finance.stock.issue' => 'صرف المخزون والمرتجعات الخارجة والنقل بين المخازن',
            'finance.stock.count' => 'إعداد محاضر الجرد',
            'finance.stock.count.approve' => 'اعتماد محاضر الجرد وتسوية الفروقات',
        ]],
        'accountant' => ['المحاسب العام', [
            'finance.purchase.invoice' => 'تسجيل فواتير الموردين ورقم سند الصرف',
            'finance.rentals.record' => 'تسجيل قبض الإيجارات وترحيل تسوياتها',
            'finance.sponsorships.record' => 'تسجيل تحصيلات وصرف الكفالات والتبرعات',
            'finance.treasury.manage' => 'الصندوق والبنوك',
            'finance.transaction.draft' => 'إعداد سند قبض وسند صرف (مسودة وإرسال)',
            'finance.transaction.review' => 'مراجعة سندات القبض والصرف',
            'finance.transaction.approve' => 'اعتماد سندات القبض والصرف',
            'finance.transaction.execute' => 'تنفيذ سندات القبض والصرف (الترحيل)',
            'finance.reversal.request' => 'طلب عكس سند منفّذ',
            'finance.reversal.approve' => 'اعتماد عكس السندات',
            'finance.receivable.manage' => 'المستحقات',
            'finance.cheque.manage' => 'الشيكات',
            'finance.transfer.execute' => 'التحويل بين الصناديق والبنوك',
            'finance.transfer.approve' => 'اعتماد التحويلات',
            'finance.reconcile' => 'المطابقة البنكية',
            'finance.advance.manage' => 'العهد والسلف',
        ]],
        'payroll' => ['مسير الرواتب', [
            'finance.payroll.manage' => 'إعداد مسير الرواتب وربط الصرف',
            'finance.payroll.approve' => 'اعتماد مسير الرواتب',
        ]],
        'budgets' => ['الموازنات والتمويل', [
            'finance.budget.manage' => 'إعداد الموازنات',
            'finance.budget.transfer' => 'طلب مناقلات واستثناءات الموازنة',
            'finance.budget.approve' => 'اعتماد الموازنة ومناقلاتها',
            'finance.fund.manage' => 'التمويل المخصص وحركاته',
            'finance.fund.approve' => 'اعتماد حركات التمويل المخصص',
        ]],
    ];

    /**
     * Pairs one person should not hold together (preparation versus approval or execution). The workflows already
     * refuse a decision on one's own record; the pairs are shown to the administrator as a warning.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const CONFLICTS = [
        ['finance.procurement.manage', 'finance.procurement.review'],
        ['finance.procurement.manage', 'finance.purchase.receive'],
        ['finance.procurement.manage', 'finance.purchase.invoice'],
        ['finance.purchase.receive', 'finance.purchase.invoice'],
        ['finance.transaction.draft', 'finance.transaction.approve'],
        ['finance.transaction.draft', 'finance.transaction.execute'],
        ['finance.stock.count', 'finance.stock.count.approve'],
        ['finance.payroll.manage', 'finance.payroll.approve'],
        ['finance.transfer.execute', 'finance.transfer.approve'],
        ['finance.fund.manage', 'finance.fund.approve'],
        ['finance.budget.transfer', 'finance.budget.approve'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_merge(...array_map(fn (array $area): array => array_keys($area[1]), array_values(self::AREAS)));
    }

    public static function label(string $code): string
    {
        foreach (self::AREAS as [, $permissions]) {
            if (isset($permissions[$code])) {
                return $permissions[$code];
            }
        }

        return $code;
    }

    /**
     * @param  list<string>  $held
     * @return list<array{0: string, 1: string}>
     */
    public static function conflictsIn(array $held): array
    {
        return array_values(array_filter(self::CONFLICTS, fn (array $pair): bool => in_array($pair[0], $held, true) && in_array($pair[1], $held, true)));
    }
}
