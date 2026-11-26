<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataGroupsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['code' => 'work', 'name_ar' => 'ثوابت العمل', 'name_en' => 'Work', 'sort_order' => 1],
            ['code' => 'education', 'name_ar' => 'ثوابت التعليم', 'name_en' => 'Education', 'sort_order' => 2],
            ['code' => 'locations', 'name_ar' => 'ثوابت العناوين والمناطق', 'name_en' => 'Locations and Addresses', 'sort_order' => 3],
            ['code' => 'social', 'name_ar' => 'ثوابت البيانات الاجتماعية', 'name_en' => 'Social Data', 'sort_order' => 4],
            ['code' => 'money', 'name_ar' => 'ثوابت المال', 'name_en' => 'Money', 'sort_order' => 5],
            ['code' => 'services', 'name_ar' => 'ثوابت الخدمات', 'name_en' => 'Services', 'sort_order' => 6],
            ['code' => 'buildings', 'name_ar' => 'ثوابت المباني والمرافق', 'name_en' => 'Buildings and Facilities', 'sort_order' => 7],
            ['code' => 'skills', 'name_ar' => 'ثوابت المهارات', 'name_en' => 'Skills', 'sort_order' => 8],
            ['code' => 'external_contacts', 'name_ar' => 'جهات التواصل الخارجية', 'name_en' => 'External Contacts', 'sort_order' => 9],
            ['code' => 'hr', 'name_ar' => 'ثوابت شؤون الموظفين', 'name_en' => 'Human Resources', 'sort_order' => 10],
            ['code' => 'sponsorships', 'name_ar' => 'ثوابت الأيتام والكفالات', 'name_en' => 'Orphans and Sponsorships', 'sort_order' => 11],
            ['code' => 'accounting', 'name_ar' => 'ثوابت الحسابات', 'name_en' => 'Accounting', 'sort_order' => 12],
            ['code' => 'inventory', 'name_ar' => 'ثوابت المشتريات والمخازن', 'name_en' => 'Procurement and Inventory', 'sort_order' => 13],
            ['code' => 'maintenance', 'name_ar' => 'ثوابت الصيانة', 'name_en' => 'Maintenance', 'sort_order' => 14],
            ['code' => 'assets', 'name_ar' => 'ثوابت الأصول والإيجارات', 'name_en' => 'Assets and Rentals', 'sort_order' => 15],
            ['code' => 'documents', 'name_ar' => 'ثوابت الوثائق والمراسلات', 'name_en' => 'Documents and Correspondence', 'sort_order' => 16],
            ['code' => 'projects', 'name_ar' => 'ثوابت البرامج والمشاريع', 'name_en' => 'Programs and Projects', 'sort_order' => 17],
        ];

        DB::transaction(function () use ($groups): void {
            foreach ($groups as $group) {
                // أضف المجموعة فقط إذا لم تكن موجودة.
                // لا تستبدل أي تعديلات لاحقة يجريها مدير النظام.
                DB::table('master_data_groups')->insertOrIgnore([
                    'code' => $group['code'],
                    'name_ar' => $group['name_ar'],
                    'name_en' => $group['name_en'],
                    'description' => null,
                    'sort_order' => $group['sort_order'],
                    'is_active' => true,
                    'created_by' => null,
                    'updated_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
