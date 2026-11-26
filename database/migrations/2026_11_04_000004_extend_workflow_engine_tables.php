<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns the workflow executor needs (SRS §79): configurable assignment,
     * SLA tracking and a human-readable reference number.
     */
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            // Employees can start this workflow from "طلباتي".
            $table->boolean('is_user_startable')->default(false)->after('is_active');
            $table->string('reference_prefix', 20)->nullable()->after('is_user_startable');
        });

        Schema::table('workflow_steps', function (Blueprint $table) {
            // {"department_id": .., "user_id": .., "department_scope": "origin|any|specific",
            //  "escalate_to_role_code": .., "outcome": "completed|rejected"}
            $table->json('assignment_config')->nullable()->after('assigned_role_code');
            $table->unsignedInteger('sla_hours')->nullable()->after('assignment_config');
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->string('reference_no', 40)->nullable()->unique()->after('public_id');
            $table->timestamp('due_at')->nullable()->after('status');
            $table->timestamp('sla_breached_at')->nullable()->after('due_at');
            $table->index(['status', 'due_at'], 'workflow_instances_sla_index');
        });

        // The legacy "executive_director" assignment type becomes a configured role.
        // Only unpublished drafts are touched; published versions are immutable.
        $draftVersionIds = DB::table('workflow_versions')->where('status', 'draft')->pluck('id');
        DB::table('workflow_steps')
            ->whereIn('workflow_version_id', $draftVersionIds)
            ->where('assignment_type', 'executive_director')
            ->update(['assignment_type' => 'role', 'assigned_role_code' => 'executive_director']);
    }

    public function down(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropIndex('workflow_instances_sla_index');
            $table->dropUnique(['reference_no']);
            $table->dropColumn(['reference_no', 'due_at', 'sla_breached_at']);
        });

        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['assignment_config', 'sla_hours']);
        });

        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->dropColumn(['is_user_startable', 'reference_prefix']);
        });
    }
};
