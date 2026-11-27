<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const LEGACY = [
        'finance.transaction.draft' => ['finance.payment.draft', 'finance.receipt.draft'],
        'finance.transaction.review' => ['finance.payment.review', 'finance.receipt.review'],
        'finance.transaction.approve' => ['finance.payment.approve', 'finance.receipt.approve'],
        'finance.transaction.execute' => ['finance.payment.execute', 'finance.receipt.execute'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('finance_access_grants', 'previous_effect')) {
            Schema::table('finance_access_grants', fn (Blueprint $table) => $table->string('previous_effect', 10)->nullable());
        }
        foreach (['payment' => 'الصرف', 'receipt' => 'القبض'] as $kind => $label) {
            foreach (['draft' => 'إعداد', 'review' => 'مراجعة', 'approve' => 'اعتماد', 'execute' => 'تنفيذ'] as $action => $verb) {
                DB::table('permissions')->insertOrIgnore(['code' => "finance.$kind.$action", 'name' => "$verb سندات $label", 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('permissions')->insertOrIgnore(['code' => 'finance.payment.record', 'name' => 'تسجيل سند الصرف من البرنامج المحاسبي', 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->pluck('id', 'code');
        foreach (self::LEGACY as $old => $replacements) {
            foreach (DB::table('permission_role as pr')->join('roles as r', 'r.id', '=', 'pr.role_id')->where('pr.permission_id', $ids[$old] ?? 0)->get(['pr.role_id', 'r.code']) as $row) {
                // The existing payment-executor template must never silently retain receipt execution.
                foreach ($row->code === 'finance_cash_executor' ? array_filter($replacements, fn (string $code): bool => str_starts_with($code, 'finance.payment.')) : $replacements as $code) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $row->role_id, 'permission_id' => $ids[$code]]);
                }
            }
            DB::table('permission_role')->where('permission_id', $ids[$old] ?? 0)->delete();
            // Preserve explicit grants and withdrawals, their dates, and their original decision history.
            foreach (DB::table('finance_user_permissions')->where('permission_code', $old)->get() as $row) {
                foreach ($replacements as $code) {
                    $values = (array) $row;
                    unset($values['id']);
                    $values['permission_code'] = $code;
                    DB::table('finance_user_permissions')->insertOrIgnore($values);
                }
            }
            DB::table('finance_user_permissions')->where('permission_code', $old)->delete();
            // Pending shared decisions become two explicit decisions, without granting either permission early.
            foreach (DB::table('finance_access_grants')->where('kind', 'permission')->where('code', $old)->where('state', 'pending_approval')->get() as $row) {
                $existing = DB::table('finance_access_grants')->where('user_id', $row->user_id)->where('kind', 'permission')->where('code', $replacements[0])->where('state', 'pending_approval')->exists();
                DB::table('finance_access_grants')->where('id', $row->id)->update(['code' => $replacements[0], 'state' => $existing ? 'cancelled' : 'pending_approval', 'updated_at' => now()]);
                $values = (array) $row;
                unset($values['id']);
                $values['code'] = $replacements[1];
                $values['public_id'] = (string) Str::uuid();
                DB::table('finance_access_grants')->insertOrIgnore($values);
            }
        }
        foreach ([
            'finance_cash_executor' => ['منفّذ الصرف', ['finance.view', 'finance.payment.execute', 'finance.payment.record', 'finance.reports.view', 'finance.report.export']],
            'finance_receipt_executor' => ['منفّذ القبض', ['finance.view', 'finance.receipt.execute', 'finance.rentals.record', 'finance.reports.view', 'finance.report.export']],
        ] as $code => [$name, $permissions]) {
            DB::table('roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $role = DB::table('roles')->where('code', $code)->value('id');
            foreach ($permissions as $permission) {
                if (isset($ids[$permission])) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $ids[$permission]]);
                }
            }
        }
        // Invoice preparation does not itself confer collection or payment recording.
        $accountant = DB::table('roles')->where('code', 'finance_accountant')->value('id');
        DB::table('permission_role')->where('role_id', $accountant)->whereIn('permission_id', array_filter([$ids['finance.rentals.record'] ?? null, $ids['finance.payment.record'] ?? null]))->delete();
        $accounts = DB::table('departments')->whereIn('code', ['FIN', 'ACC'])->whereNull('deleted_at')->orderByRaw("CASE WHEN code = 'FIN' THEN 0 ELSE 1 END")->first();
        if ($accounts) {
            foreach (['FIN_PURCHASING' => 'المشتريات', 'FIN_STORES' => 'المخازن'] as $code => $name) {
                $existing = DB::table('departments')->where(fn ($q) => $q->where('code', $code)->orWhere(fn ($unit) => $unit->where('parent_id', $accounts->id)->where('name', $name)))->first();
                if (! $existing) {
                    DB::table('departments')->insert(['code' => $code, 'name' => $name, 'parent_id' => $accounts->id, 'node_type' => 'unit', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                } elseif ($existing->deleted_at === null) {
                    DB::table('departments')->where('id', $existing->id)->update(['parent_id' => $accounts->id, 'node_type' => 'unit', 'updated_at' => now()]);
                }
            }
        }
        if (Schema::hasTable('navigation_items')) {
            app(NavigationRegistry::class)->apply(null);
        }
    }

    public function down(): void
    {
        // Keep access decisions and organizational units. Recombining cash permissions would widen access.
    }
};
