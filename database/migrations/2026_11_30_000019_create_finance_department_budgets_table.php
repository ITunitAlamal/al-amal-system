<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_department_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->decimal('allocated_amount', 18, 2);
            $table->date('as_of');
            $table->string('reference', 120);
            $table->text('notes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'fiscal_year', 'currency'], 'fin_department_budget_uniq');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_department_budgets ADD CONSTRAINT fin_department_budget_amount_check CHECK (allocated_amount >= 0 AND revision >= 1 AND fiscal_year BETWEEN 2020 AND 2100)');
        }
        if (Schema::hasTable('navigation_items')) {
            app(NavigationRegistry::class)->apply(null);
        }
    }

    public function down(): void
    {
        if (DB::table('finance_department_budgets')->exists()) {
            throw new RuntimeException('ميزانيات الأقسام تحتوي بيانات؛ استخدم ترحيلًا تصحيحيًا للحفاظ على السجل المالي.');
        }
        if (Schema::hasTable('navigation_items')) {
            DB::table('navigation_items')->where('code', 'finance_department_budgets')->delete();
        }
        Schema::dropIfExists('finance_department_budgets');
    }
};
