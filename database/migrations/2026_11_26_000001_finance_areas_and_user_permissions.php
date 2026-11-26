<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «قسم الحسابات» as three functional areas (المشتريات، المخازن، المحاسب العام):
 * - `finance_user_permissions`: the system administrator's per-user grant or revocation of one finance permission;
 * - the sidebar group «المشتريات والمخازن» is split into «المشتريات» and «المخازن», and the shared entries move to
 *   «قسم الحسابات». An entry moves only while it still has its default group, icon or order (administrator
 *   choices from «القائمة الرئيسية» are kept). Additive only; no financial data is touched.
 */
return new class extends Migration
{
    /** code => [old group, old icon, old sort, new group, new icon, new sort] */
    private const MOVES = [
        'finance_pending' => ['purchasing_stores', 'inbox', 10, 'accounts_home', 'inbox', 10],
        'finance_overview' => ['purchasing_stores', 'git-branch', 20, 'accounts_home', 'git-branch', 20],
        'finance_archive' => ['purchasing_stores', 'save', 130, 'accounts_home', 'save', 30],
        'finance_reports' => ['finance', 'chart', 100, 'accounts_home', 'file-spreadsheet', 40],
        'finance_purchases' => ['purchasing_stores', 'shopping-cart', 30, 'purchasing', 'shopping-cart', 10],
        'finance_quotes' => ['purchasing_stores', 'clipboard-list', 40, 'purchasing', 'clipboard-list', 20],
        'finance_orders' => ['purchasing_stores', 'clipboard-list', 50, 'purchasing', 'file-text', 30],
        'finance_suppliers' => ['purchasing_stores', 'handshake', 70, 'purchasing', 'handshake', 40],
        'finance_receipts' => ['purchasing_stores', 'inbox', 80, 'stores', 'package-check', 10],
        'finance_stock' => ['purchasing_stores', 'list', 90, 'stores', 'boxes', 20],
        'finance_items' => ['purchasing_stores', 'list', 100, 'stores', 'list', 30],
        'finance_warehouses' => ['purchasing_stores', 'building', 110, 'stores', 'building', 40],
        'finance_counts' => ['purchasing_stores', 'clipboard-list', 120, 'stores', 'clipboard-list', 50],
        'finance_invoices' => ['purchasing_stores', 'list', 60, 'finance', 'receipt', 5],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('finance_user_permissions')) {
            Schema::create('finance_user_permissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('permission_code', 100);
                $table->string('effect', 10);
                $table->text('reason')->nullable();
                $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'permission_code']);
            });
            DB::statement("ALTER TABLE finance_user_permissions ADD CONSTRAINT finance_user_permissions_effect_check CHECK (effect IN ('grant','deny'))");
        }

        $registry = app(NavigationRegistry::class);
        // Before the move the old group still has children, so it is not retired yet; the new groups are added.
        $registry->apply(null);
        $this->move(fn (array $m): array => [$m[0], $m[1], $m[2]], fn (array $m): array => [$m[3], $m[4], $m[5]]);
        // Now empty, «المشتريات والمخازن» is retired.
        $registry->apply(null);
    }

    public function down(): void
    {
        if (! DB::table('navigation_items')->where('code', 'purchasing_stores')->exists()) {
            DB::table('navigation_items')->insert(['code' => 'purchasing_stores', 'label_ar' => 'المشتريات والمخازن', 'icon' => 'shopping-cart',
                'sort_order' => 60, 'is_active' => true, 'access_rule' => 'authenticated', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->move(fn (array $m): array => [$m[3], $m[4], $m[5]], fn (array $m): array => [$m[0], $m[1], $m[2]]);
        foreach (['accounts_home', 'purchasing', 'stores'] as $group) {
            $id = DB::table('navigation_items')->where('code', $group)->value('id');
            if ($id && ! DB::table('navigation_items')->where('parent_id', $id)->exists()) {
                DB::table('navigation_items')->where('id', $id)->delete();
            }
        }
        // The administrator's per-user decisions are kept unless there are none.
        if (Schema::hasTable('finance_user_permissions') && ! DB::table('finance_user_permissions')->exists()) {
            Schema::drop('finance_user_permissions');
        }
    }

    private function move(callable $from, callable $to): void
    {
        $groups = DB::table('navigation_items')->whereNull('parent_id')->pluck('id', 'code');
        foreach (self::MOVES as $code => $move) {
            [$fromGroup, $fromIcon, $fromSort] = $from($move);
            [$toGroup, $toIcon, $toSort] = $to($move);
            $row = DB::table('navigation_items')->where('code', $code)->first();
            if (! $row || ! isset($groups[$toGroup])) {
                continue;
            }
            $changes = [];
            if ((int) $row->parent_id === (int) ($groups[$fromGroup] ?? 0)) {
                $changes['parent_id'] = $groups[$toGroup];
            }
            if ($row->icon === $fromIcon) {
                $changes['icon'] = $toIcon;
            }
            if ((int) $row->sort_order === $fromSort) {
                $changes['sort_order'] = $toSort;
            }
            if ($changes !== []) {
                DB::table('navigation_items')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
            }
        }
    }
};
