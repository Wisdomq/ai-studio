<?php

namespace App\Http\Controllers;

use App\Ai\Agents\OrchestratorAgent;
use App\Ai\Agents\WorkflowOptimizerAgent;
use App\Jobs\ExecutePlanJob;
use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Models\Workflow;
use App\Ai\Skills\WorkflowBuilderSkill;
use App\Services\WorkflowBuilderService;
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

    public function planner(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'messages' => 'required|array',
            'messages.*.role'    => 'required|in:user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        $messages = $request->input('messages');
        $agent    = new OrchestratorAgent($this->model);

        return response()->stream(function () use ($messages, $agent) {

            try {
                $fullText = $agent->stream($messages, function (string $chunk) {
                    $this->sseEmit(['type' => 'chunk', 'content' => $chunk]);
                });

                $signal = $agent->parseSignal($fullText, $messages);

                if ($signal['type'] === 'ready' && $signal['plan'] !== null) {
                    $this->sseEmit(['type' => 'plan', 'plan' => $signal['plan']]);

                } elseif ($signal['type'] === 'ambiguous') {
                    $workflows = \App\Models\Workflow::active()
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

                    $this->sseEmit([
                        'type'      => 'ambiguous',
                        'workflows' => $workflows,
                    ]);

                } elseif ($signal['type'] === 'create_workflow') {
                    // Extract user intent from conversation
                    $userIntent = '';
                    foreach ($messages as $msg) {
                        if ($msg['role'] === 'user') {
                            $userIntent = $msg['content'];
                            break;
                        }
                    }
                    if (empty($userIntent) && ! empty($signal['intent'])) {
                        $userIntent = $signal['intent'];
                    }

                    // Do NOT save yet — emit a proposal so the user can confirm or cancel
                    // The actual save happens in confirmWorkflow() after user clicks Accept
                    $this->sseEmit([
                        'type'    => 'workflow_proposed',
                        'intent'  => $userIntent,
                    ]);

                } else {
                    $plan = $agent->parsePlan($fullText);
                    if ($plan !== null) {
                        $this->sseEmit(['type' => 'plan', 'plan' => $plan]);
                    }
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
    public function approvePlan(Request $request): JsonResponse
    {
        $request->validate([
            'user_intent' => 'required|string',
            'steps'       => 'required|array|min:1',
            'steps.*.step_order'    => 'required|integer|min:0',
            'steps.*.workflow_id'   => 'required|integer|exists:workflows,id',
            'steps.*.workflow_type' => 'required|string',
            'steps.*.purpose'       => 'required|string',
            'steps.*.prompt_hint'   => 'nullable|string',
            'steps.*.depends_on'    => 'nullable|array',
            'files'        => 'nullable|array',
            'files.*.storage_path' => 'required|string',
            'files.*.media_type'   => 'required|string|in:image,video,audio',
        ]);

        $sessionId = session()->getId();
        $inputFiles = $request->input('files', []);

        $plan = WorkflowPlan::create([
            'session_id'   => $sessionId,
            'user_intent' => $request->input('user_intent'),
            'plan_steps'   => $request->input('steps'),
            'status'       => 'pending',
            'input_files'  => $inputFiles,
        ]);

        foreach ($request->input('steps') as $stepData) {
            $workflow = Workflow::findOrFail((int) $stepData['workflow_id']);

            WorkflowPlanStep::create([
                'plan_id'        => $plan->id,
                'workflow_id'    => $workflow->id,
                'step_order'     => (int) $stepData['step_order'],
                'workflow_type'  => $stepData['workflow_type'],
                'purpose'        => $stepData['purpose'],
                'depends_on'     => $stepData['depends_on'] ?? [],
                'status'         => 'pending',
            ]);
        }

        session(['workflow_session_id' => $sessionId]);

        return response()->json([
            'plan_id'    => $plan->id,
            'input_files' => $inputFiles,
            'steps'      => $plan->steps()->orderBy('step_order')->with('workflow')->get()
                ->map(fn($s) => [
                    'id'            => $s->id,
                    'step_order'    => $s->step_order,
                    'purpose'       => $s->purpose,
                    'workflow_type' => $s->workflow_type,
                    'input_types'  => $s->workflow->input_types ?? [],
                ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 2 — Prompt Refinement (SSE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /studio/plan/refine-step
     * SSE stream: WorkflowOptimizerAgent for one plan step.
     */
    public function refineStep(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'plan_id'     => 'required|integer|exists:workflow_plans,id',
            'step_order'  => 'required|integer|min:0',
            'messages'    => 'required|array',
            'turn_number' => 'required|integer|min:1|max:3',
        ]);

        $plan = WorkflowPlan::findOrFail($request->input('plan_id'));
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $request->input('step_order'))->with('workflow')->firstOrFail();

        $messages   = $request->input('messages');
        $turnNumber = $request->input('turn_number');
        $outputType = $step->workflow->output_type;
        $agent      = new WorkflowOptimizerAgent($this->model);

        return response()->stream(function () use ($messages, $outputType, $turnNumber, $agent) {
            try {
                $fullText = $agent->stream($messages, $outputType, $turnNumber, function (string $chunk) {
                    $this->sseEmit(['type' => 'chunk', 'content' => $chunk]);
                });

                $approvedPrompt = $agent->parseApprovedPrompt($fullText);

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
     */
    public function confirmStep(Request $request, WorkflowPlan $plan, int $order): JsonResponse
    {
        $this->authorisePlan($plan);

        $request->validate([
            'refined_prompt'  => 'required|string|min:5',
            'input_file_path' => 'nullable|string', // storage-relative path from prior /studio/upload call
        ]);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();

        $updateData = ['refined_prompt' => $request->input('refined_prompt')];

        // Persist user-uploaded input file path if provided
        if ($request->filled('input_file_path')) {
            $updateData['input_file_path'] = $request->input('input_file_path');
        }

        $step->update($updateData);

        return response()->json(['success' => true, 'step_order' => $order]);
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
    public function dispatch(WorkflowPlan $plan): JsonResponse
    {
        $this->authorisePlan($plan);

        $unconfirmed = $plan->steps()->whereNull('refined_prompt')->count();
        if ($unconfirmed > 0) {
            return response()->json(['error' => "{$unconfirmed} step(s) need confirmed prompts."], 422);
        }

        // Don't block re-dispatch after rejection — only block if truly already running
        if ($plan->isRunning()) {
            return response()->json(['error' => 'Plan is already running.'], 422);
        }

        // Reset plan to pending so job can mark it running cleanly
        $plan->update(['status' => WorkflowPlan::STATUS_PENDING]);

        ExecutePlanJob::dispatch($plan->id);

        Log::info("StudioController: Dispatched plan #{$plan->id}");

        return response()->json(['success' => true, 'plan_id' => $plan->id]);
    }

    /**
     * POST /studio/plan/{plan}/queue
     * Add plan to backlog queue instead of dispatching immediately.
     */
    public function queuePlan(WorkflowPlan $plan): JsonResponse
    {
        $this->authorisePlan($plan);

        $unconfirmed = $plan->steps()->whereNull('refined_prompt')->count();
        if ($unconfirmed > 0) {
            return response()->json(['error' => "{$unconfirmed} step(s) need confirmed prompts."], 422);
        }

        // Auto-dispatch immediately if nothing running
        if (! WorkflowPlan::hasRunning(session()->getId())) {
            $plan->update(['status' => WorkflowPlan::STATUS_PENDING]);
            ExecutePlanJob::dispatch($plan->id);
            return response()->json(['success' => true, 'plan_id' => $plan->id, 'auto_dispatched' => true]);
        }

        // Otherwise add to queue backlog
        $plan->addToQueue();

        return response()->json([
            'success'         => true,
            'plan_id'         => $plan->id,
            'queue_position'  => $plan->fresh()->queue_position,
            'auto_dispatched' => false,
        ]);
    }

    /**
     * POST /studio/queue/run-next
     * Manually trigger the next queued plan.
     */
    public function runNextInQueue(): JsonResponse
    {
        $sessionId = session()->getId();

        if (WorkflowPlan::hasRunning($sessionId)) {
            return response()->json(['error' => 'A job is already running.'], 422);
        }

        $next = WorkflowPlan::nextInQueue($sessionId);
        if (! $next) {
            return response()->json(['error' => 'No queued plans found.'], 404);
        }

        $next->update(['status' => WorkflowPlan::STATUS_PENDING]);
        ExecutePlanJob::dispatch($next->id);

        return response()->json(['success' => true, 'plan_id' => $next->id]);
    }

    /**
     * GET /studio/jobs
     * Return all session plans for the floating jobs panel.
     */
    public function jobs(): JsonResponse
    {
        $sessionId = session()->getId();

        $plans = WorkflowPlan::where('session_id', $sessionId)
            ->with('steps')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn(WorkflowPlan $plan) => $plan->statusPayload());

        return response()->json(['plans' => $plans]);
    }

    /**
     * GET /studio/queue-status
     * Summary for the jobs panel badge.
     */
    public function queueStatus(): JsonResponse
    {
        $sessionId = session()->getId();

        $running  = WorkflowPlan::where('session_id', $sessionId)->where('status', 'running')->count();
        $queued   = WorkflowPlan::where('session_id', $sessionId)->where('status', 'queued')->count();
        $pending  = WorkflowPlan::where('session_id', $sessionId)->where('status', 'pending')->count();
        $awaiting = WorkflowPlanStep::whereHas('plan', fn($q) => $q->where('session_id', $sessionId))
            ->where('status', 'awaiting_approval')->count();

        return response()->json([
            'running'   => $running,
            'queued'    => $queued,
            'pending'   => $pending,
            'awaiting'  => $awaiting,
            'total_active' => $running + $queued + $awaiting,
        ]);
    }

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
    public function status(WorkflowPlan $plan): JsonResponse
    {
        $this->authorisePlan($plan);

        $payload = $plan->statusPayload();

        // Inject output_path alongside output_url on each step entry.
        // statusPayload() already loads steps; we just re-key the raw DB value.
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

        return response()->json($payload);
    }

    /**
     * POST /studio/plan/{plan}/step/{order}/approve
     * User approves an intermediate result — step advances to completed.
     */
    public function approveStep(WorkflowPlan $plan, int $order): JsonResponse
    {
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();

        if (! $step->isAwaitingApproval()) {
            return response()->json(['error' => 'Step is not awaiting approval.'], 422);
        }

        $step->markCompleted();

        Log::info("Studio: Plan #{$plan->id} step {$order} approved by user.");

        return response()->json(['success' => true, 'step_order' => $order, 'status' => 'completed']);
    }

    /**
     * POST /studio/plan/{plan}/step/{order}/reject
     * User rejects an intermediate result — step returns to pending for re-refinement.
     */
    public function rejectStep(WorkflowPlan $plan, int $order): JsonResponse
    {
        $this->authorisePlan($plan);

        $step = $plan->steps()->where('step_order', $order)->firstOrFail();

        if (! $step->isAwaitingApproval()) {
            return response()->json(['error' => 'Step is not awaiting approval.'], 422);
        }

        $step->resetForRefinement();

        Log::info("Studio: Plan #{$plan->id} step {$order} rejected by user — reset for re-refinement.");

        return response()->json(['success' => true, 'step_order' => $order, 'status' => 'pending']);
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
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
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