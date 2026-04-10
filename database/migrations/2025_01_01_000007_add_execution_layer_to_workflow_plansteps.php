<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add execution_layer to workflow_plan_steps.
 *
 * execution_layer groups steps into DAG levels:
 *   0 = no dependencies (independent / root steps)
 *   1 = depends only on layer-0 steps
 *   N = depends on layer-(N-1) or lower steps
 *
 * The executor runs all steps in layer N before starting any step in layer N+1.
 * This guarantees multi-input steps (e.g. faceswap needing image + video from
 * two separate upstream steps) always have all their inputs available.
 *
 * Existing rows default to 0 — safe because old single-step plans have no
 * dependencies and old multi-step plans will fall back to the safety-net
 * scan in collectDependencyFiles() if layer info is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            $table->unsignedTinyInteger('execution_layer')
                ->default(0)
                ->after('step_order')
                ->comment('DAG execution layer: 0 = independent, N = depends on layer N-1');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            $table->dropColumn('execution_layer');
        });
    }
};