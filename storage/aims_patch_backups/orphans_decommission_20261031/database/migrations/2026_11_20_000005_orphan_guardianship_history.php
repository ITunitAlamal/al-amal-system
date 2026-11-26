<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guardianship history: a guardian may represent a child again after an ended period, so uniqueness applies only to
 * links not yet ended by a decision; a transfer approved through a change request is a valid source of a link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orphan_guardianships', function (Blueprint $t): void {
            $t->foreignId('orphan_change_request_id')->nullable()->constrained('orphan_change_requests')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_source');
        DB::statement('ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (guardian_application_id IS NOT NULL OR orphan_application_id IS NOT NULL OR orphan_change_request_id IS NOT NULL)');
        DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardian_child_unique');
        DB::statement('DROP INDEX IF EXISTS orphan_guardian_child_unique');
        DB::statement('CREATE UNIQUE INDEX orphan_guardian_child_open ON orphan_guardianships (guardian_id, orphan_child_id) WHERE ended_reason IS NULL');
    }

    public function down(): void
    {
        if (DB::table('orphan_guardianships')->whereNotNull('orphan_change_request_id')->exists()
            || DB::table('orphan_guardianships')->select('guardian_id', 'orphan_child_id')->groupBy('guardian_id', 'orphan_child_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('يوجد تاريخ وصاية لا يحتمله القيد السابق؛ لا يُتراجع عن الترحيل.');
        }
        DB::statement('DROP INDEX IF EXISTS orphan_guardian_child_open');
        DB::statement('ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardian_child_unique UNIQUE (guardian_id, orphan_child_id)');
        DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_source');
        DB::statement('ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (guardian_application_id IS NOT NULL OR orphan_application_id IS NOT NULL)');
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropConstrainedForeignId('orphan_change_request_id'));
    }
};
