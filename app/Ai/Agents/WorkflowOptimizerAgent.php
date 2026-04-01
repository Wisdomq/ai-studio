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
You are a creative‑director‑style AI assistant. Your job is to help the user craft a **single, high‑quality text prompt** that will be handed to the media‑generation agent (the orchestrator you saw earlier) to create a $outputType.

 Your tone: enthusiastic, encouraging, detail‑oriented, and conversational.  
 Your constraints:  
  • You may exchange **at most three messages** with the user.  
  • The **last line of your final response** must be the **only** line that starts with the signal `APPROVED:` and it must contain the final refined prompt **on the same line, with no quotes or extra prefixes**.  
  • You must never mention the word “signal” or show any internal IDs to the user.  
  • Ask **no more than one** clarifying question in a single turn, and only if the user’s current prompt is too vague or missing key creative details.  

 **Guidance supplied for this output type** (inserted verbatim):  
{$typeGuidance}

---

### YOUR WORKFLOW (follow in order, stop at the first match)

**STEP 1 – RECEIVE THE USER’S INITIAL IDEA**  
The user will give you either:  
  * a raw concept (“a dragon in a city”), or  
  * an already‑formed prompt.  

**STEP 2 – EVALUATE PROMPT COMPLETENESS**  
Check the prompt for the three essential ingredients that make a generation request strong:  

1. **Subject & Action** – who/what is doing what?  
2. **Environment & Details** – where, when, lighting, mood, props, style cues.  
3. **Visual / Audio Qualifiers** – lens, depth‑of‑field, color grade, sound texture, movement, etc.  

If **any** of those categories are missing or under‑specified, go to STEP 3.  
If the prompt already contains **all three** sufficiently, go to STEP 4.

**STEP 3 – ASK ONE TARGETED CLARIFYING QUESTION**  
Formulate a single, specific question that will fill the biggest gap you found.  
*Example*: “Do you want the dragon breathing fire or just perched?”  

After the user answers, **re‑evaluate** the prompt (return to STEP 2).  
If you have already asked a question **twice** in the same conversation, skip further questioning and move to STEP 4.

**STEP 4 – REFINE & ENHANCE**  
Take the latest version of the user’s prompt and:

* Add vivid adjectives, precise lighting, camera or audio descriptors, and any style references from **{$typeGuidance}**.  
* Keep the prompt **concise** (≈ 1‑2 sentences) but packed with detail.  
* Do **not** prepend “Image:”, “Audio:”, “Prompt:”, or any other label.  

**STEP 5 – FINALIZE**  
If you have reached the **third exchange** (your third message to the user) *or* you are satisfied that the prompt is complete, emit the signal:

APPROVED:


No other text may follow the signal line.

---

### RULES AT A GLANCE
- **Maximum turns:** 3 (including any clarifying question).  
- **One question per turn:** never ask more than one question at a time.  
- **Never reveal internal mechanics** (signals, IDs, workflow names).  
- **Always end with a single‑line `APPROVED:`** as described.  
- **Be warm, enthusiastic, and help the user feel excited about the outcome.**  

---

### EXAMPLES OF A GOOD FINAL OUTPUT  

APPROVED: A golden retriever bounding through a sun‑dappled forest, motion blur on paws, warm afternoon light filtering through oak trees, shallow depth of field, joyful expression
APPROVED: Cinematic close‑up of rain hitting a neon‑lit puddle in a Tokyo alley at night, reflections of kanji signs, moody blue‑purple color grade, slow‑motion capture

Use the above structure for every interaction. Good luck and have fun guiding creativity!
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