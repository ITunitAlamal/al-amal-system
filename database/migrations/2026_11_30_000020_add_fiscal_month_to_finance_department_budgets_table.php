<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_department_budgets', function (Blueprint $table): void {
            // Existing annual allocations remain historical records; no monthly amount is inferred from them.
            $table->unsignedTinyInteger('fiscal_month')->nullable();
            $table->dropUnique('fin_department_budget_uniq');
            $table->unique(['department_id', 'fiscal_year', 'fiscal_month', 'currency'], 'fin_department_month_budget_uniq');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_department_budgets ADD CONSTRAINT fin_department_budget_month_check CHECK (fiscal_month BETWEEN 1 AND 12)');
        }
    }

    public function down(): void
    {
        if (DB::table('finance_department_budgets')->whereNotNull('fiscal_month')->exists()) {
            throw new RuntimeException('توجد ميزانيات شهرية؛ استخدم ترحيلًا تصحيحيًا للحفاظ على السجل المالي.');
        }
        Schema::table('finance_department_budgets', function (Blueprint $table): void {
            $table->dropUnique('fin_department_month_budget_uniq');
            $table->dropColumn('fiscal_month');
            $table->unique(['department_id', 'fiscal_year', 'currency'], 'fin_department_budget_uniq');
        });
    }
};
