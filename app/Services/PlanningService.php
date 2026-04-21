<?php

namespace App\Services;

use App\Ai\Agents\OrchestratorAgent;
use App\Ai\Helpers\ExecutionLayerHelper;
use App\Models\Workflow;
use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use Illuminate\Support\Facades\Log;

class PlanningService
{
    use ExecutionLayerHelper;

    protected string $model;

    public function __construct(string $model = 'mistral:7b')
    {
        $this->model = $model;
    }

    /**
     * Stream planning conversation with OrchestratorAgent
     * 
     * @param array $messages Conversation history
     * @param callable $chunkCallback Called for each streamed chunk
     * @return array Signal data (type, plan, workflow_ids, etc.)
     */
    public function streamPlanning(array $messages, callable $chunkCallback): array
    {
        $agent = new OrchestratorAgent($this->model);

        $fullText = $agent->stream($messages, $chunkCallback);
        $signal = $agent->parseSignal($fullText, $messages);

        // Handle ambiguous signal - fetch workflow details
        if ($signal['type'] === 'ambiguous') {
            $workflows = Workflow::active()
                ->whereIn('id', $signal['workflow_ids'])
                ->get(['id', 'name', 'description', 'output_type', 'type', 'input_types'])
                ->map(fn($w) => [
                    'id'          => $w->id,
                    'name'        => $w->name,
                    'description' => $w->description,
                    'output_type' => $w->output_type,
                    'type'        => $w->type,
                    'input_types' => $w->input_types ?? [],
                ])
                ->values()
                ->all();

            $signal['workflows'] = $workflows;
        }

        // Handle create_workflow signal - extract intent
        if ($signal['type'] === 'create_workflow') {
            $userIntent = '';
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    $userIntent = $msg['content'];
                    break;
                }
            }
            if (empty($userIntent) && !empty($signal['intent'])) {
                $userIntent = $signal['intent'];
            }
            $signal['intent'] = $userIntent;
        }

        // Try to parse plan if signal doesn't contain one
        if ($signal['type'] === 'ready' && $signal['plan'] === null) {
            $plan = $agent->parsePlan($fullText);
            if ($plan !== null) {
                $signal['plan'] = $plan;
            }
        }

        return $signal;
    }

    /**
     * Create WorkflowPlan and WorkflowPlanStep records from approved plan
     * 
     * @param string $sessionId User session ID
     * @param string $userIntent Original user intent
     * @param array $steps Plan steps with workflow_id, purpose, depends_on, etc.
     * @param array $inputFiles Uploaded input files
     * @return WorkflowPlan Created plan with steps loaded
     */
    public function createPlan(string $sessionId, string $userIntent, array $steps, array $inputFiles = []): WorkflowPlan
    {
        // Create plan record
        $plan = WorkflowPlan::create([
            'session_id'  => $sessionId,
            'user_intent' => $userIntent,
            'plan_steps'  => $steps,
            'status'      => 'pending',
            'input_files' => $inputFiles,
        ]);

        // Create step records
        foreach ($steps as $stepData) {
            $workflow = Workflow::findOrFail((int) $stepData['workflow_id']);

            WorkflowPlanStep::create([
                'plan_id'         => $plan->id,
                'workflow_id'     => $workflow->id,
                'step_order'      => (int) $stepData['step_order'],
                'execution_layer' => (int) ($stepData['execution_layer'] ?? 0),
                'workflow_type'   => $stepData['workflow_type'],
                'purpose'         => $stepData['purpose'],
                'depends_on'      => $stepData['depends_on'] ?? [],
                'status'          => 'pending',
            ]);
        }

        // Recompute execution layers (authoritative backend pass)
        $stepsArray = $steps;
        $this->recomputeExecutionLayers($stepsArray);

        // Update database with recomputed layers
        foreach ($stepsArray as $stepData) {
            WorkflowPlanStep::where('plan_id', $plan->id)
                ->where('step_order', $stepData['step_order'])
                ->update(['execution_layer' => $stepData['execution_layer']]);
        }

        Log::info("PlanningService: Created plan #{$plan->id} with " . count($steps) . " steps");

        // Reload with steps
        return $plan->load('steps.workflow');
    }

    /**
     * Get formatted step data for API response
     * 
     * @param WorkflowPlan $plan
     * @return array
     */
    public function getStepsPayload(WorkflowPlan $plan): array
    {
        return $plan->steps()->orderBy('step_order')->with('workflow')->get()
            ->map(fn($s) => [
                'id'              => $s->id,
                'step_order'      => $s->step_order,
                'execution_layer' => $s->execution_layer,
                'purpose'         => $s->purpose,
                'workflow_type'   => $s->workflow_type,
                'input_types'     => $s->workflow->input_types ?? [],
                'output_type'     => $s->workflow->output_type ?? null,
                'depends_on'      => $s->depends_on ?? [],
            ])
            ->toArray();
    }
}
