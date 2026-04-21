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

/**
 * PollStepJob - Phase 2 of Split Job Pattern
 * 
 * Checks if a ComfyUI job is complete. If not, re-dispatches itself with a delay.
 * If complete, retrieves the result and marks the step as awaiting approval.
 * 
 * This job runs quickly (< 1 second) and never blocks the queue worker.
 * It re-dispatches itself until the ComfyUI job completes or times out.
 */
class PollStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;  // Short timeout - just for status check
    public int $tries   = 1;   // Don't retry - re-dispatch handles continuation

    protected const POLL_INTERVAL_SECONDS = 5;
    protected const MAX_POLL_ATTEMPTS = 720; // 1 hour at 5 second intervals

    public function __construct(
        public readonly int $stepId,
        public readonly int $planId,
        public readonly int $pollAttempt = 0
    ) {}

    public function handle(McpService $mcp): void
    {
        $step = WorkflowPlanStep::with('workflow')->findOrFail($this->stepId);
        $plan = WorkflowPlan::findOrFail($this->planId);

        // Check if step was cancelled or failed externally
        if ($step->isCancelled() || $step->isFailed()) {
            Log::info("PollStepJob: Step {$step->step_order} is {$step->status}, stopping poll");
            return;
        }

        // Check timeout
        if ($this->pollAttempt >= self::MAX_POLL_ATTEMPTS) {
            Log::warning("PollStepJob: Step {$step->step_order} reached max poll attempts, marking as orphaned");
            $step->markOrphaned();
            return;
        }

        $jobId = $step->comfy_job_id;

        if (! $jobId) {
            Log::error("PollStepJob: Step {$step->step_order} has no comfy_job_id, marking as failed");
            $step->markFailed('No ComfyUI job ID found');
            return;
        }

        try {
            // Check job status
            $status = $mcp->checkJobStatus($jobId);

            Log::debug("PollStepJob: Job {$jobId} status: {$status['status']}", [
                'step_order'     => $step->step_order,
                'queue_position' => $status['queue_position'] ?? null,
                'poll_attempt'   => $this->pollAttempt,
            ]);

            if ($status['status'] === 'completed') {
                // Job is complete - retrieve result
                $this->handleCompletion($step, $plan, $mcp, $jobId);
                return;
            }

            if ($status['status'] === 'failed') {
                Log::error("PollStepJob: ComfyUI job {$jobId} failed");
                $step->markFailed('ComfyUI job failed during execution');
                return;
            }

            // Job is still queued or running - re-dispatch with delay
            Log::debug("PollStepJob: Job {$jobId} still {$status['status']}, re-dispatching", [
                'step_order'   => $step->step_order,
                'poll_attempt' => $this->pollAttempt + 1,
            ]);

            self::dispatch($this->stepId, $this->planId, $this->pollAttempt + 1)
                ->delay(now()->addSeconds(self::POLL_INTERVAL_SECONDS));

        } catch (\Exception $e) {
            Log::error("PollStepJob: Error polling step {$step->step_order}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-dispatch on transient errors (network issues, etc.)
            if ($this->pollAttempt < self::MAX_POLL_ATTEMPTS) {
                self::dispatch($this->stepId, $this->planId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(self::POLL_INTERVAL_SECONDS));
            } else {
                $step->markFailed('Polling failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle job completion - retrieve result, free VRAM, mark awaiting approval
     */
    protected function handleCompletion(
        WorkflowPlanStep $step,
        WorkflowPlan $plan,
        McpService $mcp,
        string $jobId
    ): void {
        try {
            // Retrieve output files
            $result = $mcp->getJobResult($jobId);
            $storagePath = $result['storage_path'] ?? null;

            if (! $storagePath) {
                throw new \RuntimeException("ComfyUI job {$jobId} completed but returned no output file");
            }

            Log::info("PollStepJob: Step {$step->step_order} completed", [
                'output_path' => $storagePath,
            ]);

            // Free VRAM immediately after generation
            $this->freeVram($mcp, $step->step_order);

            // Mark step as awaiting approval
            $step->markAwaitingApproval($storagePath);

            Log::info("PollStepJob: Step {$step->step_order} awaiting approval");

        } catch (\Exception $e) {
            Log::error("PollStepJob: Failed to retrieve result for step {$step->step_order}", [
                'error' => $e->getMessage(),
            ]);
            $step->markFailed('Failed to retrieve result: ' . $e->getMessage());
        }
    }

    /**
     * Free VRAM after step completion (non-fatal)
     */
    protected function freeVram(McpService $mcp, int $stepOrder): void
    {
        try {
            $result = $mcp->freeVram();

            if ($result['freed']) {
                Log::info("PollStepJob: VRAM freed after step {$stepOrder}");
            } else {
                Log::warning("PollStepJob: VRAM free returned non-success after step {$stepOrder}", [
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("PollStepJob: VRAM free threw after step {$stepOrder} — continuing", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
