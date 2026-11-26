<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MasterDataTypesSeeder extends Seeder
{
    public function run(): void
    {
        // الأنواع البسيطة فقط.
        // الثوابت المتخصصة ستُدار في جداول مستقلة.
        $types = [
            'work' => [
                ['employment_terms', 'بنود التوظيف', 'Employment Terms'],
                ['administrative_levels', 'المستويات الإدارية', 'Administrative Levels'],
            ],

            'education' => [
                ['institution_types', 'أنواع المؤسسات التعليمية', 'Educational Institution Types'],
                ['academic_grades', 'التقديرات العلمية', 'Academic Grades'],
            ],

            'locations' => [],

            'social' => [
                ['kinship_types', 'صلات القرابة', 'Kinship Types'],
                ['marital_statuses', 'الحالات الاجتماعية', 'Marital Statuses'],
                ['nationalities', 'الجنسيات', 'Nationalities'],
                ['identity_document_types', 'أنواع وثائق الهوية', 'Identity Document Types'],
            ],

            'money' => [
                ['bank_account_types', 'أنواع الحسابات البنكية', 'Bank Account Types'],
            ],

            'services' => [],

            'buildings' => [
                ['building_types', 'أنواع المباني', 'Building Types'],
                ['facility_types', 'أنواع المرافق', 'Facility Types'],
            ],

            'skills' => [
                ['skill_levels', 'مستويات إتقان المهارات', 'Skill Levels'],
            ],

            'external_contacts' => [
                ['external_entity_categories', 'تصنيفات الجهات الخارجية', 'External Entity Categories'],
                ['cooperation_types', 'أنواع علاقات التعاون', 'Cooperation Types'],
            ],

            'hr' => [
                ['contract_types', 'أنواع العقود', 'Contract Types'],
                ['leave_types', 'أنواع الإجازات', 'Leave Types'],
                ['reward_types', 'أنواع المكافآت', 'Reward Types'],
                ['disciplinary_action_types', 'أنواع العقوبات', 'Disciplinary Action Types'],
                ['termination_reasons', 'أسباب إنهاء الخدمة', 'Termination Reasons'],
                ['work_schedules', 'أنظمة الدوام', 'Work Schedules'],
            ],

            'sponsorships' => [
                ['sponsorship_types', 'أنواع الكفالات', 'Sponsorship Types'],
                ['beneficiary_types', 'أنواع المستفيدين', 'Beneficiary Types'],
                ['need_categories', 'تصنيفات الاحتياجات', 'Need Categories'],
                ['sponsor_relationship_types', 'أنواع العلاقات بالكفلاء', 'Sponsor Relationship Types'],
            ],

            'accounting' => [
                ['income_categories', 'تصنيفات الإيرادات', 'Income Categories'],
                ['expense_categories', 'تصنيفات المصروفات', 'Expense Categories'],
                ['payment_methods', 'طرق الدفع والتحصيل', 'Payment Methods'],
                ['voucher_types', 'أنواع السندات', 'Voucher Types'],
            ],

            'inventory' => [
                ['item_categories', 'تصنيفات الأصناف', 'Item Categories'],
                ['purchase_request_types', 'أنواع طلبات الشراء', 'Purchase Request Types'],
                ['inventory_movement_types', 'أنواع حركات المخزون', 'Inventory Movement Types'],
                ['inventory_adjustment_reasons', 'أسباب التسوية المخزنية', 'Inventory Adjustment Reasons'],
                ['supplier_types', 'أنواع الموردين', 'Supplier Types'],
            ],

            'maintenance' => [
                ['fault_types', 'أنواع الأعطال', 'Fault Types'],
                ['maintenance_types', 'أنواع الصيانة', 'Maintenance Types'],
                ['maintenance_priorities', 'درجات أولوية الصيانة', 'Maintenance Priorities'],
                ['maintenance_work_categories', 'تصنيفات أعمال الصيانة', 'Maintenance Work Categories'],
            ],

            'assets' => [
                ['asset_types', 'أنواع الأصول', 'Asset Types'],
                ['asset_categories', 'تصنيفات الأصول', 'Asset Categories'],
                ['asset_statuses', 'الحالات الإدارية للأصول', 'Asset Statuses'],
                ['asset_technical_conditions', 'الحالات الفنية للأصول', 'Asset Technical Conditions'],
                ['rental_contract_types', 'أنواع عقود الإيجار', 'Rental Contract Types'],
                ['asset_disposal_reasons', 'أسباب استبعاد الأصول', 'Asset Disposal Reasons'],
                ['asset_movement_types', 'أنواع حركات الأصول', 'Asset Movement Types'],
            ],

            'documents' => [
                ['document_types', 'أنواع المستندات', 'Document Types'],
                ['attachment_categories', 'تصنيفات المرفقات', 'Attachment Categories'],
                ['correspondence_types', 'أنواع المراسلات', 'Correspondence Types'],
                ['document_issuer_types', 'تصنيفات جهات إصدار الوثائق', 'Document Issuer Types'],
            ],

            'projects' => [
                ['program_categories', 'تصنيفات البرامج', 'Program Categories'],
                ['project_categories', 'تصنيفات المشاريع', 'Project Categories'],
                ['activity_types', 'أنواع الأنشطة', 'Activity Types'],
                ['funding_source_types', 'مصادر التمويل', 'Funding Source Types'],
                ['donor_categories', 'تصنيفات الجهات المانحة', 'Donor Categories'],
            ],
        ];

        DB::transaction(function () use ($types): void {
            $groupIds = DB::table('master_data_groups')
                ->pluck('id', 'code');

            foreach ($types as $groupCode => $items) {
                if (! $groupIds->has($groupCode)) {
                    throw new RuntimeException(
                        "Master data group not found: {$groupCode}"
                    );
                }

                $groupId = $groupIds->get($groupCode);

                foreach ($items as $index => $item) {
                    [$code, $nameAr, $nameEn] = $item;

                    // لا نعدل السجلات الموجودة.
                    // نحافظ على أي تغييرات أدخلها مدير النظام.
                    $exists = DB::table('master_data_types')
                        ->where('code', $code)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('master_data_types')->insert([
                        'group_id' => $groupId,
                        'code' => $code,
                        'name_ar' => $nameAr,
                        'name_en' => $nameEn,
                        'description' => null,
                        'sort_order' => $index + 1,
                        'is_active' => true,
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
