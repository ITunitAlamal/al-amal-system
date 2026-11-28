<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «استيراد الموظفين»: an undo claims its batch before it starts (Engineering 01, CO-11). The batch status gains
 * «reverting», held while the undo runs, so a commit retry of a failed batch is refused instead of inserting rows the
 * undo has already passed. The CHECK constraint is replaced by a superset (every existing value stays valid); it is
 * added NOT VALID and then validated. Schema only, no business row changes. Safe to run twice.
 */
return new class extends Migration
{
    private const STATUSES = ['staged', 'validated', 'committing', 'committed', 'failed', 'cancelled', 'reverting', 'reverted'];

    private const PREVIOUS = ['staged', 'validated', 'committing', 'committed', 'failed', 'cancelled', 'reverted'];

    public function up(): void
    {
        if (str_contains((string) $this->definition(), "'reverting'")) {
            return;
        }
        $this->replace(self::STATUSES);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'schema.constraint.updated', 'subject_type' => 'employee_import_batches', 'subject_id' => '0',
            'metadata' => json_encode(['before' => ['statuses' => self::PREVIOUS], 'after' => ['statuses' => self::STATUSES],
                'source' => 'ترحيل 2026_11_30_000046 (حجز دفعة استيراد الموظفين أثناء التراجع)'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Restores the previous list only while no batch is «reverting».
     */
    public function down(): void
    {
        if (DB::table('employee_import_batches')->where('status', 'reverting')->exists()) {
            throw new RuntimeException('توجد دفعة استيراد قيد التراجع؛ لا يُعاد القيد السابق قبل انتهائها.');
        }
        $this->replace(self::PREVIOUS);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function replace(array $statuses): void
    {
        $list = implode(',', array_map(fn (string $status): string => "'{$status}'", $statuses));
        DB::transaction(function () use ($list): void {
            DB::statement('ALTER TABLE employee_import_batches DROP CONSTRAINT IF EXISTS employee_import_batches_status_check');
            DB::statement("ALTER TABLE employee_import_batches ADD CONSTRAINT employee_import_batches_status_check CHECK (status IN ({$list})) NOT VALID");
        });
        DB::statement('ALTER TABLE employee_import_batches VALIDATE CONSTRAINT employee_import_batches_status_check');
    }

    private function definition(): ?string
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint
            WHERE conrelid = 'employee_import_batches'::regclass AND conname = 'employee_import_batches_status_check'");

        return $row?->definition;
    }
};
