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
 * CoordinatePlanJob - Orchestrates workflow plan execution using split-job pattern
 * 
 * This job coordinates the execution of a workflow plan by:
 * 1. Checking which steps are ready to execute
 * 2. Dispatching SubmitStepJob for ready steps
 * 3. Re-dispatching itself to check for next steps
 * 
 * This job never blocks - it runs quickly and re-dispatches itself.
 * Actual ComfyUI submission and polling are handled by SubmitStepJob and PollStepJob.
 */
class CoordinatePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 1;

    protected const CHECK_INTERVAL_SECONDS = 5;

    public function __construct(public readonly int $planId) {}

    public function handle(McpService $mcp): void
    {
        $plan = WorkflowPlan::with('steps.workflow')->findOrFail($this->planId);

        Log::debug("CoordinatePlanJob: Checking plan #{$plan->id}");

        // Check if plan is complete
        if ($plan->steps->every(fn (WorkflowPlanStep $s) => $s->isCompleted())) {
            $plan->markCompleted();
            Log::info("CoordinatePlanJob: Plan #{$plan->id} completed");
            
            // Auto-dispatch next queued plan
            $this->dispatchNextQueued($plan->session_id);
            return;
        }

        // Check for failures or cancellations
        $failed = $plan->steps->first(fn (WorkflowPlanStep $s) => $s->isFailed());
        if ($failed) {
            $plan->markFailed();
            Log::error("CoordinatePlanJob: Plan #{$plan->id} failed at step {$failed->step_order}");
            $this->dispatchNextQueued($plan->session_id);
            return;
        }

        $cancelled = $plan->steps->first(fn (WorkflowPlanStep $s) => $s->isCancelled());
        if ($cancelled) {
            $plan->markFailed();
            Log::info("CoordinatePlanJob: Plan #{$plan->id} cancelled at step {$cancelled->step_order}");
            $this->dispatchNextQueued($plan->session_id);
            return;
        }

        // Find active layer (lowest layer with pending steps)
        $activeLayer = $plan->steps
            ->filter(fn (WorkflowPlanStep $s) => $s->isPending())
            ->min('execution_layer');

        if ($activeLayer === null) {
            // No pending steps - all are running, awaiting approval, or complete
            Log::debug("CoordinatePlanJob: No pending steps, re-dispatching");
            self::dispatch($this->planId)->delay(now()->addSeconds(self::CHECK_INTERVAL_SECONDS));
            return;
        }

        // Guard: all layers below active must be fully complete
        $lowerLayersPending = $plan->steps->filter(
            fn (WorkflowPlanStep $s) => $s->execution_layer < $activeLayer && ! $s->isCompleted()
        );

        if ($lowerLayersPending->isNotEmpty()) {
            Log::debug("CoordinatePlanJob: Waiting for lower layers to complete");
            self::dispatch($this->planId)->delay(now()->addSeconds(self::CHECK_INTERVAL_SECONDS));
            return;
        }

        // Find next ready step in active layer
        $next = $plan->steps->first(function (WorkflowPlanStep $s) use ($plan, $activeLayer) {
            return $s->isPending()
                && $s->execution_layer === $activeLayer
                && $s->isReady($plan);
        });

        if ($next !== null) {
            Log::info("CoordinatePlanJob: Dispatching step {$next->step_order} (layer {$activeLayer})");
            SubmitStepJob::dispatch($next->id, $plan->id);
            
            // Re-dispatch immediately to check for more steps
            self::dispatch($this->planId)->delay(now()->addSeconds(2));
            return;
        }

        // No step ready yet - waiting for approval or dependency
        Log::debug("CoordinatePlanJob: No steps ready in layer {$activeLayer}, re-dispatching");
        self::dispatch($this->planId)->delay(now()->addSeconds(self::CHECK_INTERVAL_SECONDS));
    }

    protected function dispatchNextQueued(string $sessionId): void
    {
        $next = WorkflowPlan::nextInQueue($sessionId);
        if ($next) {
            Log::info("CoordinatePlanJob: Auto-dispatching next queued plan #{$next->id}");
            $next->markRunning();
            self::dispatch($next->id);
        }
    }
}
