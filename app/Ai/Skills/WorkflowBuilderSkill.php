<?php

namespace App\Ai\Skills;

use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * WorkflowBuilderSkill
 *
 * Builds new ComfyUI workflows from user intent using pre-validated templates.
 *
 * Strategy (template-first, reliable):
 *   1. Use LLM only to CLASSIFY the intent into a workflow type
 *      (image / video / audio / image_to_video / video_to_video)
 *   2. Select the matching pre-validated template from WorkflowTemplateLibrary
 *   3. Generate a human-friendly name for the workflow
 *   4. Save to DB as active — immediately available to the OrchestratorAgent
 *
 * This avoids LLM hallucination of node names/connections entirely.
 * The LLM does a simple classification task it can do reliably.
 */
class WorkflowBuilderSkill
{
    protected string $model;

    public function __construct(string $model = 'mistral:7b')
    {
        $this->model = $model;
    }

    // ─── Main entry point ─────────────────────────────────────────────────────

    /**
     * Build and save a workflow from user intent.
     *
     * @param  string $userIntent  The original user message describing the workflow
     * @return array{success: bool, workflow?: Workflow, error?: string}
     */
    public function buildFromIntent(string $userIntent): array
    {
        try {
            // Step 1: classify intent → workflow type
            $type = $this->classifyIntent($userIntent);

            if ($type === null) {
                return [
                    'success' => false,
                    'error'   => "I couldn't determine what type of workflow to create from your description. Try being more specific — e.g. \"text to image workflow\" or \"video generation workflow\".",
                ];
            }

            // Step 2: get template
            $template = WorkflowTemplateLibrary::get($type);

            if ($template === null) {
                return [
                    'success' => false,
                    'error'   => "No template available for workflow type: {$type}.",
                ];
            }

            // Step 3: generate a human-friendly name
            $name = $this->generateName($userIntent, $type);

            // Step 4: save to DB
            $workflow = $this->saveWorkflow($name, $type, $userIntent, $template);

            Log::info('WorkflowBuilderSkill: Workflow created from template', [
                'workflow_id' => $workflow->id,
                'type'        => $type,
                'name'        => $name,
            ]);

            return [
                'success'  => true,
                'workflow' => $workflow,
            ];

        } catch (\Throwable $e) {
            Log::error('WorkflowBuilderSkill: Failed to build workflow', [
                'intent' => $userIntent,
                'error'  => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ─── Intent classification ────────────────────────────────────────────────

    /**
     * Ask the LLM to classify the user intent into one of the supported types.
     * This is a simple classification task — no JSON generation, no hallucination risk.
     */
    protected function classifyIntent(string $userIntent): ?string
    {
        $supportedTypes = implode(', ', WorkflowTemplateLibrary::supportedTypes());

        $prompt = <<<PROMPT
Classify the following workflow creation request into exactly ONE of these types:
{$supportedTypes}

Rules:
- Reply with ONLY the type string, nothing else
- If the request mentions images/photos/pictures → image
- If the request mentions video/animation/motion → video
- If the request mentions audio/voice/speech/sound/music/TTS → audio
- If the request mentions converting an image into a video → image_to_video
- If the request mentions face swap or replacing a face in a video → video_to_video
- If unclear, default to: image

Request: "{$userIntent}"

Type:
PROMPT;

        try {
            $response = prism()
                ->text()
                ->using(Provider::Ollama, $this->model)
                ->withClientOptions(['timeout' => 60])
                ->withMessages([new UserMessage($prompt)])
                ->generate();

            $raw = strtolower(trim($response->text));

            // Try direct match first
            $resolved = WorkflowTemplateLibrary::resolveType($raw);
            if ($resolved !== null) {
                return $resolved;
            }

            // Try to find a type keyword anywhere in the response
            foreach (WorkflowTemplateLibrary::supportedTypes() as $type) {
                if (str_contains($raw, $type)) {
                    return $type;
                }
            }

            // Keyword fallback — don't rely solely on LLM output
            return $this->keywordFallback($userIntent);

        } catch (\Throwable $e) {
            Log::warning('WorkflowBuilderSkill: LLM classification failed, using keyword fallback', [
                'error' => $e->getMessage(),
            ]);
            // If LLM is unavailable, fall back to keyword matching
            return $this->keywordFallback($userIntent);
        }
    }

    /**
     * Keyword-based type detection as fallback when LLM is unavailable.
     * Uses the user's original message directly.
     */
    protected function keywordFallback(string $intent): string
    {
        $lower = strtolower($intent);

        if (str_contains($lower, 'face swap') || str_contains($lower, 'reactor')) {
            return WorkflowTemplateLibrary::TYPE_VIDEO_TO_VIDEO;
        }
        if (str_contains($lower, 'image to video') || str_contains($lower, 'img2vid') || str_contains($lower, 'image into video')) {
            return WorkflowTemplateLibrary::TYPE_IMAGE_TO_VIDEO;
        }
        if (str_contains($lower, 'audio') || str_contains($lower, 'voice') || str_contains($lower, 'speech') || str_contains($lower, 'tts')) {
            return WorkflowTemplateLibrary::TYPE_TEXT_TO_AUDIO;
        }
        if (str_contains($lower, 'video') || str_contains($lower, 'animat') || str_contains($lower, 'motion')) {
            return WorkflowTemplateLibrary::TYPE_TEXT_TO_VIDEO;
        }

        // Default to text-to-image
        return WorkflowTemplateLibrary::TYPE_TEXT_TO_IMAGE;
    }

    // ─── Name generation ──────────────────────────────────────────────────────

    /**
     * Generate a human-friendly workflow name.
     * Uses a readable type label + a shortened intent.
     */
    protected function generateName(string $userIntent, string $type): string
    {
        $typeLabel = match ($type) {
            WorkflowTemplateLibrary::TYPE_TEXT_TO_IMAGE  => 'Text to Image',
            WorkflowTemplateLibrary::TYPE_TEXT_TO_VIDEO  => 'Text to Video',
            WorkflowTemplateLibrary::TYPE_TEXT_TO_AUDIO  => 'Text to Audio',
            WorkflowTemplateLibrary::TYPE_IMAGE_TO_VIDEO => 'Image to Video',
            WorkflowTemplateLibrary::TYPE_VIDEO_TO_VIDEO => 'Video Face Swap',
            default                                       => Str::title($type),
        };

        // Strip workflow-builder phrases from the intent for a cleaner name
        $cleaned = preg_replace('/\b(create|build|generate|make|add|new|a|an|the|workflow|for|me)\b/i', '', $userIntent);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

        if (strlen($cleaned) > 3) {
            $suffix = Str::title(Str::limit($cleaned, 30, ''));
            return "{$typeLabel} — {$suffix}";
        }

        return $typeLabel;
    }

    // ─── DB persistence ───────────────────────────────────────────────────────

    protected function saveWorkflow(string $name, string $type, string $intent, string $json): Workflow
    {
        return Workflow::create([
            'name'                => $name,
            'type'                => $type,
            'output_type'         => WorkflowTemplateLibrary::outputType($type),
            'description'         => $intent,
            'workflow_json'       => $json,
            'is_active'           => true,   // immediately available
            'default_for_type'    => false,
            'input_types'         => WorkflowTemplateLibrary::inputTypes($type),
            'inject_keys'         => WorkflowTemplateLibrary::injectKeys($type),
            'comfy_workflow_name' => null,
            'discovered_at'       => now(),
        ]);
    }
}