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
You are a warm, creative assistant that helps users generate images, videos, and audio.

CRITICAL RULE: Every reply MUST end with a signal on its own final line. Always. No exceptions.

WORKFLOWS:
{$capabilityList}

HOW TO DECIDE:
- User sends INPUT:type:filename → find ONE workflow needing that input type → READY:<id>
- One workflow can do the whole job → READY:<id>
- Multiple workflows could each do it alone → AMBIGUOUS:<id1>,<id2>
- User wants a chain ("generate X then turn it into Y") → READY:<id1>,<id2>
- Required step has no matching workflow → CREATE_WORKFLOW:<what is missing>
- Pure conversation, no creation request → reply warmly, no signal yet

SIGNALS (last line of every reply — pick exactly one):
READY:<id>
READY:<id1>,<id2>
AMBIGUOUS:<id1>,<id2>
CREATE_WORKFLOW:<description>

For READY with multiple IDs, add one INTENT line per step right before the signal:
INTENT:<what step 1 should do>
INTENT:<what step 2 should do>
READY:<id1>,<id2>

EXTRA RULES:
- When in doubt, use READY with the most likely workflow. Do not overthink.
- Never use READY:<id1>,<id2> if one workflow can do the full job alone.
- Never repeat the same ID twice in a row (READY:1,1,2 is wrong, use READY:1,2).
- Never mention workflow IDs, signal names, or technical terms to the user.
- Keep replies short and friendly.
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

        // ── Phase 1: Collect ALL IDs from ALL READY: patterns ──────────────────
        $allReadyIds = [];

        if (preg_match_all('/READY:\s*(?:\d+(?:\s*,\s*\d+)*)/i', $rawResponse, $allReadyMatches)) {
            foreach ($allReadyMatches[0] as $raw) {
                $clean = preg_replace('/[^0-9,\s]/', '', $raw);
                $clean = preg_replace('/\s*,\s*/', ',', trim($clean));
                $chunk = array_values(array_filter(array_map('intval', explode(',', $clean))));
                $allReadyIds = array_merge($allReadyIds, $chunk);
            }
        }

        // ── Phase 2: If READY signals yielded < 2 IDs, also scan prose ─────────
        if (count($allReadyIds) < 2) {
            $proseIds = $this->extractIdsFromProse($rawResponse);
            $allReadyIds = array_merge($allReadyIds, $proseIds);
        }

        // ── Phase 3: Remove consecutive duplicates ───────────────────────────
        // Consecutive duplicates (e.g. READY:1,1,2) are almost always LLM
        // copy-paste errors. Collapse them while preserving non-consecutive
        // repeats which may represent intentional "generate multiple outputs".
        $ids = array_values(array_filter($allReadyIds, fn ($id) => $id > 0));
        $originalCount = count($ids);
        $ids = $this->removeConsecutiveDuplicates($ids);

        Log::info('OrchestratorAgent: Collected IDs from LLM response', [
            'ids' => $ids,
            'original_ids' => $allReadyIds,
            'deduplicated' => $originalCount !== count($ids),
            'original_count' => $originalCount,
            'deduped_count' => count($ids),
        ]);

        // ── Phase 3b: Extract step-specific intents from INTENT: lines ───────
        $stepIntents = [];
        if (preg_match_all('/^INTENT:\s*(.+)$/mi', $rawResponse, $intentMatches)) {
            foreach ($intentMatches[1] as $intent) {
                $intent = trim($intent);
                if (! empty($intent)) {
                    $stepIntents[] = $intent;
                }
            }
        }

        Log::info('OrchestratorAgent: Extracted step intents', [
            'step_intents' => $stepIntents,
        ]);

        // ── Phase 3c: Parse explicit dependency graph from DEPS: line (bonus) ─
        // Not required — emitted only by capable models or complex pipelines.
        // Format: DEPS:<step_idx>:<type>=<src_idx>[,<type>=<src_idx>]|...
        // Example: DEPS:1:image=0|2:image=0,video=1
        $explicitDeps = [];
        if (preg_match('/^DEPS:\s*(.+)$/mi', $rawResponse, $depsMatch)) {
            foreach (explode('|', trim($depsMatch[1])) as $entry) {
                if (! preg_match('/^(\d+):(.+)$/', trim($entry), $em)) {
                    continue;
                }
                $depMap = [];
                foreach (explode(',', $em[2]) as $pair) {
                    if (preg_match('/^(\w+)=(\d+)$/', trim($pair), $pm)) {
                        $depMap[$pm[1]] = (int) $pm[2];
                    }
                }
                if (! empty($depMap)) {
                    $explicitDeps[(int) $em[1]] = $depMap;
                }
            }
            Log::info('OrchestratorAgent: Parsed explicit dependency graph', [
                'deps' => $explicitDeps,
            ]);
        }

        // ── Phase 4: Validate against DB ─────────────────────────────────────
        if (! empty($ids)) {
            // Get unique IDs for the DB query
            $uniqueIds = array_values(array_unique($ids));
            $workflows = Workflow::active()->whereIn('id', $uniqueIds)->get();
            $foundIds  = $workflows->pluck('id')->toArray();

            $missing = array_diff($uniqueIds, $foundIds);
            if (! empty($missing)) {
                Log::warning('OrchestratorAgent: Invalid workflow IDs skipped: ' . implode(',', $missing));
            }

            if (! empty($foundIds)) {
                // Re-order to match the LLM's order (preserves pipeline sequence)
                // Filter to only valid IDs, but preserve ALL occurrences (including duplicates)
                $orderedWorkflows = collect($ids)
                    ->filter(fn ($id) => in_array($id, $foundIds))
                    ->map(fn ($id) => $workflows->firstWhere('id', $id))
                    ->filter()
                    ->values();

                $plan = count($orderedWorkflows) > 1
                    ? $this->buildMultiStepPlan($orderedWorkflows, $conversationMessages, $stepIntents, $explicitDeps)
                    : $this->buildPlan($orderedWorkflows->first(), $conversationMessages);

                Log::info('OrchestratorAgent: READY signal detected', [
                    'requested_ids' => $ids,
                    'found_ids'     => $foundIds,
                ]);

                return ['type' => 'ready', 'workflow_ids' => $foundIds, 'plan' => $plan];
            }
        }

        // ── 2. AMBIGUOUS signal ───────────────────────────────────────────────
        if (preg_match_all('/AMBIGUOUS:\s*(?:\d+(?:\s*,\s*\d+)*)/i', $rawResponse, $allAmbiguousMatches)) {
            $raw = end($allAmbiguousMatches[0]);
            $idString = preg_replace('/[^0-9,\s]/', '', $raw);
            $idString = preg_replace('/\s*,\s*/', ',', trim($idString));
            $ids      = array_values(array_filter(array_map('intval', explode(',', $idString))));

            if (! empty($ids)) {
                Log::info('OrchestratorAgent: AMBIGUOUS signal detected', ['ids' => $ids]);
                return ['type' => 'ambiguous', 'workflow_ids' => $ids, 'plan' => null];
            }
        }

        // ── 3. CREATE_WORKFLOW signal ─────────────────────────────────────────
        if (preg_match_all('/CREATE_WORKFLOW:?([^\n]*)/i', $rawResponse, $allCwMatches)) {
            $intent = trim(end($allCwMatches[1]));

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
        Log::debug('OrchestratorAgent: No signal found in response');
        return ['type' => 'no_signal', 'workflow_ids' => [], 'plan' => null];
    }

    /**
     * Scan response prose for bare workflow ID mentions that weren't caught by
     * READY: signals. Matches patterns like:
     *   "workflow 37", "workflow 38", "workflows 37 and 38"
     *   "ID 37", "step 37", "use 37", "numbers 37, 38"
     *
     * Only returns IDs that appear to be part of a plan (near action words).
     */
    protected function extractIdsFromProse(string $response): array
    {
        $ids = [];

        // Match bare workflow IDs in context: workflow N, workflows N, ID N, step N, use N, numbers N
        $pattern = '/(?:(?:workflows?|ids?|steps?|use|numbers?)\s*:?\s*)(\d+)(?:\s*(?:,|and|\/)\s*(\d+))*/i';

        if (preg_match_all($pattern, $response, $matches)) {
            foreach ($matches[0] as $index => $match) {
                $firstId = (int) $matches[1][$index];
                if ($firstId > 0) {
                    $ids[] = $firstId;
                }
                // Capture comma/and-separated IDs on the same line
                if (! empty($matches[2][$index])) {
                    $extra = (int) $matches[2][$index];
                    if ($extra > 0) {
                        $ids[] = $extra;
                    }
                }
            }
        }

        // Also scan sentence-by-sentence for standalone numbers that look like workflow IDs
        // (single digits 1-9 or any number near "workflow/step" keywords)
        $sentences = preg_split('/[.!?\n]+/', $response);
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (preg_match_all('/\b(\d{1,4})\b/', $sentence, $numMatches)) {
                foreach ($numMatches[1] as $num) {
                    $n = (int) $num;
                    // Skip single digits (likely list items or counts, not workflow IDs)
                    // Skip if the sentence doesn't mention a workflow-related keyword
                    $hasKeyword = preg_match('/\b(?:workflow|step|use|run|execute|generate|animate|create|process)\b/i', $sentence);
                    if ($n >= 10 && $hasKeyword) {
                        $ids[] = $n;
                    }
                }
            }
        }

        return $ids;
    }

    // ─── Plan building (PHP-only, no LLM JSON) ────────────────────────────────

    /**
     * Build a clean plan array from a workflow + conversation context.
     */
    public function buildPlan(Workflow $workflow, array $conversationMessages = []): array
    {
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
     *
     * Produces a DAG-aware plan by:
     *   1. Assigning keyed depends_on maps (latest-match-wins) to every step.
     *   2. Computing an execution_layer for every step so the executor can run
     *      independent steps as a group before moving to dependent steps.
     *
     * Execution layers work like topological levels in the DAG:
     *   - Steps with no dependencies → Layer 0 (run first, conceptually parallel)
     *   - Steps depending only on Layer 0 → Layer 1
     *   - Steps depending on Layer N or lower → Layer N+1
     *
     * Example — faceswap pipeline:
     *   Step 0: text→image (scene)          → Layer 0  depends_on: {}
     *   Step 1: text→image (face portrait)  → Layer 0  depends_on: {}
     *   Step 2: image→video                 → Layer 1  depends_on: {image: 0}
     *   Step 3: faceswap (needs image+video) → Layer 2  depends_on: {image: 1, video: 2}
     *
     * The executor runs all Layer 0 steps first, then Layer 1, then Layer 2.
     * This guarantees that when faceswap runs, both its video AND its face image
     * are already produced — regardless of how the LLM ordered the READY signal.
     *
     * When the LLM emits a DEPS: line, those explicit deps override the
     * algorithmic fallback for the steps they cover.
     *
     * @param \Illuminate\Support\Collection $workflows           Ordered collection of workflows
     * @param array                          $conversationMessages Conversation history
     * @param array                          $stepIntents          Step intents from INTENT: lines
     * @param array                          $explicitDeps         Dep map from DEPS: line — [stepIdx => [type => srcIdx]]
     */
    public function buildMultiStepPlan(
        \Illuminate\Support\Collection $workflows,
        array $conversationMessages = [],
        array $stepIntents = [],
        array $explicitDeps = []
    ): array {
        $userIntent = $this->extractLastUserIntent($conversationMessages);

        $plan  = [];
        $total = count($workflows);

        // ── Pass 1: assign depends_on for every step ──────────────────────────
        foreach ($workflows as $index => $workflow) {
            if ($index === 0) {
                $dependsOn = [];
            } elseif (isset($explicitDeps[$index])) {
                $dependsOn = $explicitDeps[$index];
            } else {
                $dependsOn = $this->buildKeyedDependencies($workflows, $index);
            }

            $purpose    = $this->buildMultiStepPurpose($workflow, $userIntent, $index, $total);
            $promptHint = $stepIntents[$index] ?? ($index === 0 ? $userIntent : '');

            $plan[] = [
                'step_order'    => $index,
                'workflow_id'   => $workflow->id,
                'workflow_type' => $workflow->output_type,
                'purpose'       => $purpose,
                'prompt_hint'   => $promptHint,
                'depends_on'    => $dependsOn,
                'execution_layer' => 0, // filled in Pass 2
            ];
        }

        // ── Pass 2: compute execution_layer for every step ────────────────────
        // Layer = max(layer of each dependency) + 1, or 0 if no dependencies.
        // Must be computed in step_order sequence so earlier layers are known
        // before later ones reference them.
        $layerByOrder = [];
        foreach ($plan as &$step) {
            $layer = $this->computeExecutionLayer($step, $layerByOrder);
            $step['execution_layer'] = $layer;
            $layerByOrder[$step['step_order']] = $layer;
        }
        unset($step);

        Log::info('OrchestratorAgent: Execution layers assigned', [
            'layers' => $layerByOrder,
        ]);

        return $plan;
    }

    /**
     * Compute the execution layer for a single step given already-computed
     * layers for all prior steps.
     *
     * @param array $step          The step array (must include depends_on)
     * @param array $layerByOrder  Map of step_order => execution_layer for all prior steps
     * @return int
     */
    protected function computeExecutionLayer(array $step, array $layerByOrder): int
    {
        $deps = $step['depends_on'] ?? [];

        if (empty($deps)) {
            return 0;
        }

        $maxDepLayer = 0;
        foreach ($deps as $key => $value) {
            // keyed format: $key = type string, $value = step_order
            // flat format:  $key = array index,  $value = step_order
            $depStepOrder = is_string($key) ? (int) $value : (int) $value;
            $depLayer     = $layerByOrder[$depStepOrder] ?? 0;
            $maxDepLayer  = max($maxDepLayer, $depLayer);
        }

        return $maxDepLayer + 1;
    }

    /**
     * Build a keyed dependency map for a step at the given index.
     *
     * Walks BACKWARDS through previous steps (latest-match-wins) so that when
     * the same output type appears more than once, the most recently produced
     * file wins. This is always semantically correct: in any well-ordered
     * pipeline the closest upstream producer of a type is the intended input.
     *
     * Example — faceswap (needs ['image', 'video']):
     *   Step 0: text  → image  (scene)
     *   Step 1: text  → image  (face portrait)   ← independent of step 0
     *   Step 2: image → video                    ← depends on step 0
     *   Step 3: faceswap
     *
     *   Backward walk from index 3:
     *     index 2 → outputs video  → assign video = 2
     *     index 1 → outputs image  → assign image = 1  (face portrait — correct!)
     *     both types covered → stop
     *
     *   Forward walk (old, wrong):
     *     index 0 → outputs image  → assign image = 0  (scene — wrong!)
     *     index 2 → outputs video  → assign video = 2
     *
     * @param \Illuminate\Support\Collection $workflows All workflows in order
     * @param int $index Index of the step to build dependencies for
     * @return array Keyed dependency map: [inputType => stepOrder]
     */
    protected function buildKeyedDependencies(\Illuminate\Support\Collection $workflows, int $index): array
    {
        $dependsOn = [];

        if ($index === 0) {
            return $dependsOn;
        }

        $currentWorkflow = $workflows[$index];
        $neededTypes     = $currentWorkflow->input_types ?? [];

        if (empty($neededTypes)) {
            return $dependsOn;
        }

        // Walk BACKWARDS — latest-match-wins
        for ($prevIndex = $index - 1; $prevIndex >= 0; $prevIndex--) {
            $prevOutput = $workflows[$prevIndex]->output_type ?? null;

            if ($prevOutput && in_array($prevOutput, $neededTypes) && ! isset($dependsOn[$prevOutput])) {
                $dependsOn[$prevOutput] = $prevIndex;
            }

            if (count($dependsOn) === count($neededTypes)) {
                break;
            }
        }

        return $dependsOn;
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
        $text = preg_replace('/^INTENT:.*$/im', '', $text);
        $text = preg_replace('/^DEPS:.*$/im', '', $text);
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

    /**
     * Remove consecutive duplicate workflow IDs.
     *
     * READY:1,1,2,2,3 → 1,2,3 (collapsed)
     * READY:1,2,1,2    → 1,2,1,2 (preserved — non-consecutive)
     *
     * This handles the common LLM copy-paste error where the same ID
     * appears multiple times in succession.
     */
    protected function removeConsecutiveDuplicates(array $ids): array
    {
        if (empty($ids)) {
            return $ids;
        }

        $result = [];
        $lastId = null;

        foreach ($ids as $id) {
            if ($id !== $lastId) {
                $result[] = $id;
                $lastId = $id;
            }
        }

        return $result;
    }
}