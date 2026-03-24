<?php

namespace App\Ai\Agents;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * WorkflowOptimizerAgent
 *
 * Refines the user's prompt through natural conversation.
 * Max 3 turns. On turn 3, outputs APPROVED:<prompt> signal.
 *
 * Strategy: LLM does natural conversation only.
 * PHP extracts the approved prompt via APPROVED: signal — no JSON blocks.
 */
class WorkflowOptimizerAgent
{
    protected string $model;

    public function __construct(string $model = 'mistral:7b')
    {
        $this->model = $model;
    }

    // ─── System Prompt ───────────────────────────────────────────────────────

    protected function buildSystemPrompt(string $outputType): string
    {
        $typeGuidance = match ($outputType) {
            'image'        => "For images: think about subject, mood, lighting, style, color palette, composition. Help them paint a vivid picture with words.",
            'video'        => "For video: think about scene, motion, camera movement, duration, atmosphere, subject action.",
            'audio'        => "For audio: think about genre, mood, tempo, instruments, vocal style, energy level.",
            'avatar_video' => "For avatar video: think about the character's appearance, what they're saying, their expression, the setting.",
            default        => "Help them craft a detailed, specific prompt that will produce great results.",
        };

        return <<<SYSPROMPT
You are a creative director helping someone craft the perfect prompt for {$outputType} generation. You're enthusiastic, encouraging, and have a great eye for detail.

{$typeGuidance}

YOUR APPROACH:
- Chat naturally. Be warm and creative.
- If their prompt is vague, ask ONE specific question to make it richer.
- If their prompt is already good, enhance it and confirm.
- You have up to 3 exchanges. After that you must commit to a final prompt.
- When you're happy with the prompt (or it's turn 3), end with the signal below.

SIGNAL (last line of your response, nothing after):
APPROVED: <the complete refined prompt — no quotes, no prefix like "Image:" or "Prompt:">

Examples of good approved prompts:
APPROVED: A golden retriever bounding through a sun-dappled forest, motion blur on paws, warm afternoon light filtering through oak trees, shallow depth of field, joyful expression
APPROVED: Cinematic close-up of rain hitting a neon-lit puddle in a Tokyo alley at night, reflections of kanji signs, moody blue-purple color grade

Keep the approved prompt on ONE line after APPROVED:
SYSPROMPT;
    }

    // ─── Streaming ───────────────────────────────────────────────────────────

    public function stream(array $messages, string $outputType, int $turnNumber, callable $onChunk): string
    {
        $systemPrompt  = $this->buildSystemPrompt($outputType);
        $prismMessages = $this->buildPrismMessages($messages);

        // Force approval on turn 3
        if ($turnNumber >= 3) {
            $prismMessages[] = new UserMessage(
                "This is the final turn. Please finalize the prompt and end with APPROVED: <your best prompt>."
            );
        }

        $response = prism()
            ->text()
            ->using(Provider::Ollama, $this->model)
            ->withClientOptions(['timeout' => 120])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($prismMessages)
            ->generate();

        $fullText = $response->text;

        // Emit display text (strip APPROVED: signal line)
        $displayText = $this->stripSignal($fullText);
        $words       = explode(' ', $displayText ?: $fullText);
        foreach ($words as $i => $word) {
            $onChunk(($i === 0 ? '' : ' ') . $word);
        }

        return $fullText;
    }

    // ─── Signal parsing ──────────────────────────────────────────────────────

    /**
     * Extract approved prompt from APPROVED: signal.
     * Returns null if no signal found.
     */
    public function parseApprovedPrompt(string $rawResponse): ?string
    {
        // Find APPROVED: line (may be anywhere but typically last)
        // Use last occurrence in case model outputs multiple
        $lines   = explode("\n", $rawResponse);
        $prompt  = null;

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (preg_match('/^APPROVED:\s*(.+)$/i', $line, $m)) {
                $prompt = trim($m[1]);
                break;
            }
        }

        if (! $prompt) {
            // Fallback: try legacy [READY_FOR_APPROVAL] block
            preg_match_all('/\[READY_FOR_APPROVAL\](.*?)(?:\[\/READY_FOR_APPROVAL\]|$)/s', $rawResponse, $matches);
            if (! empty($matches[1])) {
                $blockContent = trim(end($matches[1]));
                if (preg_match('/^PROMPT:\s*(.+?)(?:\n|$)/m', $blockContent, $pm)) {
                    $prompt = trim($pm[1]);
                }
            }
        }

        if (! $prompt) {
            Log::warning('WorkflowOptimizerAgent: No approved prompt signal found');
            return null;
        }

        return $this->sanitisePrompt($prompt);
    }

    public function sanitisePrompt(string $prompt): ?string
    {
        // Strip type prefixes
        $prompt = preg_replace('/^(Image|Video|Audio|Avatar|Prompt):\s*/i', '', $prompt);
        $prompt = stripslashes($prompt);
        $prompt = trim($prompt, '"\'`');
        $prompt = trim($prompt, '[]');
        $prompt = trim($prompt);

        return $this->isValidPrompt($prompt) ? $prompt : null;
    }

    protected function isValidPrompt(string $prompt): bool
    {
        if (strlen($prompt) < 5) return false;

        $markers = ['APPROVED:', 'READY_FOR_APPROVAL', 'PROMPT:', 'EXPLANATION:', 'SYSPROMPT'];
        foreach ($markers as $marker) {
            if (str_contains(strtoupper($prompt), strtoupper($marker))) return false;
        }

        return true;
    }

    protected function stripSignal(string $text): string
    {
        $text = preg_replace('/\n?APPROVED:.*$/im', '', $text);
        return trim($text);
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