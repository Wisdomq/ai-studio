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
You are a friendly, creative AI media generation assistant. You help users create images, videos, and audio through a simple conversation.

AVAILABLE WORKFLOWS:
{$capabilityList}

YOUR JOB:
1. Understand what the user wants to create through natural conversation.
2. Be warm, enthusiastic, and concise. No corporate-speak.
3. Ask at most ONE question if you genuinely need clarification.
4. When you know what to make and which workflow to use, end your response with the signal below.

SIGNALS (always on the last line, nothing after):
- When ready to generate: READY:<workflow_id>
  Example: "Great choice! Let's make that happen. READY:3"
- When multiple workflows could work and user hasn't chosen: AMBIGUOUS:<id1>,<id2>
  Example: "I can do that a few ways — want a quick turbo image or a more detailed render? AMBIGUOUS:3,5"

IMPORTANT:
- Use READY only when you are certain about the workflow. Use the exact ID number.
- For multi-step (e.g. image then video), use READY with the FIRST step's workflow ID only.
- The signal must be the very last thing in your response, on its own.
- Do not explain the signal or mention workflow IDs to the user — just use the signal.
- Be yourself: excited about creative work, helpful, natural.
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

        $response = prism()
            ->text()
            ->using(Provider::Ollama, $this->model)
            ->withClientOptions(['timeout' => 120])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($prismMessages)
            ->generate();

        $fullText = $response->text;

        // Emit word by word — strip signal line from display
        $displayText = $this->stripSignal($fullText);
        $words       = explode(' ', $displayText ?: $fullText);
        foreach ($words as $i => $word) {
            $onChunk(($i === 0 ? '' : ' ') . $word);
        }

        return $fullText;
    }

    // ─── Plan building (PHP-only, no LLM JSON) ────────────────────────────────

    /**
     * Parse the LLM response for a READY or AMBIGUOUS signal.
     * Returns a structured result for the controller to act on.
     *
     * @return array{type: 'ready'|'ambiguous'|'none', workflow_ids: int[], plan: array|null}
     */
    public function parseSignal(string $rawResponse, array $conversationMessages = []): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $rawResponse)));
        $lastLine = end($lines) ?: '';

        // READY:<id>
        if (preg_match('/READY:(\d+)/i', $lastLine, $m)) {
            $workflowId = (int) $m[1];
            $workflow   = Workflow::active()->find($workflowId);

            if (! $workflow) {
                Log::warning("OrchestratorAgent: READY signal has invalid workflow ID {$workflowId}");
                return ['type' => 'none', 'workflow_ids' => [], 'plan' => null];
            }

            $plan = $this->buildPlan($workflow, $conversationMessages);

            return ['type' => 'ready', 'workflow_ids' => [$workflowId], 'plan' => $plan];
        }

        // AMBIGUOUS:<id1>,<id2>,...
        if (preg_match('/AMBIGUOUS:([\d,]+)/i', $lastLine, $m)) {
            $ids = array_map('intval', explode(',', $m[1]));
            return ['type' => 'ambiguous', 'workflow_ids' => $ids, 'plan' => null];
        }

        return ['type' => 'none', 'workflow_ids' => [], 'plan' => null];
    }

    /**
     * Build a clean plan array from a workflow + conversation context.
     * All plan construction happens here in PHP — never from LLM JSON.
     */
    public function buildPlan(Workflow $workflow, array $conversationMessages = []): array
    {
        // Extract the user's original intent from full conversation history
        $userIntent = '';
        foreach ($conversationMessages as $msg) {
            if ($msg['role'] === 'user') {
                // Strip any mood board style hints appended by frontend
                $content = preg_replace('/\[Style:[^\]]*\]/', '', $msg['content']);
                $userIntent = trim($content);
                break; // Use first user message — the original request
            }
        }

        return [[
            'step_order'    => 0,
            'workflow_id'   => $workflow->id,
            'workflow_type' => $workflow->output_type,
            'purpose'       => $userIntent ?: "Generate {$workflow->output_type} using {$workflow->name}",
            'prompt_hint'   => $userIntent, // Always carry full intent — optimizer uses this
            'depends_on'    => [],
        ]];
    }

    protected function buildPurpose(Workflow $workflow, string $userIntent): string
    {
        if (! empty($userIntent)) {
            // Truncate to a clean short description
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

    // ─── Legacy parsePlan (kept for backward compat) ─────────────────────────

    /**
     * Fallback plan parser for any LLM responses that still contain [PLAN] blocks.
     */
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

    // ─── Server-side disambiguation shortcut ─────────────────────────────────

    public function tryResolvePlanFromSelection(array $messages): ?array
    {
        if (count($messages) < 3) {
            return null;
        }

        // Only activate after a disambiguation exchange
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

        // Explicit ID reference
        if (preg_match('/(?:id\s*:?\s*|#|\()(\d+)\)?/i', $lastUserMsg, $m)) {
            $workflow = Workflow::active()->find((int) $m[1]);
            if ($workflow) {
                return $this->buildPlan($workflow, $messages);
            }
        }

        // Name/alias match
        $activeWorkflows = Workflow::active()->get();
        foreach ($activeWorkflows as $workflow) {
            $nameLower  = strtolower($workflow->name);
            $words      = array_filter(explode(' ', $nameLower), fn($w) => strlen($w) > 3);
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

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function stripSignal(string $text): string
    {
        // Remove the READY: or AMBIGUOUS: signal line from display text
        $text = preg_replace('/\n?READY:\d+\s*$/i', '', $text);
        $text = preg_replace('/\n?AMBIGUOUS:[\d,]+\s*$/i', '', $text);
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