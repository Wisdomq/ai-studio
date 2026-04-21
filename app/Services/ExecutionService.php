<?php

namespace App\Services;

use App\Jobs\CoordinatePlanJob;
use App\Models\WorkflowPlan;
use Illuminate\Support\Facades\Log;

class ExecutionService
{
    /**
     * Dispatch a plan for immediate execution
     * 
     * @param WorkflowPlan $plan
     * @return array Result with success status and any errors
     */
    public function dispatchPlan(WorkflowPlan $plan): array
    {
        // Validate all steps have confirmed prompts
        $unconfirmed = $plan->steps()->whereNull('refined_prompt')->count();
        if ($unconfirmed > 0) {
            return [
                'success' => false,
                'error'   => "{$unconfirmed} step(s) need confirmed prompts.",
            ];
        }

        // Don't block re-dispatch after rejection - only block if truly running
        if ($plan->isRunning()) {
            return [
                'success' => false,
                'error'   => 'Plan is already running.',
            ];
        }

        // Mark plan as running before dispatching coordinator
        $plan->markRunning();

        CoordinatePlanJob::dispatch($plan->id);

        Log::info("ExecutionService: Dispatched plan #{$plan->id}");

        return [
            'success' => true,
            'plan_id' => $plan->id,
        ];
    }

    /**
     * Add plan to queue or auto-dispatch if nothing running
     * 
     * @param WorkflowPlan $plan
     * @param string $sessionId
     * @return array Result with success, plan_id, queue_position, auto_dispatched
     */
    public function queuePlan(WorkflowPlan $plan, string $sessionId): array
    {
        // Validate all steps have confirmed prompts
        $unconfirmed = $plan->steps()->whereNull('refined_prompt')->count();
        if ($unconfirmed > 0) {
            return [
                'success' => false,
                'error'   => "{$unconfirmed} step(s) need confirmed prompts.",
            ];
        }

        // Auto-dispatch immediately if nothing running
        if (!WorkflowPlan::hasRunning($sessionId)) {
            $plan->markRunning();
            CoordinatePlanJob::dispatch($plan->id);

            Log::info("ExecutionService: Auto-dispatched plan #{$plan->id} (queue empty)");

            return [
                'success'         => true,
                'plan_id'         => $plan->id,
                'auto_dispatched' => true,
            ];
        }

        // Otherwise add to queue backlog
        $plan->addToQueue();

        Log::info("ExecutionService: Queued plan #{$plan->id} at position {$plan->fresh()->queue_position}");

        return [
            'success'         => true,
            'plan_id'         => $plan->id,
            'queue_position'  => $plan->fresh()->queue_position,
            'auto_dispatched' => false,
        ];
    }

    /**
     * Run next queued plan
     * 
     * @param string $sessionId
     * @return array Result with success, plan_id, or error
     */
    public function runNextInQueue(string $sessionId): array
    {
        if (WorkflowPlan::hasRunning($sessionId)) {
            return [
                'success' => false,
                'error'   => 'A job is already running.',
            ];
        }

        $next = WorkflowPlan::nextInQueue($sessionId);
        if (!$next) {
            return [
                'success' => false,
                'error'   => 'No queued plans found.',
            ];
        }

        $next->markRunning();
        CoordinatePlanJob::dispatch($next->id);

        Log::info("ExecutionService: Dispatched next queued plan #{$next->id}");

        return [
            'success' => true,
            'plan_id' => $next->id,
        ];
    }

    /**
     * Get plan status payload with output paths
     * 
     * @param WorkflowPlan $plan
     * @return array Status payload
     */
    public function getPlanStatus(WorkflowPlan $plan): array
    {
        $payload = $plan->statusPayload();

        // Inject output_path alongside output_url on each step
        if (isset($payload['steps']) && is_array($payload['steps'])) {
            $stepModels = $plan->steps()->orderBy('step_order')->get()->keyBy('step_order');

            $payload['steps'] = array_map(function (array $stepData) use ($stepModels) {
                $order = $stepData['step_order'] ?? null;
                if ($order !== null && isset($stepModels[$order])) {
                    $stepData['output_path'] = $stepModels[$order]->output_path;
                }
                return $stepData;
            }, $payload['steps']);
        }

        return $payload;
    }

    /**
     * Get all session plans for jobs panel
     * 
     * @param string $sessionId
     * @param int $limit
     * @return array Plans with status payloads
     */
    public function getSessionPlans(string $sessionId, int $limit = 20): array
    {
        $plans = WorkflowPlan::where('session_id', $sessionId)
            ->with('steps')
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn(WorkflowPlan $plan) => $plan->statusPayload());

        return $plans->toArray();
    }
}
