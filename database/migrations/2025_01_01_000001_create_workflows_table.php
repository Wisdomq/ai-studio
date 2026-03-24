<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();

            $table->string('type');         // image / video / audio / image_to_video / video_to_video / avatar_video
            $table->string('name');
            $table->text('description');    // Used by OrchestratorAgent to select workflow

            // Stored as raw JSON string — NOT cast to array — injectPrompt() depends on this
            $table->longText('workflow_json');

            $table->boolean('is_active')->default(false);

            // [] = text-only, ["image","audio"] = requires file inputs from prior steps
            $table->json('input_types')->nullable();

            // image / video / audio — what this workflow produces
            $table->string('output_type');

            // Maps media type to placeholder token: {"image":"{{INPUT_IMAGE}}"}
            $table->json('inject_keys')->nullable();

            // V2 — MCP workflow discovery
            $table->string('comfy_workflow_name')->nullable();  // Original filename from ComfyUI
            $table->timestamp('discovered_at')->nullable();     // Last synced from ComfyUI via MCP

            // V2 — default when multiple workflows share same output_type
            $table->boolean('default_for_type')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};