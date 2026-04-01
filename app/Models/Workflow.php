<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'default_for_type' => 'boolean',
        'input_types'      => 'array',
        'inject_keys'      => 'array',
        'discovered_at'    => 'datetime',
        // workflow_json intentionally NOT cast — injectPrompt() works on raw string
    ];

    // ── Type constants ────────────────────────────────────────────────────────

    const TYPE_IMAGE         = 'image';
    const TYPE_VIDEO         = 'video';
    const TYPE_AUDIO         = 'audio';
    const TYPE_IMAGE_TO_VIDEO = 'image_to_video';
    const TYPE_VIDEO_TO_VIDEO = 'video_to_video';
    const TYPE_AVATAR_VIDEO  = 'avatar_video';

    // ── Relationships ─────────────────────────────────────────────────────────

    public function planSteps(): HasMany
    {
        return $this->hasMany(WorkflowPlanStep::class);
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

    // ── Prompt injection (V1 superior version) ────────────────────────────────

    /**
     * Inject the refined prompt and optional file overrides into the raw
     * workflow_json string.
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
     *                            e.g. ['image' => 'upload_abc.png', 'audio' => 'ref_xyz.wav']
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

        // Helper: JSON-safe escape a value for string substitution
        $esc = fn(string $value): string => substr(json_encode($value), 1, -1);

        // ── Standard placeholder defaults ─────────────────────────────────────
        // Handle both unquoted ({{SEED}}) and quoted ("{{SEED}}") versions
        // Note: {{SEED}} / "{{SEED}}" are intentionally omitted here — seed
        // randomization is handled by the auto-seed node walk below, which
        // correctly writes seed values as JSON integers (not strings).
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
            '{{DURATION}}'        => '10',
            '{{SAMPLE_RATE}}'      => '44100',
            '{{DENOISE}}'          => '1.0',
            // Quoted versions (normalized JSON storage format)
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
            '"{{DURATION}}"'        => '10',
            '"{{SAMPLE_RATE}}"'      => '44100',
            '"{{DENOISE}}"'          => '1.0',
        ];

        // ── File input injection via inject_keys map ──────────────────────────
        // inject_keys: {"image": "{{INPUT_IMAGE}}", "audio": "{{INPUT_AUDIO}}"}
        // inputFiles:  {"image": "comfy_assigned.png"}
        foreach ($this->inject_keys ?? [] as $mediaType => $placeholder) {
            if (isset($inputFiles[$mediaType])) {
                // Handle both quoted and unquoted
                $replacements[$placeholder] = $esc($inputFiles[$mediaType]);
                $replacements['"' . $placeholder . '"'] = $esc($inputFiles[$mediaType]);
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
        //      no placeholder was ever set, but we still want randomization.
        // This mirrors what ComfyUI does internally in "randomize" mode.
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
     * Skeletons/stubbs have a "_note" key or no class_type nodes.
     */
    public function hasRealWorkflow(): bool
    {
        if (empty($this->workflow_json)) {
            return false;
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
                'workflow' => $this->name,
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

        return sprintf(
            '- ID: %d | Name: %s | Type: %s | Output: %s | Requires inputs: %s%s | %s',
            $this->id,
            $this->name,
            $this->type,
            $this->output_type,
            $inputTypes,
            $isDefault,
            $this->description
        );
    }
}