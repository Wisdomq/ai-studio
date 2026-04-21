<?php

namespace App\Services;

use App\Models\WorkflowPlanStep;
use Illuminate\Support\Facades\Log;

class ApprovalService
{
    /**
     * Approve a step that's awaiting approval
     * 
     * @param WorkflowPlanStep $step
     * @return array Result with success status
     */
    public function approveStep(WorkflowPlanStep $step): array
    {
        if (!$step->isAwaitingApproval()) {
            return [
                'success' => false,
                'error'   => 'Step is not awaiting approval.',
            ];
        }

        $step->markCompleted();

        Log::info("ApprovalService: Step #{$step->id} (order {$step->step_order}) approved");

        return [
            'success'    => true,
            'step_order' => $step->step_order,
            'status'     => 'completed',
        ];
    }

    /**
     * Reject a step and reset for re-refinement
     * 
     * @param WorkflowPlanStep $step
     * @param string|null $rejectionReason
     * @return array Result with step context for redo
     */
    public function rejectStep(WorkflowPlanStep $step, ?string $rejectionReason = null): array
    {
        if (!$step->isAwaitingApproval()) {
            return [
                'success' => false,
                'error'   => 'Step is not awaiting approval.',
            ];
        }

        $plan = $step->plan;

        $step->resetForRefinement();

        // Null the refined_prompt so dispatch() cannot re-use stale prompt
        // input_file_path and input_files are preserved
        $step->update(['refined_prompt' => null]);

        Log::info("ApprovalService: Step #{$step->id} (order {$step->step_order}) rejected", [
            'rejection_reason' => $rejectionReason,
        ]);

        // Return step context for redo seed message
        return [
            'success'          => true,
            'step_order'       => $step->step_order,
            'status'           => 'pending',
            'purpose'          => $step->purpose,
            'user_intent'      => $plan->user_intent,
            'rejection_reason' => $rejectionReason,
        ];
    }

    /**
     * Cancel a running or awaiting-approval step
     * 
     * @param WorkflowPlanStep $step
     * @param McpService $mcpService
     * @return array Result with success status
     */
    public function cancelStep(WorkflowPlanStep $step, McpService $mcpService): array
    {
        // Only running steps can be cancelled
        if (!$step->isRunning() && !$step->isAwaitingApproval()) {
            return [
                'success' => false,
                'error'   => "Step {$step->step_order} is not in a cancellable state (status: {$step->status}).",
            ];
        }

        // Fire-and-forget ComfyUI cancel
        if ($step->comfy_job_id) {
            $cancelled = $mcpService->cancelJob($step->comfy_job_id);
            Log::info("ApprovalService: ComfyUI cancel result", [
                'job_id'    => $step->comfy_job_id,
                'cancelled' => $cancelled,
            ]);
        }

        $step->markCancelled();

        Log::info("ApprovalService: Step #{$step->id} (order {$step->step_order}) cancelled");

        return ['success' => true];
    }
}
