<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll in four steps (owner decision 2026-11-02): the payroll officer records the run → an independent reviewer
 * reviews it → the executive director approves it → the reviewer/publisher publishes the payslips. Nobody takes two
 * consecutive steps; the recorder takes none after recording.
 *
 * - state «pending_executive» (reviewed, awaiting the executive director); «approved» = approved, ready to publish.
 * - reviewed_by / reviewed_at / approved_at (approved_by exists).
 * - permission finance.payroll.authorize: the executive director's approval of a recorded run, held by the appointed
 *   head of EXEC (FinanceGuard::EXECUTIVE_PERMISSIONS). Separate from the legacy finance.payroll.approve, which stays
 *   refused in the documentary cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE finance_payroll_batches DROP CONSTRAINT finance_payroll_batches_state_check');
        DB::statement("ALTER TABLE finance_payroll_batches ADD CONSTRAINT finance_payroll_batches_state_check CHECK (state IN ('draft', 'pending_approval', 'pending_executive', 'approved', 'published', 'returned'))");
        Schema::table('finance_payroll_batches', function (Blueprint $t): void {
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
        DB::table('permissions')->insertOrIgnore(['code' => 'finance.payroll.authorize', 'name' => 'اعتماد المدير التنفيذي لمسير الرواتب', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Rolled back only while no run uses the new step. */
    public function down(): void
    {
        if (DB::table('finance_payroll_batches')->where('state', 'pending_executive')->orWhereNotNull('reviewed_by')->orWhereNotNull('approved_at')->exists()) {
            throw new RuntimeException('توجد مسيرات مرّت بالمراجعة أو الاعتماد الجديد؛ لا يمكن التراجع.');
        }
        DB::table('permissions')->where('code', 'finance.payroll.authorize')->delete();
        Schema::table('finance_payroll_batches', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['reviewed_at', 'approved_at']);
        });
        DB::statement('ALTER TABLE finance_payroll_batches DROP CONSTRAINT finance_payroll_batches_state_check');
        DB::statement("ALTER TABLE finance_payroll_batches ADD CONSTRAINT finance_payroll_batches_state_check CHECK (state IN ('draft', 'pending_approval', 'approved', 'published', 'returned'))");
    }
};
