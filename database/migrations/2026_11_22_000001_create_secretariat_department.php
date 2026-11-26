<?php

use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'SECRETARIAT';

    private const NAME = 'السكرتاريا';

    public function up(): void
    {
        DB::transaction(function (): void {
            $existing = DB::table('departments')->where('code', self::CODE)->first();
            if ($existing) {
                if ($existing->name === self::NAME && $existing->parent_id === null && $existing->is_active && $existing->deleted_at === null && $existing->node_type === 'department') {
                    return;
                }

                throw new RuntimeException('The SECRETARIAT department code already exists with a different or inactive structure.');
            }

            $sameName = DB::table('departments')->whereNull('parent_id')->whereNull('deleted_at')
                ->whereRaw('lower(btrim(name)) = lower(btrim(?))', [self::NAME])->exists();
            if ($sameName) {
                throw new RuntimeException('An independent department named السكرتاريا already exists with a different code.');
            }

            $departmentId = DB::table('departments')->insertGetId([
                'code' => self::CODE,
                'name' => self::NAME,
                'parent_id' => null,
                'node_type' => 'department',
                'is_active' => true,
                'head_user_id' => null,
                'effective_from' => today()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reason = 'طلب المستخدم بتاريخ 2026-10-27: إنشاء السكرتاريا قسمًا مستقلًا دون تعيين مسؤول؛ تعيين المسؤول لاحقًا منفصل عن وجود القسم.';
            $after = (array) DB::table('departments')->find($departmentId);
            $changeId = DB::table('department_structure_changes')->insertGetId([
                'change_type' => 'create_department', 'department_id' => $departmentId,
                'proposed_name' => self::NAME, 'proposed_code' => self::CODE,
                'rationale' => $reason, 'status' => 'applied', 'authority' => 'user_request',
                'after_state' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditService::class)->recordRow('organization.structure.applied', 'departments', $departmentId, null,
                ['change_id' => $changeId, 'authority' => 'user_request', 'source' => '2026_11_22_000001_create_secretariat_department', 'reason' => $reason, 'after' => $after], null);

            $suggestions = DB::table('department_structure_changes')->where('change_type', 'create_unit')
                ->where('status', 'suggested')->where('proposed_name', self::NAME)->get();
            foreach ($suggestions as $suggestion) {
                DB::table('department_structure_changes')->where('id', $suggestion->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
                app(AuditService::class)->recordRow('organization.change.dismissed', 'department_structure_changes', (int) $suggestion->id,
                    ['status' => 'suggested'], ['status' => 'withdrawn', 'reason' => $reason, 'replacement_change_id' => $changeId], null);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The secretariat department may have live organizational references and must not be deleted by rolling back a migration.');
    }
};
