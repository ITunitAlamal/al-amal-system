<?php

use App\Services\AuditService;
use App\Services\Organization\OrganizationStructure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $department = DB::table('departments')->where('code', 'SECRETARIAT')->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $department || $department->node_type !== 'department' || $department->parent_id !== null || ! $department->is_active) {
                throw new RuntimeException('يجب وجود قسم السكرتاريا المستقل والمفعّل قبل أرشفة الوحدة المكررة.');
            }
            $units = DB::table('departments')->where('name', 'السكرتاريا')->where('node_type', 'unit')->whereNull('deleted_at')->lockForUpdate()->get();
            foreach ($units as $unit) {
                $blockers = app(OrganizationStructure::class)->retireBlockers((int) $unit->id);
                if (DB::table('users')->where('department_id', $unit->id)->exists()) {
                    $blockers[] = 'حسابات مستخدمين مرتبطة بالوحدة';
                }
                if ($blockers !== []) {
                    throw new RuntimeException('تعذر أرشفة الوحدة المكررة: '.implode('، ', $blockers));
                }
                $reason = 'طلب المستخدم بتاريخ 2026-10-27: الإبقاء على قسم سكرتاريا مستقل واحد وأرشفة الوحدة التابعة المكررة مع حفظ تاريخها.';
                DB::table('departments')->where('id', $unit->id)->update([
                    'is_active' => false, 'deleted_at' => now(), 'retired_at' => now(), 'retired_reason' => $reason, 'updated_at' => now(),
                ]);
                $after = (array) DB::table('departments')->find($unit->id);
                $changeId = app(OrganizationStructure::class)->record(['change_type' => 'retire', 'department_id' => $unit->id, 'rationale' => $reason], null, 'applied');
                DB::table('department_structure_changes')->where('id', $changeId)->update([
                    'authority' => 'user_request', 'before_state' => json_encode($unit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'after_state' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'applied_at' => now(),
                ]);
                app(AuditService::class)->recordRow('organization.structure.applied', 'departments', (int) $unit->id, (array) $unit,
                    ['change_id' => $changeId, 'authority' => 'user_request', 'retained_department_id' => $department->id, 'after' => $after], null);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('لا تعاد الوحدة المكررة تلقائيًا؛ إعادة تفعيلها تحتاج قرارًا جديدًا.');
    }
};
