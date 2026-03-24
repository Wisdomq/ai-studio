<?php

namespace App\Http\Controllers;

use App\Ai\Agents\OrchestratorAgent;
use App\Ai\Agents\WorkflowOptimizerAgent;
use App\Jobs\ExecutePlanJob;
use App\Models\WorkflowPlan;
use App\Models\WorkflowPlanStep;
use App\Models\Workflow;
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
        return view('studio.index');
    }

    public function result(WorkflowPlan $plan): \Illuminate\View\View
    {
        $this->authorisePlan($plan);
        $plan->load('steps.workflow');

        return view('studio.result', compact('plan'));
    }

    public function generations(): \Illuminate\View\View
    {
        $sessionId = session()->getId();
        $plans     = WorkflowPlan::where('session_id', $sessionId)
            ->where('status', 'completed')
            ->with('steps')
            ->latest()
            ->paginate(12);

        return view('studio.generations', compact('plans'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 1 — Planning (SSE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /studio/planner
     * SSE stream: OrchestratorAgent conversation turn.
     */
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

                // Parse signal from response (READY:<id> or AMBIGUOUS:<id1>,<id2>)
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
                } else {
                    $plan = $agent->parsePlan($fullText);
                    if ($plan !== null) {
                        $this->sseEmit(['type' => 'plan', 'plan' => $plan]);
                    }
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Ollama timed out — most likely VRAM is saturated by ComfyUI
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
        ]);

        $sessionId = session()->getId();

        $plan = WorkflowPlan::create([
            'session_id'  => $sessionId,
            'user_intent' => $request->input('user_intent'),
            'plan_steps'  => $request->input('steps'),
            'status'      => 'pending',
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
            'plan_id' => $plan->id,
            'steps'   => $plan->steps()->orderBy('step_order')->with('workflow')->get()
                ->map(fn($s) => [
                    'id'           => $s->id,
                    'step_order'   => $s->step_order,
                    'purpose'      => $s->purpose,
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
     */
    public function status(WorkflowPlan $plan): JsonResponse
    {
        $this->authorisePlan($plan);

        return response()->json($plan->statusPayload());
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