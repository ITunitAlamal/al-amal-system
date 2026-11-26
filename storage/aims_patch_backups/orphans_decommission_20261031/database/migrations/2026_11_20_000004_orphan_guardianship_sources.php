<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guardianship link now records the decision it came from: the civil-registry claim (existing path) or the
 * department's file application approved by the executive director. Exactly one source is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orphan_guardianships', function (Blueprint $t): void {
            $t->foreignId('orphan_application_id')->nullable()->constrained('orphan_applications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE orphan_guardianships ALTER COLUMN guardian_application_id DROP NOT NULL');
        DB::statement('ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (guardian_application_id IS NOT NULL OR orphan_application_id IS NOT NULL)');
    }

    public function down(): void
    {
        if (DB::table('orphan_guardianships')->whereNull('guardian_application_id')->exists()) {
            throw new RuntimeException('توجد روابط وصاية مصدرها قرار ملف القسم؛ لا يُتراجع عن الترحيل.');
        }
        DB::statement('ALTER TABLE orphan_guardianships DROP CONSTRAINT IF EXISTS orphan_guardianships_source');
        DB::statement('ALTER TABLE orphan_guardianships ALTER COLUMN guardian_application_id SET NOT NULL');
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropConstrainedForeignId('orphan_application_id'));
    }
};
