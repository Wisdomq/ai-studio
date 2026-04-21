<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to workflow_plan_steps table.
 *
 * These indexes address Audit Issue #6 - Missing Database Indexes.
 * The workflow_plan_steps table is queried on every 4-second poll interval
 * during job execution, causing full table scans without these indexes.
 *
 * Indexes added:
 *   1. plan_id + status - For finding next ready step in a plan
 *   2. comfy_job_id - For polling ComfyUI job status
 *   3. workflow_id - For workflow-based queries
 *
 * Impact: Eliminates full table scans on every poll cycle, providing
 * immediate and measurable query performance improvement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            // Composite index for finding steps by plan and status
            // Used heavily in CoordinatePlanJob to find next ready step
            $table->index(['plan_id', 'status'], 'idx_plan_status');
            
            // Index for ComfyUI job status polling
            // Used in PollStepJob every 5 seconds
            $table->index('comfy_job_id', 'idx_comfy_job');
            
            // Index for workflow-based queries
            // Used in admin panel and workflow analytics
            $table->index('workflow_id', 'idx_workflow');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            $table->dropIndex('idx_plan_status');
            $table->dropIndex('idx_comfy_job');
            $table->dropIndex('idx_workflow');
        });
    }
};
