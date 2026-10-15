<?php
namespace Database\Seeders;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AimsCoreSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'ORPHANS'=>'شؤون الأيتام والكفالات',
            'HR'=>'الموارد البشرية والخدمات العامة',
            'MAINT'=>'الصيانة',
            'ASSETS'=>'الأصول والإيجارات',
            'FIN'=>'الحسابات والمشتريات والمخازن',
            'EXEC'=>'الإدارة التنفيذية',
            'SHELTER'=>'الإيواء (مؤجل)',
        ];
        foreach ($departments as $code=>$name) Department::firstOrCreate(['code'=>$code],['name'=>$name]);
        $permissions = [
            'dashboard.view'=>'عرض لوحة التحكم',
            'department.records.view'=>'عرض سجلات القسم',
            'department.records.create'=>'إنشاء سجلات القسم',
            'department.records.update'=>'تعديل سجلات القسم',
            'department.requests.review'=>'مراجعة طلبات القسم',
            'department.tasks.assign'=>'توزيع المهام',
            'department.reports.view'=>'عرض تقارير القسم',
            'executive.requests.decide'=>'القرار التنفيذي حسب التفويض',
            'system.users.manage'=>'إدارة المستخدمين',
            'system.roles.manage'=>'إدارة الأدوار والصلاحيات',
            'system.departments.manage' => 'إدارة الهيكل التنظيمي والأقسام',
        ];
        foreach($permissions as $code=>$name) Permission::firstOrCreate(['code'=>$code],['name'=>$name]);
       
$grants = [
    'department_employee' => [
        'dashboard.view',
        'department.records.view',
        'department.records.create',
        'department.records.update',
    ],

    'department_head' => [
        'dashboard.view',
        'department.records.view',
        'department.records.create',
        'department.records.update',
        'department.requests.review',
        'department.tasks.assign',
        'department.reports.view',
    ],

    'executive_director' => [
        'dashboard.view',
        'executive.requests.decide',
        'department.reports.view',
    ],

    'system_admin' => [
        'dashboard.view',
        'system.users.manage',
        'system.roles.manage',
        'system.departments.manage',
    ],
];

        $names=['department_employee'=>'موظف قسم','department_head'=>'رئيس قسم','executive_director'=>'المدير التنفيذي','system_admin'=>'مدير النظام'];
        foreach($grants as $code=>$permCodes) {
            $role=Role::firstOrCreate(['code'=>$code],['name'=>$names[$code]]);
            $role->permissions()->sync(Permission::whereIn('code',$permCodes)->pluck('id'));
        }
    }
}
