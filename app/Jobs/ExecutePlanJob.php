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

    protected const POLL_INTERVAL_SECONDS    = 5;
    protected const DEADLINE_BUFFER_SECONDS  = 60;

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

    protected function runPlanLoop(WorkflowPlan $plan, McpService $mcp, int $deadline): void
    {
        while (true) {
            if (time() >= $deadline) {
                throw new \RuntimeException("Plan #{$plan->id} exceeded timeout.");
            }

            $plan->load('steps.workflow');
            $steps = $plan->steps;

            // All steps done?
            if ($steps->every(fn (WorkflowPlanStep $s) => $s->isCompleted())) {
                break;
            }

            // Any hard failures?
            $failed = $steps->first(fn (WorkflowPlanStep $s) => $s->isFailed());
            if ($failed) {
                throw new \RuntimeException("Step {$failed->step_order} failed: {$failed->error_message}");
            }

            // Find next pending step whose dependencies are met
            $next = $steps->first(function (WorkflowPlanStep $s) use ($plan) {
                return $s->isPending() && $s->isReady($plan);
            });

            if ($next !== null) {
                $this->executeStep($next, $plan, $mcp, $deadline);
                continue; // Loop immediately after submitting — don't sleep
            }

            // No step ready — waiting for approval or dependency
            sleep(self::POLL_INTERVAL_SECONDS);
        }
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
                $comfyFilename              = $mcp->uploadInputFile($storagePath, $mediaType);
                $comfyInputFiles[$mediaType] = $comfyFilename;
                Log::info("ExecutePlanJob: Uploaded {$mediaType} → {$comfyFilename}");
            }

            // Inject refined prompt + file placeholders into workflow JSON
            $injectedJson = $step->workflow->injectPrompt(
                $step->refined_prompt ?? $step->purpose,
                $comfyInputFiles
            );

            Log::info("ExecutePlanJob: Submitting step {$step->step_order} to ComfyUI", [
                'workflow'       => $step->workflow->name,
                'prompt_preview' => substr($step->refined_prompt ?? $step->purpose, 0, 80),
            ]);

            // Submit to ComfyUI via MCP
            $jobId = $mcp->submitJob($injectedJson);
            $step->update(['comfy_job_id' => $jobId]);

            Log::info("ExecutePlanJob: Step {$step->step_order} → ComfyUI job {$jobId}");

            // Poll until ComfyUI completes the job
            $storagePath = $this->pollUntilComplete($jobId, $mcp, $deadline);

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

            Log::debug("ExecutePlanJob: Job {$jobId} status: {$status['status']}");

            if ($status['status'] === 'completed') {
                // Retrieve and download the output file
                $result = $mcp->getJobResult($jobId);

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

    // ── Dependency file collection ────────────────────────────────────────────

    protected function collectDependencyFiles(WorkflowPlanStep $step, WorkflowPlan $plan): array
    {
        $files      = [];
        $injectKeys = $step->workflow->inject_keys ?? [];

        // ── 1. User-uploaded input file (supplied during refinement phase) ────
        // This handles workflows like image_to_video / face_swap where the user
        // provides a source file from the start, not from a prior step's output.
        if (! empty($step->input_file_path)) {
            if (! Storage::disk('public')->exists($step->input_file_path)) {
                throw new \RuntimeException(
                    "User-uploaded input file missing: {$step->input_file_path}"
                );
            }

            // Determine media type from file extension
            $ext       = strtolower(pathinfo($step->input_file_path, PATHINFO_EXTENSION));
            $mediaType = match (true) {
                in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) => 'image',
                in_array($ext, ['mp4', 'webm', 'mov'])                => 'video',
                in_array($ext, ['mp3', 'wav', 'ogg', 'flac'])         => 'audio',
                default                                                => 'image',
            };

            $files[$mediaType] = $step->input_file_path;
            Log::info("ExecutePlanJob: User-uploaded {$mediaType} input → {$step->input_file_path}");
        }

        // ── 2. Dependency outputs from prior steps ────────────────────────────
        foreach ($step->depends_on ?? [] as $depOrder) {
            $dep = $plan->steps->firstWhere('step_order', $depOrder);

            if (! $dep || ! $dep->output_path) {
                throw new \RuntimeException(
                    "Dependency step {$depOrder} has no output. Cannot execute step {$step->step_order}."
                );
            }

            if (! Storage::disk('public')->exists($dep->output_path)) {
                throw new \RuntimeException(
                    "Dependency output file missing: {$dep->output_path}"
                );
            }

            $depOutputType = $dep->workflow->output_type ?? null;
            if (! $depOutputType) {
                continue;
            }

            $placeholder           = $injectKeys[$depOutputType] ?? '{{INPUT_FILE}}';
            $files[$depOutputType] = $dep->output_path;

            Log::info("ExecutePlanJob: Dependency step {$depOrder} ({$depOutputType}) → placeholder {$placeholder}");
        }

        return $files;
    }
}