<?php

namespace App\Services\Configuration;

use App\Models\User;
use App\Services\Departments\DepartmentWorkspace;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use App\Services\Hr\HrServicesDashboard;
use App\Services\Kpi\KpiScope;
use App\Services\Organization\StructureChanges;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named visibility rules a menu item can reference.
 *
 * Rules only decide whether a link is shown; every route still enforces its
 * own authorization. Besides the named rules below, "permission:<code>" shows
 * the item to anyone holding that permission in any scope.
 */
final class NavigationAccessRules
{
    private const PERMISSION_PREFIX = 'permission:';

    /**
     * "finance:<code>|<code>…" shows the item when FinanceGuard allows any of the listed Finance permissions: the
     * role permission plus an active Finance assignment, and never a bookkeeping permission while Accounts run the
     * documentary cycle. It is the same check the Finance routes make.
     */
    private const FINANCE_PREFIX = 'finance:';

    /** Finance permission sets too long for one access_rule; they mirror the sections of the Finance page. */
    public const FINANCE_SETS = [
        'finance_cycle' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
            'finance.purchase.receive', 'finance.purchase.invoice', 'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue', 'finance.stock.count', 'finance.stock.count.approve'],
        'finance_purchasing' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve'],
    ];

    public function __construct(
        private readonly FinanceGuard $financeGuard,
        private readonly OrphanAccess $orphanAccess,
    ) {}

    /**
     * @return array<string, string> rule key => Arabic label
     */
    public function namedRules(): array
    {
        return [
            'authenticated' => 'كل مستخدم نشط',
            'kpi_view' => 'مؤشرات الأداء (الإدارة العليا ورؤساء الأقسام وأصحاب صلاحية kpi.view)',
            'department_workspace' => '«قسمي» (رئيس القسم المعيّن في الهيكل بصلاحيات رئيس القسم)',
            'system_admin' => 'مدير النظام',
            'manage_employees' => 'إدارة الموظفين والهيكل',
            'org_structure' => 'الهيكل التنظيمي (مدير النظام ورئيس الموارد البشرية والمدير التنفيذي ورؤساء الأقسام)',
            'attendance_access' => 'الحضور (إدارة الموظفين أو المدير التنفيذي)',
            'hr_dashboard' => 'لوحة الموارد البشرية والخدمات العامة (رئيس الموارد البشرية ومدير النظام والمدير التنفيذي)',
            'leave_requester' => 'موظف مكتمل الملف ونشط',
            'employee_profile' => 'صاحب ملف موظف مرتبط بحسابه («ملفي الوظيفي»)',
            'finance_view' => 'الاطلاع على الحسابات',
            'finance_cycle' => 'الدورة المستندية في الحسابات (المشتريات أو الاستلام أو الفواتير أو المخازن)',
            'finance_purchasing' => 'طلبات الشراء (إعدادها أو مراجعتها أو اعتمادها)',
            'finance_approver' => 'صندوق الاعتمادات المالية (المعتمدون الماليون)',
            'orphans_review' => 'مراجعة طلبات الأوصياء',
            'orphans_delegations' => 'إدارة تفويضات الأيتام',
            'orphans_imports' => 'استيراد الكشوف (تفويض «استيراد الكشوف» في قسم الكفالات)',
            'orphans_settings' => 'إعدادات قسم الكفالات (مدير النظام والمدير التنفيذي ورئيس القسم)',
            'orphans_sponsorships' => 'الكفالات والتبرعات والمساعدات (موظفو الكفالات المفوضون والمدير التنفيذي)',
            'relief_campaigns' => 'الحملات الإغاثية (موظفو الكفالات المفوضون والمدير التنفيذي والمكلفون بحملة محددة)',
            'relief_devices' => 'أجهزة التوزيع الميداني (رئيس قسم الكفالات ومدير النظام)',
            'orphans_aid' => 'الكفالات والمساعدات (من يفتح الكفالات أو الحملات الإغاثية)',
            'approval_delegations' => 'التفويضات الإدارية (المدير التنفيذي ومدير النظام والمفوَّضون)',
        ];
    }

    /**
     * @return array<string, string> every selectable rule, including permission-based ones
     */
    public function selectableRules(): array
    {
        $rules = $this->namedRules();

        foreach (DB::table('permissions')->orderBy('code')->get(['code', 'name']) as $permission) {
            $rules[self::PERMISSION_PREFIX.$permission->code] = 'صلاحية: '.($permission->name ?: $permission->code);
            if (str_starts_with($permission->code, 'finance.')) {
                $rules[self::FINANCE_PREFIX.$permission->code] = 'صلاحية مالية مع تخصيص في «صلاحيات الحسابات»: '.($permission->name ?: $permission->code);
            }
        }

        return $rules;
    }

    public function isKnown(string $rule): bool
    {
        if (str_starts_with($rule, self::PERMISSION_PREFIX)) {
            return DB::table('permissions')->where('code', substr($rule, strlen(self::PERMISSION_PREFIX)))->exists();
        }
        if (str_starts_with($rule, self::FINANCE_PREFIX)) {
            $codes = $this->financeCodes($rule);

            return $codes !== [] && DB::table('permissions')->whereIn('code', $codes)->count() === count($codes);
        }

        return array_key_exists($rule, $this->namedRules());
    }

    public function allows(User $user, string $rule): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (str_starts_with($rule, self::PERMISSION_PREFIX)) {
            return $user->hasPermissionInAnyScope(substr($rule, strlen(self::PERMISSION_PREFIX)));
        }
        if (str_starts_with($rule, self::FINANCE_PREFIX)) {
            return $this->anyFinance($user, $this->financeCodes($rule));
        }

        return match ($rule) {
            'finance_cycle', 'finance_purchasing' => $this->anyFinance($user, self::FINANCE_SETS[$rule]),
            'finance_approver' => app(FinanceApprovalQueue::class)->isApprover($user),
            'kpi_view' => KpiScope::resolve($user) !== null,
            'department_workspace' => app(DepartmentWorkspace::class)->departments($user) !== [],
            'authenticated' => true,
            'system_admin' => $user->isSystemAdmin(),
            'manage_employees' => $user->canManageEmployees(),
            'org_structure' => $user->canManageOrganization() || $this->hasRole($user, 'executive_director') || app(StructureChanges::class)->headedDepartments($user) !== [],
            'attendance_access' => $user->canManageEmployees() || $this->hasRole($user, 'executive_director'),
            'hr_dashboard' => HrServicesDashboard::canView($user),
            'leave_requester' => $this->isActiveEmployee($user),
            'employee_profile' => $user->employeeProfile()->exists(),
            'finance_view' => $this->financeGuard->allows($user, 'finance.view'),
            'orphans_review' => $this->orphanAccess->allows($user, 'view'),
            'orphans_delegations' => $this->orphanAccess->canManageDelegations($user),
            'orphans_imports' => $this->orphanAccess->allows($user, 'imports'),
            'orphans_settings' => $user->isSystemAdmin() || $this->orphanAccess->isExecutive($user) || $this->orphanAccess->isHead($user),
            'orphans_sponsorships' => $this->orphanAccess->allows($user, 'sponsorships') || $this->orphanAccess->isExecutive($user),
            'relief_campaigns' => $this->orphanAccess->allows($user, 'campaigns') || $this->orphanAccess->isExecutive($user)
                || DB::table('relief_campaign_members')->where('user_id', $user->id)->whereNull('revoked_at')->where('ends_on', '>=', today()->toDateString())->exists(),
            'relief_devices' => $user->isSystemAdmin() || $this->orphanAccess->isHead($user),
            // «الكفالات والمساعدات»: one entry for whoever may open sponsorships or relief campaigns.
            'orphans_aid' => $this->allows($user, 'orphans_sponsorships') || $this->allows($user, 'relief_campaigns'),
            'approval_delegations' => $user->isSystemAdmin() || $this->hasRole($user, 'executive_director')
                || DB::table('approval_delegations')->where('delegate_user_id', $user->id)->where('status', 'active')->exists(),
            default => false,
        };
    }

    /**
     * Plain-language reason a rule denies the user, for the administrator's diagnosis; «متاح» when it allows.
     */
    public function explain(User $user, string $rule): string
    {
        if ($this->allows($user, $rule)) {
            return 'متاح';
        }
        if (! $user->is_active) {
            return 'الحساب غير نشط.';
        }
        if (str_starts_with($rule, self::FINANCE_PREFIX) || isset(self::FINANCE_SETS[$rule])) {
            $codes = self::FINANCE_SETS[$rule] ?? $this->financeCodes($rule);
            $names = DB::table('permissions')->whereIn('code', $codes)->pluck('name')->implode('، ');

            return $this->financeGuard->documentaryMode() && array_intersect($codes, FinanceGuard::ACCOUNTING_PERMISSIONS) === $codes
                ? 'قسم محاسبي مخفي: الحسابات تعمل بالدورة المستندية والقيود في البرنامج المحاسبي الخارجي.'
                : 'يتطلب إحدى الصلاحيات المالية ('.$names.') عبر دور مسند، مع تخصيص نشط في «صلاحيات الحسابات».';
        }
        if (str_starts_with($rule, self::PERMISSION_PREFIX)) {
            $code = substr($rule, strlen(self::PERMISSION_PREFIX));
            $name = DB::table('permissions')->where('code', $code)->value('name') ?? $code;
            $owners = array_keys(array_filter(User::DEPARTMENT_HEAD_PERMISSIONS, fn (array $codes): bool => in_array($code, $codes, true)));
            $viaHead = $owners === [] ? '' : '، أو رئاسة قسم «'.implode('» أو «', DB::table('departments')->whereIn('code', $owners)->pluck('name')->all()).'» في الهيكل مع دور «رئيس قسم» فيه';

            return 'يتطلب صلاحية «'.$name.'» عبر دور مسند للمستخدم'.$viaHead.'.';
        }

        return match ($rule) {
            'system_admin' => 'مخصص لمدير النظام.',
            'kpi_view' => 'ليس من الإدارة العليا ولا رئيسًا معيّنًا لقسم فعال، ولا يحمل دورًا بصلاحية kpi.view.',
            'department_workspace' => 'ليس رئيسًا معيّنًا لقسم فعال يحمل فيه دور «رئيس قسم».',
            'manage_employees' => 'إدارة الموظفين لمدير النظام ورئيس قسم الموارد البشرية المعيّن فقط.',
            'org_structure' => 'الهيكل التنظيمي لمدير النظام ورئيس الموارد البشرية والمدير التنفيذي ورؤساء الأقسام المعيّنين.',
            'attendance_access' => 'الحضور لمدير النظام ورئيس الموارد البشرية والمدير التنفيذي.',
            'hr_dashboard' => 'لوحة الموارد البشرية والخدمات العامة لرئيس الموارد البشرية المعيّن ومدير النظام والمدير التنفيذي.',
            'leave_requester' => 'يتطلب ملف موظف مكتملًا ونشطًا مرتبطًا بالحساب.',
            'employee_profile' => 'لا يوجد ملف موظف مرتبط بهذا الحساب؛ تربطه الموارد البشرية من ملف الموظف.',
            'finance_view' => $user->roleAssignments()->whereHas('role.permissions', fn ($query) => $query->where('code', 'finance.view'))->exists()
                ? 'يحمل دورًا ماليًا لكن لا يوجد له تخصيص نشط في «صلاحيات الحسابات».'
                : 'لا يحمل دورًا بصلاحية finance.view؛ يُسند الاختصاص المالي من «صلاحيات الحسابات».',
            'orphans_review' => 'يتطلب تفويضًا نافذًا في قسم الكفالات: يطلبه رئيس القسم ويعتمده مدير النظام.',
            'orphans_delegations' => 'لمدير النظام ورئيس قسم الكفالات المعيّن.',
            'orphans_imports' => 'يتطلب تفويض «استيراد الكشوف وتدقيق دفعاتها» في قسم الكفالات: يطلبه رئيس القسم ويعتمده مدير النظام.',
            'orphans_settings' => 'لمدير النظام والمدير التنفيذي ورئيس قسم الكفالات المعيّن.',
            'orphans_sponsorships' => 'يتطلب تفويض «الكفلاء والكفالات والتبرعات» في قسم الكفالات، أو دور المدير التنفيذي.',
            'relief_campaigns' => 'يتطلب تفويض «الحملات» في قسم الكفالات، أو دور المدير التنفيذي، أو تكليفًا نافذًا في حملة محددة.',
            'relief_devices' => 'لرئيس قسم الكفالات المعيّن ومدير النظام.',
            'orphans_aid' => 'يتطلب تفويض «الكفلاء والكفالات والتبرعات» أو «الحملات» في قسم الكفالات، أو دور المدير التنفيذي، أو تكليفًا نافذًا في حملة.',
            'approval_delegations' => 'للمدير التنفيذي ومدير النظام والمفوَّضين بتفويض ساري.',
            'finance_approver' => 'لمن يحمل صلاحية اعتماد مالي مع تخصيص «معتمد مالي» أو عضوية الاعتماد في المجلس.',
            default => 'قاعدة ظهور غير معروفة.',
        };
    }

    /** @return list<string> */
    private function financeCodes(string $rule): array
    {
        return array_values(array_filter(array_map('trim', explode('|', substr($rule, strlen(self::FINANCE_PREFIX))))));
    }

    /** @param list<string> $codes */
    private function anyFinance(User $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->financeGuard->allows($user, $code)) {
                return true;
            }
        }

        return false;
    }

    private function hasRole(User $user, string $roleCode): bool
    {
        return $user->roleAssignments()->whereHas('role', fn ($query) => $query->where('code', $roleCode))->exists();
    }

    private function isActiveEmployee(User $user): bool
    {
        if (! Schema::hasColumn('employees', 'onboarding_status')) {
            return false;
        }
        $employee = $user->employeeProfile;

        return $employee !== null
            && $employee->onboarding_status === 'completed'
            && $employee->employment_status === 'active';
    }
}
