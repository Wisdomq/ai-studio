<?php

namespace App\Jobs;

use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Services\McpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExecutePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    protected const POLL_INTERVAL_SECONDS   = 5;
    protected const DEADLINE_BUFFER_SECONDS = 60;

    public function __construct(public readonly int $planId) {}

    // ── Entry point ───────────────────────────────────────────────────────────

    public function handle(McpService $mcp): void
    {
        $plan = WorkflowPlan::with('steps.workflow')->findOrFail($this->planId);

        Log::info("ExecutePlanJob: Starting plan #{$plan->id}");

        // Mark running here — not in the controller — to avoid race condition
        $plan->markRunning();

        $deadline = time() + $this->timeout - self::DEADLINE_BUFFER_SECONDS;

        try {
            $health = $mcp->healthCheck();

            if (! $health['reachable']) {
                throw new \RuntimeException('ComfyUI is not reachable. Check COMFYUI_BASE_URL.');
            }

            Log::info("ExecutePlanJob: ComfyUI reachable. VRAM free: {$health['gpu_vram_free']}");

            $this->runPlanLoop($plan, $mcp, $deadline);

            $plan->markCompleted();
            Log::info("ExecutePlanJob: Plan #{$plan->id} completed.");

            // Auto-dispatch next queued plan for this session
            $this->dispatchNextQueued($plan->session_id);

        } catch (\Throwable $e) {
            Log::error("ExecutePlanJob: Plan #{$plan->id} failed.", [
                'error' => $e->getMessage(),
            ]);
            $plan->markFailed();
            $plan->fresh()->steps
                ->where('status', WorkflowPlanStep::STATUS_RUNNING)
                ->each(fn (WorkflowPlanStep $s) => $s->markFailed($e->getMessage()));

            // Still try to run next queued item
            $this->dispatchNextQueued($plan->session_id);
        }
    }

    // ── Main loop ─────────────────────────────────────────────────────────────

    /**
     * Layer-aware execution loop.
     *
     * Steps are grouped into execution layers computed by OrchestratorAgent.
     * All steps in layer N complete before any step in layer N+1 starts.
     * Within a layer, steps run one at a time (GPU constraint), but they are
     * conceptually parallel — they do not depend on each other.
     *
     * This handles the fan-in pattern correctly:
     *   Layer 0: [text→image (scene)]  [text→image (face)]   ← independent
     *   Layer 1: [image→video]                               ← needs layer-0 image
     *   Layer 2: [faceswap]                                  ← needs layer-1 video + layer-0 image
     *
     * By the time faceswap runs, ALL layer-0 AND layer-1 steps are complete,
     * so both its inputs (image from layer-0 and video from layer-1) are available.
     */
    protected function runPlanLoop(WorkflowPlan $plan, McpService $mcp, int $deadline): void
    {
        while (true) {
            if (time() >= $deadline) {
                $this->handleTimeout($plan);
                return;
            }

            $plan->load('steps.workflow');
            $steps = $plan->steps;

            // All steps done?
            if ($steps->every(fn (WorkflowPlanStep $s) => $s->isCompleted())) {
                break;
            }

            // Any hard failures or cancellations?
            $failed = $steps->first(fn (WorkflowPlanStep $s) => $s->isFailed());
            if ($failed) {
                throw new \RuntimeException("Step {$failed->step_order} failed: {$failed->error_message}");
            }

            $cancelled = $steps->first(fn (WorkflowPlanStep $s) => $s->isCancelled());
            if ($cancelled) {
                throw new \RuntimeException("Step {$cancelled->step_order} was cancelled by the user.");
            }

            // Handle orphaned steps
            $orphaned = $steps->first(fn (WorkflowPlanStep $s) => $s->isOrphaned());
            if ($orphaned) {
                Log::info("ExecutePlanJob: Found orphaned step {$orphaned->step_order}, skipping to next");
                continue;
            }

            // ── Determine active layer ────────────────────────────────────────
            // Active layer = lowest layer that still has pending steps.
            // We never start a step in layer N until ALL steps in layers 0..N-1
            // are complete — this is the core of the layer-aware guarantee.
            $activeLayer = $steps
                ->filter(fn (WorkflowPlanStep $s) => $s->isPending())
                ->min('execution_layer');

            if ($activeLayer === null) {
                // No pending steps — all are running, awaiting approval, or complete
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            // Guard: all layers below active must be fully complete
            $lowerLayersPending = $steps->filter(
                fn (WorkflowPlanStep $s) => $s->execution_layer < $activeLayer && ! $s->isCompleted()
            );

            if ($lowerLayersPending->isNotEmpty()) {
                Log::debug("ExecutePlanJob: Waiting for lower layers to complete before layer {$activeLayer}", [
                    'pending_lower' => $lowerLayersPending->pluck('step_order')->all(),
                ]);
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            // Find the next pending step in the active layer that is ready to run
            $next = $steps->first(function (WorkflowPlanStep $s) use ($plan, $activeLayer) {
                return $s->isPending()
                    && $s->execution_layer === $activeLayer
                    && $s->isReady($plan);
            });

            if ($next !== null) {
                Log::info("ExecutePlanJob: Executing step {$next->step_order} (layer {$activeLayer})");
                $this->executeStep($next, $plan, $mcp, $deadline);
                continue; // Loop immediately after submitting
            }

            // No step ready yet in active layer — waiting for approval or dependency
            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * Handle soft timeout - mark running steps as orphaned instead of failing.
     * The job is already submitted to ComfyUI, so it will complete in background.
     */
    protected function handleTimeout(WorkflowPlan $plan): void
    {
        Log::warning("ExecutePlanJob: Plan #{$plan->id} reached soft timeout, marking running steps as orphaned");

        $plan->load('steps');
        
        $plan->steps
            ->where('status', WorkflowPlanStep::STATUS_RUNNING)
            ->each(function (WorkflowPlanStep $step) {
                if ($step->comfy_job_id) {
                    $step->markOrphaned();
                    Log::info("ExecutePlanJob: Step {$step->step_order} marked as orphaned (ComfyUI job: {$step->comfy_job_id})");
                } else {
                    // Step was submitted but no job ID - mark as failed
                    $step->markFailed('Job timed out before submission to ComfyUI');
                }
            });

        // Don't mark plan as failed - it's still potentially running
        Log::info("ExecutePlanJob: Plan #{$plan->id} handling complete - orphaned steps will be picked up by background monitor");
    }

    // ── Step execution ────────────────────────────────────────────────────────

    protected function executeStep(
        WorkflowPlanStep $step,
        WorkflowPlan $plan,
        McpService $mcp,
        int $deadline
    ): void {
        Log::info("ExecutePlanJob: Executing step {$step->step_order} (workflow: {$step->workflow->name})");

        $step->markRunning();

        try {
            // Collect dependency input files
            $inputFiles = $this->collectDependencyFiles($step, $plan);

            // Upload dependency files to ComfyUI via MCP
            $comfyInputFiles = [];
            foreach ($inputFiles as $mediaType => $storagePath) {
                $comfyFilename               = $mcp->uploadInputFile($storagePath, $mediaType);
                $comfyInputFiles[$mediaType] = $comfyFilename;
                Log::info("ExecutePlanJob: Uploaded {$mediaType} → {$comfyFilename}");
            }

            // Inject refined prompt + file placeholders into workflow JSON
            // Determine execution path:
            // 1. ComfyUI-direct: fetch from ComfyUI server via comfy_workflow_name
            // 2. MCP live-fetch: fetch from MCP server via mcp_workflow_id
            // 3. Stored JSON: use workflow_json from database
            $prompt = $step->refined_prompt ?? $step->purpose;

            if ($step->workflow->isComfyuiDirect()) {
                $workflowName = $step->workflow->comfy_workflow_name;
                Log::info("ExecutePlanJob: Using ComfyUI-direct path via MCP for workflow: {$workflowName}");
                $graph = $mcp->mcpFetchWorkflowFromComfyUI($workflowName);
                if ($graph === null) {
                    throw new \RuntimeException("Failed to fetch workflow from ComfyUI for '{$workflowName}'");
                }
                $injectedJson = $step->workflow->injectPromptIntoGraph($graph, $prompt, $comfyInputFiles);
            } elseif ($step->workflow->isMcpLiveFetch()) {
                $workflowId = $step->workflow->mcp_workflow_id;
                Log::info("ExecutePlanJob: Using MCP live-fetch path for workflow: {$workflowId}");
                $graph = $mcp->mcpFetchWorkflowGraph($workflowId);
                if ($graph === null) {
                    throw new \RuntimeException("Failed to fetch workflow graph from MCP for '{$workflowId}'");
                }
                $injectedJson = $step->workflow->injectPromptIntoGraph($graph, $prompt, $comfyInputFiles);
            } else {
                $injectedJson = $step->workflow->injectPrompt($prompt, $comfyInputFiles);
            }

            Log::info("ExecutePlanJob: Submitting step {$step->step_order} to ComfyUI", [
                'workflow'       => $step->workflow->name,
                'prompt_preview' => substr($step->refined_prompt ?? $step->purpose, 0, 80),
            ]);

            // ── Submit via McpService::mcpSubmitJob() ─────────────────────────
            // When COMFYUI_MCP_ENABLED=true  → routes through MCP sidecar,
            //                                  returns asset_id for provenance.
            // When COMFYUI_MCP_ENABLED=false → falls back to direct submitJob(),
            //                                  asset_id is null.
            $submission = $mcp->mcpSubmitJob($injectedJson);
            $jobId      = $submission['prompt_id'];
            $assetId    = $submission['asset_id'];

            // Persist both identifiers immediately — before polling starts —
            // so cancel_job can reference comfy_job_id even if polling times out.
            $step->update([
                'comfy_job_id' => $jobId,
                'mcp_asset_id' => $assetId,
            ]);

            Log::info("ExecutePlanJob: Step {$step->step_order} → ComfyUI job {$jobId}", [
                'asset_id' => $assetId,
            ]);

            // Poll until ComfyUI completes the job
            $storagePath = $this->pollUntilComplete($jobId, $mcp, $deadline);

            // Free VRAM immediately after the generation finishes.
            // The output file is already saved to storage, so models are safe
            // to unload now. Freeing here rather than at step start means VRAM
            // is released during the (potentially long) user approval wait,
            // preventing OOM when the next step loads different model weights.
            $this->freeVram($mcp, $step->step_order);

            // Mark awaiting approval — pause for user review
            $step->markAwaitingApproval($storagePath);
            Log::info("ExecutePlanJob: Step {$step->step_order} awaiting approval. Output: {$storagePath}");

            // Wait for user to approve or reject
            $this->waitForApproval($step, $plan, $deadline);

        } catch (\Throwable $e) {
            $step->markFailed($e->getMessage());
            throw $e;
        }
    }

    // ── ComfyUI polling ───────────────────────────────────────────────────────

    /**
     * Poll McpService::checkJobStatus() until the job is 'completed' or 'failed',
     * then retrieve the result via McpService::getJobResult() and return the
     * storage-relative path of the primary output file.
     *
     * Polling always goes direct to ComfyUI via checkJobStatus() / getJobResult()
     * regardless of COMFYUI_MCP_ENABLED — the MCP server has no polling advantage
     * over our existing direct implementation.
     *
     * McpService::checkJobStatus() returns:
     *   ['status' => 'queued'|'running'|'completed'|'failed', 'queue_position' => int|null, ...]
     *
     * McpService::getJobResult() returns:
     *   ['output_files' => [...], 'media_type' => string, 'storage_path' => string]
     */
    protected function pollUntilComplete(string $jobId, McpService $mcp, int $deadline): string
    {
        while (true) {
            if (time() >= $deadline) {
                throw new \RuntimeException("Deadline reached polling ComfyUI job {$jobId}");
            }

            $status = $mcp->checkJobStatus($jobId);

            Log::debug("ExecutePlanJob: Job {$jobId} status: {$status['status']}", [
                'queue_position' => $status['queue_position'] ?? null,
            ]);

            if ($status['status'] === 'completed') {
                $result      = $mcp->getJobResult($jobId);
                $storagePath = $result['storage_path'] ?? null;

                if (! $storagePath) {
                    throw new \RuntimeException("ComfyUI job {$jobId} completed but returned no output file.");
                }

                return $storagePath;
            }

            if ($status['status'] === 'failed') {
                throw new \RuntimeException("ComfyUI job {$jobId} failed during execution.");
            }

            // status is 'queued' or 'running' — keep waiting
            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    // ── Approval waiting ──────────────────────────────────────────────────────

    /**
     * Wait for user to approve or reject the step output.
     * On approval: return (outer loop continues to next step).
     * On rejection: wait for re-refinement then return (outer loop re-executes).
     * On cancellation: throws so the plan is marked failed cleanly.
     */
    protected function waitForApproval(WorkflowPlanStep $step, WorkflowPlan $plan, int $deadline): void
    {
        Log::info("ExecutePlanJob: Waiting for approval of step {$step->step_order}");

        while (true) {
            if (time() >= $deadline) {
                throw new \RuntimeException("Deadline waiting for approval of step {$step->step_order}");
            }

            $step->refresh();

            if ($step->isCompleted()) {
                Log::info("ExecutePlanJob: Step {$step->step_order} approved.");
                return;
            }

            if ($step->isCancelled()) {
                throw new \RuntimeException("Step {$step->step_order} cancelled during approval wait.");
            }

            if ($step->isPending()) {
                // User rejected — wait until re-refinement confirms a new prompt
                Log::info("ExecutePlanJob: Step {$step->step_order} rejected. Waiting for re-refinement.");
                $this->waitForReRefinement($step, $deadline);
                return; // Outer loop will re-execute this step
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * After rejection, wait until the step has a fresh refined_prompt and
     * is back in pending state — then return so outer loop can re-execute it.
     */
    protected function waitForReRefinement(WorkflowPlanStep $step, int $deadline): void
    {
        // Clear old prompt so outer loop knows it needs fresh execution
        $step->update(['refined_prompt' => null]);

        while (true) {
            if (time() >= $deadline) {
                throw new \RuntimeException("Deadline waiting for re-refinement of step {$step->step_order}");
            }

            $step->refresh();

            // Step has a new confirmed prompt — ready to re-execute
            if ($step->isPending() && ! empty($step->refined_prompt)) {
                Log::info("ExecutePlanJob: Step {$step->step_order} re-confirmed, will re-execute.");
                return;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    // ── Queue management ──────────────────────────────────────────────────────

    protected function dispatchNextQueued(string $sessionId): void
    {
        $next = WorkflowPlan::nextInQueue($sessionId);
        if ($next) {
            Log::info("ExecutePlanJob: Auto-dispatching next queued plan #{$next->id}");
            self::dispatch($next->id);
            $next->markRunning();
        }
    }

    // ── VRAM management ───────────────────────────────────────────────────────

    /**
     * Ask ComfyUI to unload all models and free GPU memory after a step completes.
     *
     * This is intentionally non-fatal: a failed free call is logged as a warning
     * but never propagates as an exception. The plan continues regardless — a
     * failed free means the next step may have less VRAM available, but failing
     * the entire plan over a cleanup call would be worse.
     */
    protected function freeVram(McpService $mcp, int $stepOrder): void
    {
        try {
            $result = $mcp->freeVram();

            if ($result['freed']) {
                Log::info("ExecutePlanJob: VRAM freed after step {$stepOrder}.");
            } else {
                Log::warning("ExecutePlanJob: VRAM free returned non-success after step {$stepOrder}.", [
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("ExecutePlanJob: VRAM free threw after step {$stepOrder} — continuing.", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Dependency file collection ────────────────────────────────────────────

    /**
     * Collect all input files for a step from three sources:
     *   1. Step-level upload  — $step->input_file_path (set via refinement phase)
     *   2. Plan-level uploads — $plan->input_files[] (attached during plan creation)
     *   3. Dependency outputs — prior steps that this step depends on
     *
     * Returns map of media_type => storage-relative path.
     * Files are NOT uploaded here — the caller (executeStep) handles ComfyUI upload.
     *
     * @return array<string, string>  e.g. ['image' => 'comfyui-inputs/foo.png', 'video' => '...']
     */
    protected function collectDependencyFiles(WorkflowPlanStep $step, WorkflowPlan $plan): array
    {
        $files = [];

        // ── 1a. Step-level multi-input map (new column — preferred) ────────────
        // {"image": "comfyui-inputs/a.png", "audio": "comfyui-inputs/b.mp3"}
        foreach ($step->input_files ?? [] as $mediaType => $path) {
            if (Storage::disk('public')->exists($path)) {
                $files[$mediaType] = $path;
                Log::info("ExecutePlanJob: Step-level {$mediaType} input (input_files) → {$path}");
            } else {
                throw new \RuntimeException("Step input file missing [{$mediaType}]: {$path}");
            }
        }

        // ── 1b. Legacy single-file column — only if input_files didn't already
        //        supply a file for this media type (backward compat, not overwritten)
        if (empty($files) && ! empty($step->input_file_path)) {
            $path = $step->input_file_path;
            if (Storage::disk('public')->exists($path)) {
                $mediaType        = $this->mediaTypeFromPath($path);
                $files[$mediaType] = $path;
                Log::info("ExecutePlanJob: Step-level input (legacy input_file_path) → {$path}");
            } else {
                throw new \RuntimeException("Step input file missing: {$path}");
            }
        }

        // ── 2. Plan-level uploads (attached during plan approval) ───────────────
        $planInputFiles     = $plan->input_files ?? [];
        $workflowInputTypes = $step->workflow->input_types ?? [];

        foreach ($planInputFiles as $file) {
            $mediaType   = $file['media_type'] ?? null;
            $storagePath = $file['storage_path'] ?? null;

            if (! $mediaType || ! $storagePath) {
                continue;
            }

            if (! in_array($mediaType, $workflowInputTypes, true)) {
                continue;
            }

            if (isset($files[$mediaType])) {
                continue; // Step-level upload takes priority
            }

            if (Storage::disk('public')->exists($storagePath)) {
                $files[$mediaType] = $storagePath;
                Log::info("ExecutePlanJob: Plan-level {$mediaType} input → {$storagePath}");
            } else {
                throw new \RuntimeException("Plan input file missing: {$storagePath}");
            }
        }

        // ── 3. Dependency outputs from prior steps ─────────────────────────────
        foreach ($step->depends_on ?? [] as $key => $value) {
            $stepOrder  = is_string($key) ? $value : $key;
            $neededType = is_string($key) ? $key : null;

            $dep = $plan->steps->firstWhere('step_order', $stepOrder);

            if (! $dep) {
                throw new \RuntimeException("Dependency step {$stepOrder} not found for step {$step->step_order}");
            }

            if (! $dep->output_path) {
                throw new \RuntimeException(
                    "Dependency step {$stepOrder} has no output. Cannot execute step {$step->step_order}."
                );
            }

            if (! Storage::disk('public')->exists($dep->output_path)) {
                throw new \RuntimeException("Dependency output file missing: {$dep->output_path}");
            }

            $fileType = $neededType ?? ($dep->workflow->output_type ?? null);

            if (! $fileType) {
                continue;
            }

            if (isset($files[$fileType])) {
                continue;
            }

            $files[$fileType] = $dep->output_path;
            Log::info("ExecutePlanJob: Dependency step {$stepOrder} output → {$fileType}: {$dep->output_path}");
        }

        // ── 4. Safety-net: scan all prior completed steps for still-uncovered types ──
        //
        // Catches two scenarios:
        //   a) depends_on map has a gap (e.g. LLM omitted a workflow from the READY signal
        //      so no step in the plan produces the needed type via explicit dependency).
        //   b) A step in the same execution layer happens to produce a needed type that
        //      was not wired explicitly — the layer system guarantees it completed first.
        //
        // Scans backwards (latest-match-wins) to be consistent with the planner.
        $requiredTypes = $step->workflow->input_types ?? [];

        if (! empty($requiredTypes)) {
            $uncoveredTypes = array_diff($requiredTypes, array_keys($files));

            if (! empty($uncoveredTypes)) {
                $priorSteps = $plan->steps
                    ->filter(fn (WorkflowPlanStep $s) =>
                        $s->step_order < $step->step_order
                        && $s->isCompleted()
                        && ! empty($s->output_path)
                        && Storage::disk('public')->exists($s->output_path)
                    )
                    ->sortByDesc('step_order')
                    ->values();

                foreach ($uncoveredTypes as $neededType) {
                    foreach ($priorSteps as $prior) {
                        $priorType = $prior->workflow->output_type
                            ?? $this->mediaTypeFromPath($prior->output_path);

                        if ($priorType !== $neededType) {
                            continue;
                        }

                        $files[$neededType] = $prior->output_path;
                        Log::info("ExecutePlanJob: Safety-net resolved {$neededType} from step {$prior->step_order} → {$prior->output_path}");
                        break;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Infer media type from a file path extension.
     */
    protected function mediaTypeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff']) => 'image',
            in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'])                 => 'video',
            in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'])          => 'audio',
            default                                                                   => 'image',
        };
    }
}