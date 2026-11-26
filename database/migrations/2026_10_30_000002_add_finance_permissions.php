<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $abilities = [
            'finance.view', 'finance.setup', 'finance.budget.manage', 'finance.treasury.manage',
            'finance.transaction.draft', 'finance.transaction.review', 'finance.transaction.approve', 'finance.transaction.execute',
            'finance.procurement.manage', 'finance.procurement.review', 'finance.procurement.director_approve', 'finance.procurement.board_approve',
            'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue', 'finance.stock.count',
            'finance.transfer.execute', 'finance.payroll.manage', 'finance.advance.manage', 'finance.fund.manage', 'finance.reconcile', 'finance.reports.view',
        ];
        foreach ($abilities as $code) {
            DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => $code]);
        }
        $roles = [
            'finance_head' => ['finance.view', 'finance.budget.manage', 'finance.transaction.review', 'finance.procurement.review', 'finance.reports.view'],
            'finance_accountant' => ['finance.view', 'finance.transaction.draft', 'finance.reports.view', 'finance.advance.manage'],
            'finance_procurement' => ['finance.view', 'finance.procurement.manage'],
            'finance_warehouse' => ['finance.view', 'finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue', 'finance.stock.count'],
            'finance_cash_executor' => ['finance.view', 'finance.transaction.execute'],
            'finance_director_approver' => ['finance.procurement.director_approve', 'finance.transaction.approve'],
            'finance_board_treasurer' => ['finance.procurement.board_approve'],
        ];
        foreach ($roles as $code => $codes) {
            DB::table('roles')->updateOrInsert(['code' => $code], ['name' => $code]);
            $id = DB::table('roles')->where('code', $code)->value('id');
            foreach ($codes as $p) {
                $pid = DB::table('permissions')->where('code', $p)->value('id');
                DB::table('permission_role')->updateOrInsert(['role_id' => $id, 'permission_id' => $pid], []);
            }
        }
    }

    public function down(): void
    { /* Never automatically revoke existing user assignments. */
    }
};
