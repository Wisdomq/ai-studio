<?php

namespace App\Jobs;

use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Services\DependencyFileService;
use App\Services\McpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SubmitStepJob - Phase 1 of Split Job Pattern
 * 
 * Submits a workflow step to ComfyUI and immediately exits, freeing the worker.
 * Dispatches PollStepJob to check completion status.
 * 
 * This job runs quickly (< 5 seconds) and never blocks the queue worker.
 */
class SubmitStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60; // Short timeout - just for submission
    public int $tries   = 3;  // Retry submission failures

    public function __construct(
        public readonly int $stepId,
        public readonly int $planId
    ) {}

    public function handle(McpService $mcp, DependencyFileService $depService): void
    {
        $step = WorkflowPlanStep::with('workflow')->findOrFail($this->stepId);
        $plan = WorkflowPlan::findOrFail($this->planId);

        Log::info("SubmitStepJob: Submitting step {$step->step_order} (workflow: {$step->workflow->name})");

        // Mark step as running
        $step->markRunning();

        try {
            // Collect dependency input files
            $inputFiles = $depService->collectDependencyFiles($step, $plan);

            // Upload dependency files to ComfyUI via MCP
            $comfyInputFiles = [];
            foreach ($inputFiles as $mediaType => $storagePath) {
                $comfyFilename               = $mcp->uploadInputFile($storagePath, $mediaType);
                $comfyInputFiles[$mediaType] = $comfyFilename;
                Log::info("SubmitStepJob: Uploaded {$mediaType} → {$comfyFilename}");
            }

            // Inject refined prompt + file placeholders into workflow JSON
            $prompt = $step->refined_prompt ?? $step->purpose;

            // Determine execution path and get workflow JSON
            if ($step->workflow->isComfyuiDirect()) {
                $workflowName = $step->workflow->comfy_workflow_name;
                Log::info("SubmitStepJob: Using ComfyUI-direct path for workflow: {$workflowName}");
                $graph = $mcp->mcpFetchWorkflowFromComfyUI($workflowName);
                if ($graph === null) {
                    throw new \RuntimeException("Failed to fetch workflow from ComfyUI for '{$workflowName}'");
                }
                $injectedJson = $step->workflow->injectPromptIntoGraph($graph, $prompt, $comfyInputFiles);
            } elseif ($step->workflow->isMcpLiveFetch()) {
                $workflowId = $step->workflow->mcp_workflow_id;
                Log::info("SubmitStepJob: Using MCP live-fetch path for workflow: {$workflowId}");
                $graph = $mcp->mcpFetchWorkflowGraph($workflowId);
                if ($graph === null) {
                    throw new \RuntimeException("Failed to fetch workflow graph from MCP for '{$workflowId}'");
                }
                $injectedJson = $step->workflow->injectPromptIntoGraph($graph, $prompt, $comfyInputFiles);
            } else {
                $injectedJson = $step->workflow->injectPrompt($prompt, $comfyInputFiles);
            }

            Log::info("SubmitStepJob: Submitting to ComfyUI", [
                'step_order'     => $step->step_order,
                'workflow'       => $step->workflow->name,
                'prompt_preview' => substr($prompt, 0, 80),
            ]);

            // Submit to ComfyUI via MCP
            $submission = $mcp->mcpSubmitJob($injectedJson);
            $jobId      = $submission['prompt_id'];
            $assetId    = $submission['asset_id'];

            // Save job identifiers
            $step->update([
                'comfy_job_id' => $jobId,
                'mcp_asset_id' => $assetId,
            ]);

            Log::info("SubmitStepJob: Submitted successfully", [
                'step_order'  => $step->step_order,
                'comfy_job_id' => $jobId,
                'asset_id'    => $assetId,
            ]);

            // Dispatch polling job with 5 second delay
            PollStepJob::dispatch($this->stepId, $this->planId)
                ->delay(now()->addSeconds(5));

            Log::info("SubmitStepJob: Dispatched PollStepJob for step {$step->step_order}");

        } catch (\Exception $e) {
            Log::error("SubmitStepJob: Failed to submit step {$step->step_order}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $step->markFailed($e->getMessage());
            throw $e; // Let queue handle retry
        }
    }

}
