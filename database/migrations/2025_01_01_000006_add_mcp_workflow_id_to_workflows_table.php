<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add mcp_workflow_id to workflows table and make workflow_json nullable.
 *
 * mcp_workflow_id:
 *   Stores the snake_case workflow ID used by the MCP sidecar server
 *   (e.g. "generate_image", "generate_song"). When set on a Workflow record,
 *   ExecutePlanJob fetches the node graph live from MCP at execution time
 *   instead of reading workflow_json from the DB.
 *
 * workflow_json nullable:
 *   Live-fetch workflows have no JSON stored in the DB. Making this column
 *   nullable allows Workflow::create() without a workflow_json value.
 *   Existing rows with JSON are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            // Add mcp_workflow_id after comfy_workflow_name for logical grouping
            $table->string('mcp_workflow_id', 255)
                  ->nullable()
                  ->after('comfy_workflow_name')
                  ->comment('MCP sidecar workflow ID (e.g. "generate_image"). When set, graph is fetched live from MCP at execution time.');

            // Make workflow_json nullable so live-fetch workflows can be
            // registered without storing any JSON in the database.
            $table->longText('workflow_json')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('mcp_workflow_id');

            // Revert workflow_json to NOT NULL (original state).
            // Note: any rows where workflow_json is NULL will need to be
            // updated before this rollback can succeed cleanly.
            $table->longText('workflow_json')->nullable(false)->change();
        });
    }
};