<?php

namespace App\Ai\Agents;

use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * OrchestratorAgent
 *
 * Strategy: separate concerns completely.
 *
 * The LLM handles ONLY natural conversation — understanding intent,
 * asking questions, presenting options warmly.
 *
 * PHP handles ALL plan construction — no JSON from the LLM ever.
 * The LLM signals readiness with a simple keyword: READY:<workflow_id>
 * or AMBIGUOUS:<id1>,<id2>,... which PHP intercepts and acts on.
 *
 * This prevents malformed JSON, undefined fields, and robotic tone.
 */
class OrchestratorAgent
{
    protected string $model;

    public function __construct(string $model = 'mistral:7b')
    {
        $this->model = $model;
    }

    // ─── Capability List ─────────────────────────────────────────────────────

    public function buildCapabilityList(): string
    {
        $workflows = Workflow::active()->orderBy('output_type')->orderBy('name')->get();

        if ($workflows->isEmpty()) {
            return 'No workflows available yet.';
        }

        return $workflows->map(fn (Workflow $w) =>
            "ID:{$w->id} | {$w->name} | produces:{$w->output_type} | needs:" .
            (empty($w->input_types) ? 'text only' : implode('+', $w->input_types)) .
            " | {$w->description}"
        )->implode("\n");
    }

    // ─── System Prompt ───────────────────────────────────────────────────────

    protected function buildSystemPrompt(string $capabilityList): string
    {
        return <<<SYSPROMPT
You are a friendly, creative AI media‑generation assistant. You help users create images, videos, and audio through natural conversation and you can stitch together several generation steps into a single "multimodal job".

AVAILABLE WORKFLOWS:
{$capabilityList}

════════════════════════════════════════
DECISION TREE — evaluate steps in order, stop at the first match.
════════════════════════════════════════

STEP 0 — USER‑PROVIDED INPUT FILES (check first!)
  • If the user's message contains INPUT:media_type:filename tokens (e.g., INPUT:image:photo.jpg),
    those are files the user already has and wants to use as direct input.
  • Find a SINGLE workflow whose input_types EXACTLY match the provided file types.
    Example: user provides INPUT:image:photo.jpg → look for workflow needing "image" input.
  • If a matching workflow exists → READY:<id> (single workflow, use the user's file as input).
  • If NO workflow matches the provided input types → explain the limitation and ask what to do.
  • DO NOT create multi‑step pipelines when the user has already provided their input files.
    The provided file IS the input — just process it directly.

STEP 1 — SINGLE WORKFLOW GENERATION (always try this first)
  • Scan the workflow list for one whose output_type directly satisfies
    what the user wants to produce.
  • If ONE workflow can deliver the final result end‑to‑end on its own → READY:<id>
  • If MULTIPLE workflows could each satisfy it alone → AMBIGUOUS:<id1>,<id2>
  • ONLY proceed to STEP 2 if zero single workflows can satisfy the request alone.
  • A user asking for a video, image, or audio is a generation request — always
    prefer the most direct single workflow over any multi‑step alternative.
    Do NOT use STEP 2 simply because a multi‑step path also exists.

STEP 2 — MULTI‑WORKFLOW ORCHESTRATION (fallback — only if STEP 1 found nothing)
  • No single workflow covers the full request on its own.
  • The user explicitly describes a chain of distinct outputs, e.g.:
    "generate an image then animate it", "make a picture and turn it into a video",
    "create audio for this video clip".
  • Identify each atomic output (image‑gen, video‑from‑image, audio‑from‑text …).
  • For every atomic output:
        – If a matching workflow exists → record its ID.
        – If none exists → flag the missing step (see STEP 3‑B).
  • If all required steps have existing workflows, return them in execution order:
        READY:<id_step1>,<id_step2>,...
  • If at least one required step is missing → fall‑through to STEP 3‑B instead.

STEP 3‑A — BUILD A NEW WORKFLOW (explicit request)
  • User explicitly asks for a new pipeline ("I need a workflow that…",
    "add support for …", "create a pipeline that …").
  • ALL must be true:
        – They use a trigger such as "workflow", "pipeline", "new capability",
          "add support for", "create a workflow".
        – The request is NOT a simple media‑generation request.
  • Respond warmly, then finish with:
        CREATE_WORKFLOW:<free‑text description of the wanted step>

STEP 3‑B — BUILD A NEW WORKFLOW (implicit missing step)
  • While evaluating STEP 2 the agent discovers one (or more) required
    sub‑steps that have no existing workflow.
  • Automatically propose creating the missing workflow(s) — even if the user
    never mentioned the word "workflow".
  • Reply with a brief acknowledgement and then end with:
        CREATE_WORKFLOW:<concise description of the missing capability>

STEP 4 — NOTHING FITS
  • No generation intent detected, or the request is outside the scope.
  • Respond helpfully and ask what the user would like to create.

════════════════════════════════════════
SIGNALS — always the very last line of the assistant's output, nothing after it:
  READY:<workflow_id>                (single or comma‑separated list)
  AMBIGUOUS:<id1>,<id2>
  CREATE_WORKFLOW:<intent>

RULES
  • When user provides INPUT:media_type:filename, use that file directly as the workflow input — do NOT create multi‑step pipelines.
  • Prefer SINGLE workflow generation over multi‑step unless the user explicitly describes chaining outputs.
  • WHEN IN DOUBT → use READY with the *most likely* single workflow
    (never fall back to CREATE_WORKFLOW unless a step is truly missing).
  • "Create an image/video/audio" = GENERATION → READY.
  • "Create a workflow/pipeline" = WORKFLOW BUILDING → CREATE_WORKFLOW.
  • Ask at most ONE clarifying question **before** emitting a signal.
  • Never mention signals, IDs, or any technical jargon to the user.
  • Be warm, concise, enthusiastic, and always keep the focus on the creative
    outcome the user wants.
SYSPROMPT;
    }

    // ─── Main stream method ───────────────────────────────────────────────────

    public function stream(array $messages, callable $onChunk): string
    {
        // Check for server-side disambiguation resolution first
        $shortcutPlan = $this->tryResolvePlanFromSelection($messages);
        if ($shortcutPlan !== null) {
            $signal   = 'READY:' . $shortcutPlan[0]['workflow_id'];
            $fullText = $signal;
            $onChunk($signal);
            return $fullText;
        }

        $capabilityList = $this->buildCapabilityList();
        $systemPrompt   = $this->buildSystemPrompt($capabilityList);
        $prismMessages  = $this->buildPrismMessages($messages);

        // Attempt generation with one automatic retry on degenerate output.
        // Small local models (Gemma, Mistral) occasionally enter token-repetition
        // loops under VRAM pressure, producing garbage like "JsonHelperériale...".
        // We detect this and retry once before falling back to a safe error message.
        $maxAttempts = 2;
        $fullText    = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = prism()
                ->text()
                ->using(Provider::Ollama, $this->model)
                ->withClientOptions(['timeout' => 120])
                ->withSystemPrompt($systemPrompt)
                ->withMessages($prismMessages)
                ->generate();

            $fullText = $response->text;

            if (! $this->isDegenerate($fullText)) {
                break;
            }

            Log::warning('OrchestratorAgent: Degenerate output detected', [
                'attempt' => $attempt,
                'preview' => substr($fullText, 0, 200),
            ]);

            if ($attempt === $maxAttempts) {
                // Both attempts produced garbage — emit a safe fallback and stop.
                // Return a no_signal response so the frontend re-enables the input
                // and the user can try again.
                $fallback = "I'm having a little trouble thinking right now — could you try sending your message again?";
                $words    = explode(' ', $fallback);
                foreach ($words as $i => $word) {
                    $onChunk(($i === 0 ? '' : ' ') . $word);
                }
                Log::error('OrchestratorAgent: Both attempts degenerate — returning fallback');
                return $fallback;
            }

            // Brief pause before retry to let VRAM settle
            usleep(500_000); // 0.5 s
        }

        // Emit word by word — strip signal line from display
        $displayText = $this->stripSignal($fullText);
        $words       = explode(' ', $displayText ?: $fullText);
        foreach ($words as $i => $word) {
            $onChunk(($i === 0 ? '' : ' ') . $word);
        }

        return $fullText;
    }

    // ─── Degenerate output detection ──────────────────────────────────────────

    /**
     * Detect token-repetition loops and other degenerate LLM output.
     *
     * Local models under memory pressure sometimes enter a state where they
     * repeat token fragments endlessly. We check for three signals:
     *
     *   1. Repeated n-gram ratio — split into trigrams, check what fraction
     *      are duplicates. Normal prose has very few repeated trigrams.
     *      Degenerate output has a ratio close to 1.0.
     *
     *   2. Character-class uniformity — degenerate output often consists almost
     *      entirely of a single character class (e.g. all letters with accents,
     *      all "JsonHelper"-style camelCase tokens). We flag responses where
     *      non-ASCII characters make up more than 40% of the content.
     *
     *   3. Single-token dominance — if any single word appears more than 20%
     *      of the time across all words, the output is looping.
     *
     * A response must pass at least one of these checks to be flagged.
     */
    protected function isDegenerate(string $text): bool
    {
        $text = trim($text);

        // Very short responses are always fine
        if (strlen($text) < 80) {
            return false;
        }

        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = count($words);

        if ($wordCount < 10) {
            return false;
        }

        // ── Check 1: repeated trigram ratio ──────────────────────────────────
        // Build word-level trigrams and count duplicates
        $trigrams = [];
        for ($i = 0; $i < $wordCount - 2; $i++) {
            $trigram = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            $trigrams[] = $trigram;
        }
        $uniqueTrigrams = count(array_unique($trigrams));
        $totalTrigrams  = count($trigrams);
        $repetitionRatio = $totalTrigrams > 0
            ? 1.0 - ($uniqueTrigrams / $totalTrigrams)
            : 0.0;

        if ($repetitionRatio > 0.5) {
            Log::debug('OrchestratorAgent: Degenerate — high trigram repetition', [
                'ratio' => round($repetitionRatio, 2),
            ]);
            return true;
        }

        // ── Check 2: non-ASCII character density ─────────────────────────────
        // Legitimate responses are almost entirely ASCII; accented-garbage is not
        $nonAsciiCount = preg_match_all('/[^\x00-\x7F]/', $text);
        $charCount     = strlen($text);
        $nonAsciiRatio = $charCount > 0 ? $nonAsciiCount / $charCount : 0.0;

        if ($nonAsciiRatio > 0.15) {
            Log::debug('OrchestratorAgent: Degenerate — high non-ASCII density', [
                'ratio' => round($nonAsciiRatio, 2),
            ]);
            return true;
        }

        // ── Check 3: single-token dominance ──────────────────────────────────
        $wordFrequency = array_count_values(array_map('strtolower', $words));
        $maxFrequency  = max($wordFrequency);
        $dominanceRatio = $maxFrequency / $wordCount;

        if ($dominanceRatio > 0.2) {
            Log::debug('OrchestratorAgent: Degenerate — single token dominance', [
                'ratio' => round($dominanceRatio, 2),
                'token' => array_search($maxFrequency, $wordFrequency),
            ]);
            return true;
        }

        return false;
    }

    // ─── Signal parsing ───────────────────────────────────────────────────────
    //
    // KEY FIX: All three signal types now scan the ENTIRE response text, not
    // just the last line. Mistral 7b frequently appends a trailing sentence
    // after the signal, which caused last-line checks to miss it entirely.
    // We use the LAST occurrence of each signal so that if the model repeats
    // itself (rare but observed) we always act on the most recent intent.

    public function parseSignal(string $rawResponse, array $conversationMessages = []): array
    {
        Log::debug('OrchestratorAgent: Raw response for signal parsing', [
            'response_length' => strlen($rawResponse),
            'response_preview' => substr($rawResponse, -500),
        ]);

        // ── 1. READY signal (single ID or comma-separated list) ───────────────
        // Use preg_match_all + take the last match so trailing prose is ignored.
        // Handle cases like "READY:24", "READY:24,5", "READY:ID24", "READY:ID 24"
        if (preg_match_all('/READY:.*?(\d+(?:,\d+)*)/i', $rawResponse, $allReadyMatches)) {
            $idString = trim(end($allReadyMatches[1]));
            $ids      = array_values(array_filter(array_map('intval', explode(',', $idString))));

            if (! empty($ids)) {
                $workflows = Workflow::active()->whereIn('id', $ids)->get();
                $foundIds  = $workflows->pluck('id')->toArray();

                $missing = array_diff($ids, $foundIds);
                if (! empty($missing)) {
                    Log::warning('OrchestratorAgent: READY signal has invalid workflow IDs: ' . implode(',', $missing));
                }

                Log::info('OrchestratorAgent: READY signal detected', [
                    'requested_ids' => $ids,
                    'found_ids'     => $foundIds,
                ]);

                if (! empty($foundIds)) {
                    // Re-order to match the LLM's requested order (preserves pipeline sequence)
                    $orderedWorkflows = collect($ids)
                        ->filter(fn ($id) => in_array($id, $foundIds))
                        ->map(fn ($id) => $workflows->firstWhere('id', $id))
                        ->filter()
                        ->values();

                    $plan = $this->buildMultiStepPlan($orderedWorkflows, $conversationMessages);

                    return ['type' => 'ready', 'workflow_ids' => $foundIds, 'plan' => $plan];
                }
            }
        }

        // ── 2. AMBIGUOUS signal ───────────────────────────────────────────────
        // Handle cases like "AMBIGUOUS:ID24,25"
        if (preg_match_all('/AMBIGUOUS:.*?(\d+(?:,\d+)*)/i', $rawResponse, $allAmbiguousMatches)) {
            $idString = trim(end($allAmbiguousMatches[1]));
            $ids      = array_values(array_filter(array_map('intval', explode(',', $idString))));

            if (! empty($ids)) {
                Log::info('OrchestratorAgent: AMBIGUOUS signal detected', ['ids' => $ids]);
                return ['type' => 'ambiguous', 'workflow_ids' => $ids, 'plan' => null];
            }
        }

        // ── 3. CREATE_WORKFLOW signal ─────────────────────────────────────────
        // Scan the full response for any CREATE_WORKFLOW occurrence, take the last.
        if (preg_match_all('/CREATE_WORKFLOW:?([^\n]*)/i', $rawResponse, $allCwMatches)) {
            $intent = trim(end($allCwMatches[1]));

            // Safety net: if the user's message looks like a generation request
            // and an existing workflow matches, override to READY rather than
            // spinning up the workflow builder unnecessarily.
            $userText = '';
            foreach ($conversationMessages as $msg) {
                if ($msg['role'] === 'user') { $userText = strtolower($msg['content']); break; }
            }

            $generationKeywords = [
                'generate', 'create an image', 'create a video', 'create an audio',
                'make an image', 'make a video', 'make an audio', 'draw', 'render',
                'produce', 'i want a picture', 'i want an image', 'i want a video',
                'show me', 'make me a',
            ];

            $looksLikeGeneration = false;
            foreach ($generationKeywords as $kw) {
                if (str_contains($userText, $kw)) { $looksLikeGeneration = true; break; }
            }

            if ($looksLikeGeneration) {
                $workflows       = Workflow::active()->get();
                $outputTypeHints = [];
                if (str_contains($userText, 'image') || str_contains($userText, 'picture') || str_contains($userText, 'photo')) {
                    $outputTypeHints[] = 'image';
                }
                if (str_contains($userText, 'video') || str_contains($userText, 'animation') || str_contains($userText, 'animate')) {
                    $outputTypeHints[] = 'video';
                }
                if (str_contains($userText, 'audio') || str_contains($userText, 'voice') || str_contains($userText, 'sound')) {
                    $outputTypeHints[] = 'audio';
                }

                $matched = null;
                if (! empty($outputTypeHints)) {
                    $matched = $workflows->whereIn('output_type', $outputTypeHints)->first();
                }
                $matched = $matched ?? $workflows->first();

                if ($matched) {
                    Log::info('OrchestratorAgent: CREATE_WORKFLOW safety-net override → READY', [
                        'user_text' => $userText,
                        'workflow'  => $matched->id,
                    ]);
                    $plan = $this->buildPlan($matched, $conversationMessages);
                    return ['type' => 'ready', 'workflow_ids' => [$matched->id], 'plan' => $plan];
                }
            }

            Log::info('OrchestratorAgent: CREATE_WORKFLOW signal detected', ['intent' => $intent]);
            return [
                'type'         => 'create_workflow',
                'intent'       => $intent,
                'workflow_ids' => [],
                'plan'         => null,
            ];
        }

        // ── 4. No signal found ────────────────────────────────────────────────
        // Return a distinct 'no_signal' type so the controller can emit a
        // gentle SSE nudge to the frontend instead of silently doing nothing.
        Log::debug('OrchestratorAgent: No signal found in response');
        return ['type' => 'no_signal', 'workflow_ids' => [], 'plan' => null];
    }

    // ─── Plan building (PHP-only, no LLM JSON) ────────────────────────────────

    /**
     * Build a clean plan array from a workflow + conversation context.
     */
    public function buildPlan(Workflow $workflow, array $conversationMessages = []): array
    {
        // Use the LAST user message — this is the current request, not stale history
        $userIntent = $this->extractLastUserIntent($conversationMessages);

        return [[
            'step_order'    => 0,
            'workflow_id'   => $workflow->id,
            'workflow_type' => $workflow->output_type,
            'purpose'       => $userIntent ?: "Generate {$workflow->output_type} using {$workflow->name}",
            'prompt_hint'   => $userIntent,
            'depends_on'    => [],
        ]];
    }

    /**
     * Build a multi-step plan from multiple workflows.
     * Each step depends on the previous step's output.
     */
    public function buildMultiStepPlan(\Illuminate\Support\Collection $workflows, array $conversationMessages = []): array
    {
        // Use the LAST user message — this is the current request, not stale history
        $userIntent = $this->extractLastUserIntent($conversationMessages);

        $plan  = [];
        $total = count($workflows);

        foreach ($workflows as $index => $workflow) {
            // Each step depends on the immediately preceding step's output
            $dependsOn = $index > 0 ? [$index - 1] : [];

            // Per-step purpose: step 0 gets the full user intent (generating from
            // the prompt), subsequent steps describe their transformation role.
            $purpose = $this->buildMultiStepPurpose($workflow, $userIntent, $index, $total);

            // Per-step prompt_hint: only step 0 pre-fills with the user intent.
            // Later steps start blank so WorkflowOptimizerAgent can ask the user
            // what they want for that specific output (e.g. motion style for video)
            // rather than blindly reusing the previous step's image prompt.
            $promptHint = $index === 0 ? $userIntent : '';

            $plan[] = [
                'step_order'    => $index,
                'workflow_id'   => $workflow->id,
                'workflow_type' => $workflow->output_type,
                'purpose'       => $purpose,
                'prompt_hint'   => $promptHint,
                'depends_on'    => $dependsOn,
            ];
        }

        return $plan;
    }

    protected function buildMultiStepPurpose(Workflow $workflow, string $userIntent, int $stepIndex, int $totalSteps): string
    {
        $outputLabel = match ($workflow->output_type) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default  => $workflow->output_type,
        };

        if ($stepIndex === 0) {
            // First step: show what is being generated and from what prompt
            $intent = strlen($userIntent) > 80 ? substr($userIntent, 0, 77) . '...' : $userIntent;
            return ! empty($intent)
                ? "Generate {$outputLabel}: {$intent}"
                : "Generate {$outputLabel} ({$workflow->name})";
        }

        // Subsequent steps: describe the transformation clearly
        $actionLabel = match ($workflow->output_type) {
            'video' => 'Animate',
            'audio' => 'Generate audio from',
            'image' => 'Transform',
            default  => 'Process',
        };

        $prevOutputLabel = match ($workflow->output_type) {
            'video' => 'image',
            'audio' => 'video',
            default  => 'output',
        };

        return "{$actionLabel} {$prevOutputLabel} → {$outputLabel} ({$workflow->name})";
    }

    /**
     * Extract the most recent user message from conversation history,
     * stripping style tags. Used by buildPlan() and buildMultiStepPlan()
     * to get the current request rather than stale history.
     */
    protected function extractLastUserIntent(array $conversationMessages): string
    {
        foreach (array_reverse($conversationMessages) as $msg) {
            if ($msg['role'] === 'user') {
                $content = preg_replace('/\[Style:[^\]]*\]/', '', $msg['content']);
                return trim($content);
            }
        }
        return '';
    }

    protected function buildPurpose(Workflow $workflow, string $userIntent): string
    {
        if (! empty($userIntent)) {
            $intent = strlen($userIntent) > 60
                ? substr($userIntent, 0, 57) . '...'
                : $userIntent;
            return $intent;
        }

        return match ($workflow->output_type) {
            'image' => 'Generate image from your description',
            'video' => 'Generate video from your description',
            'audio' => 'Generate audio from your description',
            default => "Generate {$workflow->output_type} using {$workflow->name}",
        };
    }

    public function parsePlan(string $rawResponse): ?array
    {
        if (! preg_match('/\[PLAN\](.*?)(?:\[\/PLAN\]|$)/s', $rawResponse, $matches)) {
            return null;
        }

        $jsonString = trim($matches[1]);
        $jsonString = preg_replace('/,\s*([\]\}])/', '$1', $jsonString);
        $jsonString = preg_replace('/(["}\]0-9])\s*\n(\s*")/m', "$1,\n$2", $jsonString);

        $trimmed = ltrim($jsonString);
        if (str_starts_with($trimmed, '{')) {
            $jsonString = '[' . $jsonString . ']';
        }

        $plan = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (! is_array($plan)) {
            return null;
        }

        foreach ($plan as &$step) {
            $step['workflow_id'] = (int) ($step['workflow_id'] ?? 0);
            $step['depends_on']  = $step['depends_on'] ?? [];
            if (empty($step['prompt_hint']) && ! empty($step['purpose'])) {
                $step['prompt_hint'] = $step['purpose'];
            }
        }

        return $plan;
    }

    public function tryResolvePlanFromSelection(array $messages): ?array
    {
        if (count($messages) < 3) {
            return null;
        }

        $lastAssistantMsg = '';
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'assistant') {
                $lastAssistantMsg = strtolower($msg['content']);
                break;
            }
        }

        $isDisambiguation = str_contains($lastAssistantMsg, 'ambiguous:')
            || str_contains($lastAssistantMsg, 'which would you')
            || str_contains($lastAssistantMsg, 'which workflow')
            || str_contains($lastAssistantMsg, 'a few ways')
            || str_contains($lastAssistantMsg, 'few different');

        if (! $isDisambiguation) {
            return null;
        }

        $lastUserMsg = '';
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUserMsg = strtolower($msg['content']);
                break;
            }
        }

        if (empty($lastUserMsg)) {
            return null;
        }

        if (preg_match('/(?:id\s*:?\s*|#|\()(\d+)\)?/i', $lastUserMsg, $m)) {
            $workflow = Workflow::active()->find((int) $m[1]);
            if ($workflow) {
                return $this->buildPlan($workflow, $messages);
            }
        }

        $activeWorkflows = Workflow::active()->get();
        foreach ($activeWorkflows as $workflow) {
            $nameLower  = strtolower($workflow->name);
            $words      = array_filter(explode(' ', $nameLower), fn ($w) => strlen($w) > 3);
            $matchCount = 0;
            foreach ($words as $word) {
                if (str_contains($lastUserMsg, $word)) {
                    $matchCount++;
                }
            }
            if ($matchCount >= 2) {
                return $this->buildPlan($workflow, $messages);
            }

            foreach ($this->extractAliases($workflow->name) as $alias) {
                if (str_contains($lastUserMsg, strtolower($alias))) {
                    return $this->buildPlan($workflow, $messages);
                }
            }
        }

        return null;
    }

    protected function stripSignal(string $text): string
    {
        // Remove signal lines wherever they appear — not just at the end.
        // Mistral 7b sometimes emits the signal mid-response or with trailing prose.
        $text = preg_replace('/^READY:[\d,\s]+.*$/im', '', $text);
        $text = preg_replace('/^AMBIGUOUS:[\d,\s]+.*$/im', '', $text);
        $text = preg_replace('/^CREATE_WORKFLOW:.*$/im', '', $text);

        // Strip decision-tree reasoning that leaks into the display text.
        // The model sometimes echoes numbered steps or STEP headers verbatim
        // from the system prompt structure.
        $text = preg_replace('/^STEP\s+\d+[^:]*:.*$/im', '', $text);
        $text = preg_replace('/^(?:\d+\.\s+)?(?:SINGLE WORKFLOW|MULTI.WORKFLOW|BUILD A NEW WORKFLOW|NOTHING FITS)\b.*$/im', '', $text);

        // Strip markdown bold/heading artefacts left by system-prompt echoing
        $text = preg_replace('/\*{2,}[^*\n]+\*{2,}/', '', $text);
        $text = preg_replace('/^#{1,3}\s+.+$/im', '', $text);

        // Collapse multiple blank lines left by the removals above
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    protected function buildPlanFromWorkflow(Workflow $workflow): array
    {
        return $this->buildPlan($workflow);
    }

    protected function extractAliases(string $name): array
    {
        $aliases = [];
        if (preg_match_all('/[A-Z][A-Z0-9]+/', $name, $m)) {
            $aliases = array_merge($aliases, $m[0]);
        }
        if (preg_match('/^([A-Za-z0-9]+-[A-Za-z0-9]+)/', $name, $m)) {
            $aliases[] = $m[1];
        }
        foreach (explode(' ', $name) as $word) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $word);
            if (strlen($clean) <= 5 && strlen($clean) >= 2) {
                $aliases[] = $clean;
            }
        }
        return array_unique($aliases);
    }

    protected function buildPrismMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            return match ($msg['role']) {
                'assistant' => new AssistantMessage($msg['content']),
                default     => new UserMessage($msg['content']),
            };
        }, $messages);
    }
}