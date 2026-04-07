<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            // MCP server asset_id — ephemeral secondary reference for regenerate/provenance.
            // output_path remains the durable primary reference.
            // Placed after comfy_job_id which already exists.
            $table->string('mcp_asset_id')->nullable()->after('comfy_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_plan_steps', function (Blueprint $table) {
            $table->dropColumn('mcp_asset_id');
        });
    }
};