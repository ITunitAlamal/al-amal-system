<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KPI engine storage: definitions (visibility and placement, changed in place and audited),
 * versions (formula, target and thresholds with effective dates, never edited) and saved readings.
 * Additive only: no business table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('domain', 40)->default('institution');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('audience', 20)->default('executive');
            $table->jsonb('viewer_department_ids')->default('[]');
            $table->jsonb('visible_role_codes')->default('[]');
            $table->boolean('on_executive_home')->default(false);
            $table->boolean('on_department_home')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE kpi_definitions ADD CONSTRAINT kpi_definitions_audience_check CHECK (audience IN ('executive', 'owner', 'shared'))");

        Schema::create('kpi_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_id')->constrained('kpi_definitions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->jsonb('configuration');
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['definition_id', 'version']);
            $table->index(['definition_id', 'effective_from']);
        });

        Schema::create('kpi_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('kpi_versions')->restrictOnDelete();
            $table->string('scope_key', 255);
            $table->string('scope_hash', 64);
            $table->date('period_from');
            $table->date('period_until');
            $table->jsonb('filters');
            $table->jsonb('result');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['version_id', 'scope_hash', 'period_from']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('kpi_results') && DB::table('kpi_results')->exists()) {
            throw new RuntimeException('توجد قراءات مؤشرات محفوظة؛ لا تُحذف الجداول. عطّل المؤشرات من الإدارة بدل التراجع.');
        }
        Schema::dropIfExists('kpi_results');
        Schema::dropIfExists('kpi_versions');
        Schema::dropIfExists('kpi_definitions');
    }
};
