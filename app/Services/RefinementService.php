<?php

namespace App\Services;

use App\Ai\Agents\WorkflowOptimizerAgent;
use App\Models\WorkflowPlanStep;
use Illuminate\Support\Facades\Log;

class RefinementService
{
    protected string $model;

    public function __construct(string $model = 'mistral:7b')
    {
        $this->model = $model;
    }

    /**
     * Stream prompt refinement conversation with WorkflowOptimizerAgent
     * 
     * @param WorkflowPlanStep $step Step to refine
     * @param array $messages Conversation history
     * @param int $turnNumber Current turn number
     * @param bool $isRedo Whether this is a redo after rejection
     * @param callable $chunkCallback Called for each streamed chunk
     * @return string|null Approved prompt if agent signals approval, null otherwise
     */
    public function streamRefinement(
        WorkflowPlanStep $step,
        array $messages,
        int $turnNumber,
        bool $isRedo,
        callable $chunkCallback
    ): ?string {
        $outputType = $step->workflow->output_type;
        $agent = new WorkflowOptimizerAgent($this->model);

        // On redo, nudge turnNumber to skip "ask clarifying questions" phase
        if ($isRedo && $turnNumber <= 1) {
            $turnNumber = 2;
        }

        $fullText = $agent->stream($messages, $outputType, $turnNumber, $chunkCallback);
        $approvedPrompt = $agent->parseApprovedPrompt($fullText);

        return $approvedPrompt;
    }

    /**
     * Confirm a step with refined prompt and/or input files
     * 
     * @param WorkflowPlanStep $step
     * @param string|null $refinedPrompt
     * @param array $inputFiles Multi-input map: {"image": "path/to/file.png"}
     * @param string|null $legacyInputFilePath Legacy single-file path (backward compat)
     * @return WorkflowPlanStep Updated step
     */
    public function confirmStep(
        WorkflowPlanStep $step,
        ?string $refinedPrompt = null,
        array $inputFiles = [],
        ?string $legacyInputFilePath = null
    ): WorkflowPlanStep {
        $updateData = [];

        // Only write refined_prompt when provided and not already confirmed
        if ($refinedPrompt && empty($step->refined_prompt)) {
            $updateData['refined_prompt'] = $refinedPrompt;
        } elseif ($refinedPrompt) {
            // Explicit user re-confirmation (e.g. redo) - always accept
            $updateData['refined_prompt'] = $refinedPrompt;
        }

        // Multi-input files: merge into existing map so successive chain calls accumulate
        if (!empty($inputFiles)) {
            $existing = $step->input_files ?? [];
            $updateData['input_files'] = array_merge($existing, $inputFiles);
        }

        // Legacy single-file path - still supported for backward compat
        if ($legacyInputFilePath && empty($inputFiles)) {
            $updateData['input_file_path'] = $legacyInputFilePath;
        }

        if (!empty($updateData)) {
            $step->update($updateData);
        }

        Log::info("RefinementService: Confirmed step #{$step->id} (order {$step->step_order})");

        return $step->fresh();
    }

    /**
     * Reset step for re-refinement after rejection
     * 
     * @param WorkflowPlanStep $step
     * @param string|null $rejectionReason
     * @return WorkflowPlanStep
     */
    public function resetStepForRefinement(WorkflowPlanStep $step, ?string $rejectionReason = null): WorkflowPlanStep
    {
        $step->resetForRefinement();

        // Null the refined_prompt so dispatch() cannot re-use stale prompt
        // input_file_path and input_files are preserved - user's uploads still valid
        $step->update(['refined_prompt' => null]);

        Log::info("RefinementService: Reset step #{$step->id} for re-refinement", [
            'rejection_reason' => $rejectionReason,
        ]);

        return $step->fresh();
    }
}
