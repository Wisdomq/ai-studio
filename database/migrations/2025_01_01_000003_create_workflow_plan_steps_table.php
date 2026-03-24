<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_plan_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained('workflow_plans')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows');

            $table->unsignedInteger('step_order');              // 0-based execution order
            $table->string('workflow_type');                    // Denormalised type for display
            $table->text('purpose');                            // Human description of this step
            $table->text('refined_prompt')->nullable();         // Confirmed prompt after WorkflowOptimizerAgent

            // Array of step_order ints whose output files feed into this step
            $table->json('depends_on')->nullable();

            // User-uploaded file for steps that need an existing file
            $table->string('input_file_path')->nullable();

            // ComfyUI job tracking — prompt_id returned by /prompt
            $table->string('comfy_job_id')->nullable();

            /**
             * V2 Status machine:
             *   pending → running → awaiting_approval → completed
             *                    ↘ pending (on user reject)
             *   running → failed
             */
            $table->string('status')->default('pending');

            // V2 — storage-relative path e.g. comfyui-outputs/foo.png
            $table->string('output_path')->nullable();

            // V2 — when user approved this step's output
            $table->timestamp('approved_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_plan_steps');
    }
};