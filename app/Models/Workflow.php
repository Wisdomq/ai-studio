<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Workflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'description',
        'workflow_json',
        'is_active',
        'input_types',
        'output_type',
        'inject_keys',
        'comfy_workflow_name',
        'discovered_at',
        'default_for_type',
        'mcp_workflow_id',   // ← NEW: snake_case MCP sidecar workflow ID (e.g. "generate_image")
                             //        When set, ExecutePlanJob fetches the graph live from MCP
                             //        rather than reading workflow_json from the DB.
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'default_for_type' => 'boolean',
        'input_types'      => 'array',
        'inject_keys'      => 'array',
        'discovered_at'    => 'datetime',
        // workflow_json intentionally NOT cast — injection works on raw string
        // mcp_workflow_id intentionally NOT cast — plain string
    ];

    // ── Type constants ────────────────────────────────────────────────────────

    const TYPE_IMAGE         = 'image';
    const TYPE_VIDEO         = 'video';
    const TYPE_AUDIO         = 'audio';
    const TYPE_IMAGE_TO_VIDEO = 'image_to_video';
    const TYPE_VIDEO_TO_VIDEO = 'video_to_video';
    const TYPE_AVATAR_VIDEO  = 'avatar_video';
    const TYPE_IMAGE_TO_IMAGE = 'image_to_image';
    const TYPE_AUDIO_TO_VIDEO = 'audio_to_video';
    const TYPE_AUDIO_TO_AUDIO = 'audio_to_audio';
    const TYPE_TEXT_TO_SPEECH = 'text_to_speech';

    // ── Relationships ─────────────────────────────────────────────────────────

    public function planSteps(): HasMany
    {
        return $this->hasMany(WorkflowPlanStep::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'capability_workflow')
            ->withTimestamps();
    }

    public function primaryCapability(): ?Capability
    {
        return $this->capabilities()->where('is_active', true)->first();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfOutputType($query, string $type)
    {
        return $query->where('output_type', $type);
    }

    // ── Source detection ──────────────────────────────────────────────────────

    /**
     * True when this workflow should be fetched live from the MCP sidecar
     * rather than reading workflow_json from the DB.
     *
     * ExecutePlanJob checks this before deciding which execution path to use.
     */
    public function isMcpLiveFetch(): bool
    {
        return ! empty($this->mcp_workflow_id);
    }

    /**
     * True when this workflow should be fetched directly from ComfyUI server
     * via its comfy_workflow_name reference (no local storage).
     *
     * ExecutePlanJob checks this for the ComfyUI-direct execution path.
     */
    public function isComfyuiDirect(): bool
    {
        return ! empty($this->comfy_workflow_name) && empty($this->workflow_json);
    }

    // ── Prompt injection — public entry points ────────────────────────────────

    /**
     * Inject prompt + file overrides into a pre-decoded workflow node graph
     * that was fetched live from the MCP sidecar (no DB storage required).
     *
     * This is the live-fetch execution path used by ExecutePlanJob when
     * $workflow->isMcpLiveFetch() returns true. The caller is responsible
     * for fetching the graph via McpService::mcpFetchWorkflowGraph() first.
     *
     * @param  array  $nodes      Decoded ComfyUI node graph (assoc array keyed by node_id)
     * @param  string $prompt     Refined positive prompt (already sanitised)
     * @param  array  $inputFiles Map of media_type => comfy-assigned filename
     *                            e.g. ['image' => 'upload_abc.png', 'audio' => 'ref.wav']
     * @return string             Fully injected workflow JSON string, ready for submission
     */
    public function injectPromptIntoGraph(array $nodes, string $prompt, array $inputFiles = []): string
    {
        // Auto-detect inject_keys if not set — scan the graph's JSON representation
        if (empty($this->inject_keys)) {
            $rawForDetection = json_encode($nodes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->inject_keys = $this->detectInjectKeysFromJson($rawForDetection);
        }

        // Encode the live graph to a JSON string so performInjection() can
        // operate on it using the same str_replace + seed-walk logic as the
        // stored-JSON path. JSON_UNESCAPED_SLASHES prevents double-escaping
        // of file paths that may appear in node inputs.
        $raw = json_encode($nodes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(
                "Failed to encode live workflow graph to JSON for '{$this->name}': "
                . json_last_error_msg()
            );
        }

        return $this->performInjection($raw, $prompt, $inputFiles);
    }

    /**
     * Inject the refined prompt and optional file overrides into the stored
     * workflow_json string (the classic DB-backed execution path).
     *
     * Supports the full standard placeholder set:
     *   {{PROMPT}} / {{POSITIVE_PROMPT}}  — the refined positive prompt
     *   {{NEGATIVE_PROMPT}}               — hardcoded quality negative
     *   {{SEED}}                          — random seed
     *   {{STEPS}}                         — sampler steps
     *   {{CFG}}                           — cfg scale
     *   {{WIDTH}} / {{HEIGHT}}            — output dimensions
     *   {{FRAME_COUNT}} / {{FPS}}         — video frame settings
     *   {{MOTION_STRENGTH}}               — AnimateDiff motion strength
     *   {{DURATION}}                      — audio/video duration
     *   {{SAMPLE_RATE}}                   — audio sample rate
     *   {{DENOISE}}                       — denoise strength
     *
     * File injection uses inject_keys map:
     *   inject_keys: {"image": "{{INPUT_IMAGE}}", "audio": "{{INPUT_AUDIO}}"}
     *   $inputFiles: {"image": "comfy_assigned_name.png"}
     *
     * @param  string $prompt     The refined positive prompt (already sanitised)
     * @param  array  $inputFiles Map of media_type => comfy-assigned filename
     * @return string             Fully injected workflow JSON string
     */
    public function injectPrompt(string $prompt, array $inputFiles = []): string
    {
        $raw = $this->workflow_json;

        // Auto-detect inject_keys if not set or empty
        if (empty($this->inject_keys)) {
            $this->inject_keys = $this->detectInjectKeysFromJson($raw);
        }

        // Double-decode guard — handles workflows stored as double-encoded JSON
        if (is_string($raw) && str_starts_with(trim($raw), '"')) {
            $unwrapped = json_decode($raw, true);
            if (is_string($unwrapped)) {
                $raw = $unwrapped;
            }
        }

        // Validate JSON before injection
        json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(
                "Workflow '{$this->name}' has invalid JSON: " . json_last_error_msg()
            );
        }

        return $this->performInjection($raw, $prompt, $inputFiles);
    }

    // ── Injection core (shared by both execution paths) ───────────────────────

    /**
     * Apply placeholder substitution and seed randomisation to a workflow JSON string.
     *
     * This private method is the shared core used by both:
     *   - injectPrompt()          — stored-JSON path (reads from $this->workflow_json)
     *   - injectPromptIntoGraph() — live-fetch path  (accepts pre-decoded array)
     *
     * Both callers deliver a validated JSON string and a resolved inject_keys map,
     * then delegate here for the actual substitution work.
     *
     * @param  string $raw        Validated workflow JSON string
     * @param  string $prompt     Refined positive prompt
     * @param  array  $inputFiles Media-type → comfy-assigned filename map
     * @return string             Injected, validated workflow JSON string
     */
    private function performInjection(string $raw, string $prompt, array $inputFiles = []): string
    {
        // Helper: JSON-safe escape a value for string substitution
        $esc = fn(string $value): string => substr(json_encode($value), 1, -1);

        // ── Standard placeholder defaults ─────────────────────────────────────
        // Handle both unquoted ({{SEED}}) and quoted ("{{SEED}}") versions.
        // Note: {{SEED}} / "{{SEED}}" are intentionally omitted here — seed
        // randomisation is handled by the auto-seed node walk below, which
        // writes seed values as JSON integers (not strings).
        $replacements = [
            // Unquoted versions
            '{{PROMPT}}'           => $esc($prompt),
            '{{POSITIVE_PROMPT}}'  => $esc($prompt),
            '{{NEGATIVE_PROMPT}}'  => $esc('blurry, low quality, distorted, deformed, ugly, bad anatomy, watermark, text, logo, out of frame, duplicate, worst quality, jpeg artifacts, noise'),
            '{{STEPS}}'            => '20',
            '{{CFG}}'              => '7.0',
            '{{WIDTH}}'            => '512',
            '{{HEIGHT}}'           => '512',
            '{{FRAME_COUNT}}'      => '16',
            '{{FPS}}'              => '8',
            '{{MOTION_STRENGTH}}'  => '127',
            '{{DURATION}}'         => '10',
            '{{SAMPLE_RATE}}'      => '44100',
            '{{DENOISE}}'          => '1.0',
            // Quoted versions (normalised JSON storage format)
            '"{{PROMPT}}"'           => $esc($prompt),
            '"{{POSITIVE_PROMPT}}"'  => $esc($prompt),
            '"{{NEGATIVE_PROMPT}}"'  => $esc('blurry, low quality, distorted, deformed, ugly, bad anatomy, watermark, text, logo, out of frame, duplicate, worst quality, jpeg artifacts, noise'),
            '"{{STEPS}}"'            => '20',
            '"{{CFG}}"'              => '7.0',
            '"{{WIDTH}}"'            => '512',
            '"{{HEIGHT}}"'           => '512',
            '"{{FRAME_COUNT}}"'      => '16',
            '"{{FPS}}"'              => '8',
            '"{{MOTION_STRENGTH}}"'  => '127',
            '"{{DURATION}}"'         => '10',
            '"{{SAMPLE_RATE}}"'      => '44100',
            '"{{DENOISE}}"'          => '1.0',
        ];

        // ── File input injection via inject_keys map ──────────────────────────
        // inject_keys: {"image": "{{INPUT_IMAGE}}", "audio": "{{INPUT_AUDIO}}"}
        // inputFiles:  {"image": "comfy_assigned.png"}
        foreach ($this->inject_keys ?? [] as $mediaType => $placeholder) {
            if (isset($inputFiles[$mediaType])) {
                // Handle both quoted and unquoted placeholder forms
                $replacements[$placeholder]               = $esc($inputFiles[$mediaType]);
                $replacements['"' . $placeholder . '"']  = $esc($inputFiles[$mediaType]);
            }
        }

        $injected = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $raw
        );

        // ── Auto-seed pass ────────────────────────────────────────────────────
        // Walk every ComfyUI node and replace any seed/noise_seed field with a
        // fresh random number. This covers two cases:
        //   1. Workflows with {{SEED}} placeholder — already replaced above, but
        //      the quoted-string form ("{{SEED}}") leaves the value as a string;
        //      this pass writes it as a proper JSON integer.
        //   2. Workflows imported directly from ComfyUI with a hardcoded seed —
        //      no placeholder was ever set, but we still want randomisation.
        //
        // This applies equally to both the stored-JSON and live-fetch paths
        // because performInjection() always operates on a JSON string, not the
        // original array. The live-fetch path re-encodes the array first in
        // injectPromptIntoGraph(), so by the time we get here, both paths are
        // identical from this method's perspective.
        $nodes = json_decode($injected, true);
        if (is_array($nodes)) {
            $seeded = false;
            foreach ($nodes as &$node) {
                if (! isset($node['inputs']) || ! is_array($node['inputs'])) {
                    continue;
                }
                foreach (['noise_seed', 'seed'] as $seedKey) {
                    if (array_key_exists($seedKey, $node['inputs'])) {
                        $node['inputs'][$seedKey] = rand(1, 999_999_999);
                        $seeded = true;
                    }
                }
            }
            unset($node);

            if ($seeded) {
                $injected = json_encode($nodes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        // Validate JSON after injection
        json_decode($injected);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(
                'Workflow JSON became invalid after prompt injection. ' .
                'The prompt may contain characters that break JSON. Error: ' .
                json_last_error_msg()
            );
        }

        return $injected;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * True when this workflow has a real ComfyUI node graph loaded.
     * Skeletons/stubs have a "_note" key or no class_type nodes.
     */
    public function hasRealWorkflow(): bool
    {
        if (empty($this->workflow_json)) {
            // MCP live-fetch workflows have no stored JSON — treat as valid
            // if they have an mcp_workflow_id instead.
            return ! empty($this->mcp_workflow_id);
        }

        $json = $this->workflow_json;

        // Substitute remaining numeric placeholders with dummy values so
        // json_decode() can validate the structure. SEED is handled by the
        // auto-seed node walk in injectPrompt() and needs no placeholder here.
        $placeholders = [
            '{{STEPS}}', '{{CFG}}', '{{WIDTH}}', '{{HEIGHT}}',
            '{{FRAME_COUNT}}', '{{FPS}}', '{{MOTION_STRENGTH}}', '{{DURATION}}',
            '{{SAMPLE_RATE}}', '{{DENOISE}}',
            '"{{STEPS}}"', '"{{CFG}}"', '"{{WIDTH}}"', '"{{HEIGHT}}"',
            '"{{FRAME_COUNT}}"', '"{{FPS}}"', '"{{MOTION_STRENGTH}}"', '"{{DURATION}}"',
            '"{{SAMPLE_RATE}}"', '"{{DENOISE}}"',
        ];
        $values = [
            '20', '7', '512', '512', '16', '8', '127', '10', '44100', '1',
            '20', '7', '512', '512', '16', '8', '127', '10', '44100', '1',
        ];

        $json = str_replace($placeholders, $values, $json);

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return false;
        }
        if (isset($decoded['_note'])) {
            return false;
        }
        foreach ($decoded as $node) {
            if (isset($node['class_type'])) {
                return true;
            }
        }
        return false;
    }

    public function requiresFileInputs(): bool
    {
        return ! empty($this->input_types);
    }

    public function isTextOnly(): bool
    {
        return empty($this->input_types);
    }

    public function injectKeyFor(string $mediaType): ?string
    {
        return ($this->inject_keys ?? [])[$mediaType] ?? null;
    }

    /**
     * Auto-detect inject_keys from workflow JSON by scanning for INPUT_* placeholders.
     */
    protected function detectInjectKeysFromJson(string $raw): array
    {
        $injectKeys = [];

        if (empty($raw)) {
            return $injectKeys;
        }

        // Unwrap double-encoded JSON if needed
        if (str_starts_with(trim($raw), '"')) {
            $unwrapped = json_decode($raw, true);
            if (is_string($unwrapped)) {
                $raw = $unwrapped;
            }
        }

        $patterns = [
            'image' => '/\{\{INPUT_IMAGE\}\}/i',
            'video' => '/\{\{INPUT_VIDEO\}\}/i',
            'audio' => '/\{\{INPUT_AUDIO\}\}/i',
        ];

        foreach ($patterns as $mediaType => $pattern) {
            if (preg_match($pattern, $raw)) {
                $injectKeys[$mediaType] = "{{INPUT_" . strtoupper($mediaType) . "}}";
            }
        }

        if (! empty($injectKeys)) {
            \Illuminate\Support\Facades\Log::info("Workflow auto-detected inject_keys: ", [
                'workflow'    => $this->name,
                'inject_keys' => $injectKeys,
            ]);
        }

        return $injectKeys;
    }

    /**
     * Build a formatted capability entry for OrchestratorAgent system prompt.
     */
    public function toCapabilityString(): string
    {
        $inputTypes = empty($this->input_types) ? 'none (text-only)' : implode(', ', $this->input_types);
        $isDefault  = $this->default_for_type ? ' [DEFAULT]' : '';
        $source     = $this->isMcpLiveFetch() ? " [MCP:{$this->mcp_workflow_id}]" : '';

        return sprintf(
            '- ID: %d | Name: %s | Type: %s | Output: %s | Requires inputs: %s%s%s | %s',
            $this->id,
            $this->name,
            $this->type,
            $this->output_type,
            $inputTypes,
            $isDefault,
            $source,
            $this->description
        );
    }
}