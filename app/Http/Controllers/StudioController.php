<?php

namespace App\Http\Controllers;

use App\Ai\Agents\OrchestratorAgent;
use App\Ai\Agents\WorkflowOptimizerAgent;
use App\Ai\Helpers\ExecutionLayerHelper;
use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Models\Workflow;
use App\Ai\Skills\WorkflowBuilderSkill;
use App\Services\WorkflowBuilderService;
use App\Services\McpService;
use App\Services\PlanningService;
use App\Services\RefinementService;
use App\Services\ExecutionService;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StudioController extends Controller
{
    protected string $model = 'mistral:7b'; // Change model globally here

    // ─────────────────────────────────────────────────────────────────────────
    // Views
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): \Illuminate\View\View
    {
        $workflows = \App\Models\Workflow::active()
            ->orderBy('output_type')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'output_type', 'input_types'])
            ->map(fn ($w) => [
                'id'          => $w->id,
                'name'        => $w->name,
                'description' => $w->description,
                'output_type' => $w->output_type,
                'input_types' => $w->input_types ?? [],
            ])
            ->values()
            ->all();

        return view('studio.index', compact('workflows'));
    }

    public function result(WorkflowPlan $plan): \Illuminate\View\View
    {
        $this->authorisePlan($plan);
        $plan->load('steps.workflow');

        return view('studio.result', compact('plan'));
    }

    public function destroy(WorkflowPlan $plan): \Illuminate\Http\RedirectResponse
    {
        $this->authorisePlan($plan);
        $plan->delete();

        return redirect()->route('studio.generations')->with('success', 'Generation deleted.');
    }

    public function generations(): \Illuminate\View\View
    {
        $sessionId = session()->getId();
        $plans     = WorkflowPlan::where('session_id', $sessionId)
            ->where('status', 'completed')
            ->with(['steps' => fn($q) => $q->orderBy('step_order')])
            ->latest()
            ->paginate(12);

        return view('studio.generations', compact('plans'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Workflow Proposal — confirm (save) or cancel
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /studio/workflow/confirm
     * User has reviewed the proposal and clicked Accept.
     * Now we actually build + save the workflow.
     */
    public function confirmWorkflow(Request $request): JsonResponse
    {
        $request->validate([
            'intent' => 'required|string|min:5',
        ]);

        try {
            $builder  = new WorkflowBuilderSkill($this->model);
            $result   = $builder->buildFromIntent($request->input('intent'));

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error'   => $result['error'] ?? 'Workflow creation failed.',
                ], 500);
            }

            $workflow = $result['workflow'];
            Log::info('StudioController: Workflow confirmed and saved', [
                'workflow_id' => $workflow->id,
                'name'        => $workflow->name,
            ]);

            return response()->json([
                'success'     => true,
                'workflow_id' => $workflow->id,
                'name'        => $workflow->name,
                'output_type' => $workflow->output_type,
                'description' => $workflow->description,
            ]);

        } catch (\Throwable $e) {
            Log::error('StudioController: confirmWorkflow failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Failed to save workflow: ' . $e->getMessage(),
            ], 500);
        }
    }

    //Workflow Creation Endpoint — triggered by OrchestratorAgent signal

    public function createWorkflow(Request $request): JsonResponse
    {
        $request->validate([
            'intent' => 'required|string|min:5',
        ]);

        try {
            $builder = new WorkflowBuilderService();

            $workflow = $builder->buildFromIntent($request->input('intent'));

            return response()->json([
                'success'  => true,
                'workflow' => [
                    'id'          => $workflow->id,
                    'name'        => $workflow->name,
                    'description' => $workflow->description,
                    'type'        => $workflow->type,
                    'output_type' => $workflow->output_type,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('StudioController: Workflow creation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to create workflow. ' . $e->getMessage(),
            ], 500);
        }
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Phase 1 — Planning (SSE)
    // ─────────────────────────────────────────────────────────────────────────

    public function planner(Request $request, PlanningService $planningService): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'messages' => 'required|array',
            'messages.*.role'    => 'required|in:user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        $messages = $request->input('messages');

        return response()->stream(function () use ($messages, $planningService) {

            try {
                $signal = $planningService->streamPlanning($messages, function (string $chunk) {
                    $this->sseEmit(['type' => 'chunk', 'content' => $chunk]);
                });

                // Emit appropriate response based on signal type
                if ($signal['type'] === 'ready' && isset($signal['plan'])) {
                    $this->sseEmit(['type' => 'plan', 'plan' => $signal['plan']]);

                } elseif ($signal['type'] === 'ambiguous') {
                    $this->sseEmit([
                        'type'      => 'ambiguous',
                        'workflows' => $signal['workflows'],
                    ]);

                } elseif ($signal['type'] === 'create_workflow') {
                    $this->sseEmit([
                        'type'    => 'workflow_proposed',
                        'intent'  => $signal['intent'],
                    ]);
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('StudioController: Ollama connection timeout in planner', [
                    'error' => $e->getMessage(),
                ]);
                $this->sseEmit([
                    'type'    => 'error',
                    'message' => 'The AI is taking too long to respond — the GPU may be busy with a generation job. Please wait a moment and try again.',
                ]);
            } catch (\Throwable $e) {
                Log::error('StudioController: Planner stream failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->sseEmit([
                    'type'    => 'error',
                    'message' => 'Something went wrong. Please try again.',
                ]);
            }

            $this->sseDone();

        }, 200, $this->sseHeaders());
    }

    /**
     * POST /studio/plan/approve
     * Create WorkflowPlan + WorkflowPlanStep DB records from approved plan.
     */
    public function approvePlan(Request $request, PlanningService $planningService): JsonResponse
    {
        $request->validate([
            'user_intent' => 'required|string',
            'steps'       => 'required|array|min:1',
            'steps.*.step_order'      => 'required|integer|min:0',
            'steps.*.execution_layer' => 'nullable|integer|min:0',
            'steps.*.workflow_id'     => 'required|integer|exists:workflows,id',
            'steps.*.workflow_type'   => 'required|string',
            'steps.*.purpose'         => 'required|string',
            'steps.*.prompt_hint'     => 'nullable|string',
            'steps.*.depends_on'      => 'nullable|array',
            'files'        => 'nullable|array',
            'files.*.storage_path' => 'required|string',
            'files.*.media_type'   => 'required|string|in:image,video,audio',
        ]);

        $plan = $planningService->createPlan(
            session()->getId(),
            $request->input('user_intent'),
            $request->input('steps'),
            $request->input('files', [])
        );

        session(['workflow_session_id' => session()->getId()]);

        return response()->json([
            'plan_id'     => $plan->id,
            'input_files' => $plan->input_files,
            'steps'       => $planningService->getStepsPayload($plan),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 2 — Prompt Refinement (SSE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /studio/plan/refine-step
     * SSE stream: WorkflowOptimizerAgent for one plan step.
     */
    public function refineStep(Request $request, RefinementService $refinementService): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'plan_id'     => 'required|integer|exists:workflow_plans,id',
            'step_order'  => 'required|integer|min:0',
            'messages'    => 'required|array',
            'turn_number' => 'required|integer|min:1|max:20',
            'is_redo'     => 'boolean',
        ]);

        $plan = WorkflowPlan::findOrFail($request->input('plan_id'));
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $request->input('step_order'))->with('workflow')->firstOrFail();

        return response()->stream(function () use ($request, $step, $refinementService) {
            try {
                $approvedPrompt = $refinementService->streamRefinement(
                    $step,
                    $request->input('messages'),
                    $request->input('turn_number'),
                    (bool) $request->input('is_redo', false),
                    function (string $chunk) {
                        $this->sseEmit(['type' => 'chunk', 'content' => $chunk]);
                    }
                );

                if ($approvedPrompt !== null) {
                    $this->sseEmit(['type' => 'approved', 'prompt' => $approvedPrompt]);
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('StudioController: Ollama connection timeout in refineStep', [
                    'error' => $e->getMessage(),
                ]);
                $this->sseEmit([
                    'type'    => 'error',
                    'message' => 'The AI is taking too long to respond — the GPU may be busy. Please wait a moment and try again.',
                ]);
            } catch (\Throwable $e) {
                Log::error('StudioController: RefineStep stream failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->sseEmit([
                    'type'    => 'error',
                    'message' => 'Something went wrong. Please try again.',
                ]);
            }

            $this->sseDone();
        }, 200, $this->sseHeaders());
    }

    /**
     * POST /studio/plan/{plan}/step/{order}/confirm
     * Save the confirmed refined prompt for a step.
     *
     * Called in two distinct contexts:
     *   A) User confirms prompt in refinement phase — sends refined_prompt + optional input_files
     *   B) Auto-chain after approveStep() — sends input_files only (no prompt, step already confirmed)
     *
     * input_files is always MERGED into the existing JSON column so that multiple
     * upstream approvals (each contributing a different media type) accumulate
     * correctly rather than overwriting each other.
     */
    public function confirmStep(Request $request, WorkflowPlan $plan, int $order, RefinementService $refinementService): JsonResponse
    {
        $this->authorisePlan($plan);

        $request->validate([
            'refined_prompt'  => 'nullable|string|min:5',
            'input_file_path' => 'nullable|string',
            // Multi-input map: {"image": "comfyui-inputs/a.png", "audio": "comfyui-inputs/b.mp3"}
            'input_files'     => 'nullable|array',
            'input_files.*'   => 'string',
        ]);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();

        $refinementService->confirmStep(
            $step,
            $request->input('refined_prompt'),
            $request->input('input_files', []),
            $request->input('input_file_path')
        );

        return response()->json(['success' => true, 'step_order' => $order]);
    }
    public function cancelStep(WorkflowPlan $plan, int $order, ApprovalService $approvalService, McpService $mcp): JsonResponse
    {
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();
        $result = $approvalService->cancelStep($step, $mcp);

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }
 
/**
 * Return current ComfyUI queue depth for the execution UI.
 *
 * Called every 10 seconds by fetchQueueStatus() in studio.execution.js
 * to display "N jobs ahead of yours" in the amber queue status bar.
 *
 * Route: GET /studio/queue-status
     */
    public function queueStatus(McpService $mcp): JsonResponse
    {
        return response()->json($mcp->getQueueStatus());
    }

    /**
     * GET /studio/comfy-health
     * Return ComfyUI reachability status for frontend LED indicator.
     */
    public function comfyHealth(McpService $mcp): JsonResponse
    {
        return response()->json($mcp->healthCheck());
    }
    // ─────────────────────────────────────────────────────────────────────────
    // Phase 3 — Execution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /studio/plan/{plan}/review
     * Show review page for steps awaiting approval.
     */
    public function review(WorkflowPlan $plan): \Illuminate\View\View
    {
        $this->authorisePlan($plan);
        $plan->load('steps.workflow');

        return view('studio.review', compact('plan'));
    }

    /**
     * POST /studio/plan/{plan}/dispatch
     * Fire ExecutePlanJob immediately.
     */
    public function dispatch(WorkflowPlan $plan, ExecutionService $executionService): JsonResponse
    {
        $this->authorisePlan($plan);

        $result = $executionService->dispatchPlan($plan);

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * POST /studio/plan/{plan}/queue
     * Add plan to backlog queue instead of dispatching immediately.
     */
    public function queuePlan(WorkflowPlan $plan, ExecutionService $executionService): JsonResponse
    {
        $this->authorisePlan($plan);

        $result = $executionService->queuePlan($plan, session()->getId());

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * POST /studio/queue/run-next
     * Manually trigger the next queued plan.
     */
    public function runNextInQueue(ExecutionService $executionService): JsonResponse
    {
        $result = $executionService->runNextInQueue(session()->getId());

        if (!$result['success']) {
            $statusCode = $result['error'] === 'No queued plans found.' ? 404 : 422;
            return response()->json(['error' => $result['error']], $statusCode);
        }

        return response()->json($result);
    }

    /**
     * GET /studio/jobs
     * Return all session plans for the floating jobs panel.
     */
    public function jobs(ExecutionService $executionService): JsonResponse
    {
        $plans = $executionService->getSessionPlans(session()->getId());
        return response()->json(['plans' => $plans]);
    }

    /**
     * GET /studio/queue-status
     * Summary for the jobs panel badge.
     */


    /**
     * POST /studio/plan/{plan}/mood-board
     * Save mood board selections to a plan.
     */
    public function saveMoodBoard(Request $request, WorkflowPlan $plan): JsonResponse
    {
        $this->authorisePlan($plan);

        $request->validate([
            'mood_board' => 'required|array',
        ]);

        $plan->update(['mood_board' => $request->input('mood_board')]);

        return response()->json(['success' => true]);
    }

    /**
     * GET /studio/plan/{plan}/status
     * Poll plan + step status. Frontend calls this every 4 seconds.
     * We augment statusPayload() with output_path (storage-relative) on each
     * step so the JS can attach a completed step's output as the input_file_path
     * for any dependent step — without requiring a separate lookup.
     */
    public function status(WorkflowPlan $plan, ExecutionService $executionService): JsonResponse
    {
        $this->authorisePlan($plan);
        return response()->json($executionService->getPlanStatus($plan));
    }

    /**
     * POST /studio/plan/{plan}/step/{order}/approve
     * User approves an intermediate result — step advances to completed.
     */
    public function approveStep(WorkflowPlan $plan, int $order, ApprovalService $approvalService): JsonResponse
    {
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();
        $result = $approvalService->approveStep($step);

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * POST /studio/plan/{plan}/step/{order}/reject
     * User rejects an intermediate result — step returns to pending for re-refinement.
     */
    public function rejectStep(Request $request, WorkflowPlan $plan, int $order, ApprovalService $approvalService): JsonResponse
    {
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();
        $result = $approvalService->rejectStep($step, $request->input('rejection_reason'));

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * POST /studio/upload
     * Upload a user-provided input file (image, audio, video).
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'       => 'required|file|max:102400', // 100MB max
            'media_type' => 'required|in:image,video,audio',
        ]);

        $file        = $request->file('file');
        $storagePath = $file->store('comfyui-inputs', 'public');

        return response()->json([
            'storage_path' => $storagePath,
            'url'          => Storage::disk('public')->url($storagePath),
            'media_type'   => $request->input('media_type'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    protected function authorisePlan(WorkflowPlan $plan): void
    {
        if ($plan->session_id !== session()->getId()) {
            abort(403, 'Access denied.');
        }
    }

    protected function sseHeaders(): array
    {
        return [
            'Content-Type'        => 'text/event-stream',
            'Cache-Control'       => 'no-cache',
            'X-Accel-Buffering'   => 'no',
            'Connection'          => 'keep-alive',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ];
    }

    protected function sseEmit(array $data): void
    {
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    protected function sseDone(): void
    {
        echo 'data: ' . json_encode(['type' => 'done']) . "\n\n";
        ob_flush();
        flush();
    }
}