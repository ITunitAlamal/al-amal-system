<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_purchase_orders', function (Blueprint $table) {
            $table->decimal('closure_released_amount', 18, 2)->default(0);
        });

        DB::table('finance_purchase_orders')->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                $purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
                if ($purchase === null) {
                    continue;
                }
                $released = DB::table('finance_budget_movements')
                    ->where('budget_line_id', $purchase->budget_line_id)->where('kind', 'release')
                    ->where('state', 'approved')->where('reason', 'تحرير المتبقي من أمر الشراء '.$order->number)
                    ->sum('amount');
                DB::table('finance_purchase_orders')->where('id', $order->id)
                    ->update(['closure_released_amount' => $released]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_purchase_orders', function (Blueprint $table) {
            $table->dropColumn('closure_released_amount');
        });
    }
};
