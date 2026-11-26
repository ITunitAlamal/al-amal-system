<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_correspondences', function (Blueprint $table): void {
            $table->string('channel', 20)->default('internal');
            $table->string('direction', 20)->nullable();
            $table->string('external_party', 255)->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->date('external_date')->nullable();
            $table->index(['channel', 'direction']);
            $table->foreignId('recipient_id')->nullable()->change();
            $table->foreignId('recipient_department_id')->nullable()->change();
        });

        DB::statement("ALTER TABLE official_correspondences ADD CONSTRAINT correspondence_channel_direction_check CHECK (channel IN ('internal', 'external') AND (channel = 'internal' OR direction IN ('incoming', 'outgoing')))");
    }

    public function down(): void
    {
        if (DB::table('official_correspondences')->where('channel', 'external')->exists()) {
            throw new RuntimeException('External correspondence archive records exist and must be retained.');
        }

        if (DB::table('official_correspondences')->whereNull('recipient_id')->orWhereNull('recipient_department_id')->exists()) {
            throw new RuntimeException('Correspondence rows without internal recipients must be resolved before rollback.');
        }

        DB::statement('ALTER TABLE official_correspondences DROP CONSTRAINT correspondence_channel_direction_check');
        Schema::table('official_correspondences', function (Blueprint $table): void {
            $table->dropIndex(['channel', 'direction']);
            $table->dropColumn(['channel', 'direction', 'external_party', 'external_reference', 'external_date']);
            $table->foreignId('recipient_id')->nullable(false)->change();
            $table->foreignId('recipient_department_id')->nullable(false)->change();
        });
    }
};
