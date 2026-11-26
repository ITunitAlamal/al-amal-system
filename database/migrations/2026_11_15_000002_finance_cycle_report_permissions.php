<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports within each role's own scope (Accounts requirements doc §5 and §8): the Accounts head, the storekeeper and
 * the procurement officer may view and export the reports of their part of the documentary cycle. The report
 * endpoint still limits each report to the permissions of its area (purchases, orders, invoices, stock).
 */
return new class extends Migration
{
    private const GRANTS = ['finance_head', 'finance_warehouse', 'finance_procurement'];

    private const PERMISSIONS = ['finance.reports.view', 'finance.report.export'];

    public function up(): void
    {
        $permissions = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id', 'code');
        $granted = [];
        foreach (DB::table('roles')->whereIn('code', self::GRANTS)->get(['id', 'code']) as $role) {
            foreach ($permissions as $code => $permissionId) {
                if (! DB::table('permission_role')->where('role_id', $role->id)->where('permission_id', $permissionId)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permissionId]);
                    $granted[] = $role->code.': '.$code;
                }
            }
        }
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'access.permission.granted', 'subject_type' => 'roles', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['granted' => $granted], 'source' => 'ترحيل 2026_11_15_000002 (تقارير الدورة المستندية لكل دور ضمن نطاقه)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $roles = DB::table('roles')->whereIn('code', self::GRANTS)->pluck('id');
        $permissions = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');
        // finance_head held finance.reports.view before this migration.
        $keep = DB::table('roles as r')->join('permissions as p', fn ($join) => $join->where('p.code', 'finance.reports.view'))
            ->where('r.code', 'finance_head')->first(['r.id as role_id', 'p.id as permission_id']);
        DB::table('permission_role')->whereIn('role_id', $roles)->whereIn('permission_id', $permissions)
            ->when($keep, fn ($query) => $query->whereNot(fn ($inner) => $inner->where('role_id', $keep->role_id)->where('permission_id', $keep->permission_id)))
            ->delete();
    }
};
