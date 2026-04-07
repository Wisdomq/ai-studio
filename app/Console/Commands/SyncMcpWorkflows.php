<?php

namespace App\Console\Commands;

use App\Models\Workflow;
use App\Services\McpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * workflows:sync
 *
 * Discovers workflow JSON files from the MCP sidecar server and upserts
 * them into the workflows table, translating PARAM_* placeholders to
 * the {{PLACEHOLDER}} format used by Workflow::injectPrompt().
 *
 * Usage:
 *   vendor/bin/sail artisan workflows:sync            # dry run — preview only
 *   vendor/bin/sail artisan workflows:sync --import   # write to DB
 *   vendor/bin/sail artisan workflows:sync --force    # overwrite existing JSON too
 *
 * Requires: COMFYUI_MCP_ENABLED=true in .env
 */
class SyncMcpWorkflows extends Command
{
    protected $signature = 'workflows:sync
                            {--import : Write discovered workflows to the database}
                            {--force  : Overwrite workflow_json for workflows that already exist}
                            {--type=  : Value for the type column (default: comfyui)}';

    protected $description = 'Sync workflow JSON files from the MCP sidecar server into the workflows table';

    /**
     * PARAM_* → {{PLACEHOLDER}} translation map.
     */
    private const PARAM_MAP = [
        // Prompt
        'PARAM_PROMPT'                => '{{POSITIVE_PROMPT}}',
        'PARAM_POSITIVE'              => '{{POSITIVE_PROMPT}}',
        'PARAM_NEGATIVE'              => '{{NEGATIVE_PROMPT}}',
        'PARAM_NEGATIVE_PROMPT'       => '{{NEGATIVE_PROMPT}}',
        'PARAM_LYRICS'                => '{{LYRICS}}',
        'PARAM_TAGS'                  => '{{TAGS}}',
        // Numeric — image/video
        'PARAM_INT_STEPS'             => '{{STEPS}}',
        'PARAM_FLOAT_CFG'             => '{{CFG}}',
        'PARAM_INT_WIDTH'             => '{{WIDTH}}',
        'PARAM_INT_HEIGHT'            => '{{HEIGHT}}',
        'PARAM_INT_SEED'              => '{{SEED}}',
        'PARAM_FLOAT_DENOISE'         => '{{DENOISE}}',
        'PARAM_INT_FPS'               => '{{FPS}}',
        'PARAM_INT_FRAMES'            => '{{FRAME_COUNT}}',
        'PARAM_INT_DURATION'          => '{{DURATION}}',
        'PARAM_INT_SECONDS'           => '{{DURATION}}',   // alias used by generate_song
        'PARAM_INT_SAMPLE_RATE'       => '{{SAMPLE_RATE}}',
        'PARAM_FLOAT_MOTION'          => '{{MOTION_STRENGTH}}',
        'PARAM_FLOAT_LYRICS_STRENGTH' => '{{LYRICS_STRENGTH}}',
        // String params
        'PARAM_STR_SAMPLER_NAME'      => '{{SAMPLER_NAME}}',
        'PARAM_STR_SCHEDULER'         => '{{SCHEDULER}}',
        'PARAM_MODEL'                 => '{{MODEL}}',
        // Media inputs
        'PARAM_INPUT_IMAGE'           => '{{INPUT_IMAGE}}',
        'PARAM_INPUT_VIDEO'           => '{{INPUT_VIDEO}}',
        'PARAM_INPUT_AUDIO'           => '{{INPUT_AUDIO}}',
    ];

    /**
     * Value written to the non-nullable `type` column.
     * Override with --type=<value> if your schema uses a different value.
     *
     * To inspect valid values:
     *   vendor/bin/sail artisan tinker --execute="print_r(DB::select('SHOW COLUMNS FROM workflows'));"
     */
    private const DEFAULT_TYPE = 'comfyui';

    public function handle(McpService $mcp): int
    {
        $importing = $this->option('import');
        $force     = $this->option('force');
        $typeValue = $this->option('type') ?: self::DEFAULT_TYPE;

        $mode = $importing ? ($force ? 'IMPORT + FORCE OVERWRITE' : 'IMPORT') : 'DRY RUN';
        $this->info("workflows:sync — mode: {$mode}");
        $this->line('');

        // ── Fetch workflow list from MCP server ───────────────────────────────
        try {
            $discovered = $mcp->mcpListWorkflows();
        } catch (\Throwable $e) {
            $this->error('Failed to contact MCP server: ' . $e->getMessage());
            $this->line('Ensure COMFYUI_MCP_ENABLED=true and the comfyui-mcp container is running.');
            return self::FAILURE;
        }

        if (empty($discovered)) {
            $this->warn('MCP server returned no workflows. Check the workflows/ directory in the sidecar container.');
            return self::SUCCESS;
        }

        $this->info('Discovered ' . count($discovered) . ' workflow(s) from MCP server.');
        $this->line('');

        $results = ['imported' => 0, 'skipped' => 0, 'updated' => 0, 'flagged' => []];

        foreach ($discovered as $wf) {
            $workflowId  = $wf['id']   ?? null;
            $displayName = $wf['name'] ?? $workflowId;

            if (! $workflowId) {
                $this->warn('  [SKIP] Workflow entry with no id — skipping.');
                continue;
            }

            $this->line("  Processing: <comment>{$displayName}</comment> (id: {$workflowId})");

            // ── Fetch raw JSON via MCP get_workflow_json tool ─────────────────
            try {
                $rawJson = $mcp->mcpGetWorkflowJson($workflowId);
            } catch (\Throwable $e) {
                $this->warn("    [SKIP] MCP call failed for '{$workflowId}': " . $e->getMessage());
                $results['skipped']++;
                continue;
            }

            if (! $rawJson) {
                $this->warn("    [SKIP] Could not retrieve JSON for '{$workflowId}'.");
                $results['skipped']++;
                continue;
            }

            // ── Translate PARAM_* → {{PLACEHOLDER}} ──────────────────────────
            [$translatedJson, $unmapped] = $this->translatePlaceholders($rawJson);

            if (! empty($unmapped)) {
                $this->warn("    [FLAG] Unmapped placeholders: " . implode(', ', $unmapped));
                $results['flagged'][] = ['name' => $workflowId, 'unmapped' => $unmapped];
            }

            // ── Normalise numeric placeholders (quote {{SEED}} etc.) ──────────
            $normalizedJson = $this->normalizePlaceholders($translatedJson);

            // ── Validate JSON ─────────────────────────────────────────────────
            json_decode($normalizedJson);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("    [SKIP] JSON invalid after translation: " . json_last_error_msg());
                $results['skipped']++;
                continue;
            }

            // ── Check DB for existing record ──────────────────────────────────
            $existing = Workflow::where('name', $workflowId)->first();

            if ($existing) {
                if (! $force) {
                    $this->line("    [EXISTS] Already in DB — skipping (use --force to overwrite JSON).");
                    $results['skipped']++;
                    continue;
                }

                if ($importing) {
                    $existing->update(['workflow_json' => $normalizedJson]);
                    $this->line("    [UPDATED] workflow_json overwritten.");
                    $results['updated']++;
                } else {
                    $this->line("    [DRY RUN] Would overwrite workflow_json for '{$workflowId}'.");
                }
                continue;
            }

            // ── Insert new workflow ───────────────────────────────────────────
            if ($importing) {
                try {
                    Workflow::create([
                        'name'          => $workflowId,
                        'type'          => $typeValue,
                        'description'   => $wf['description'] ?? "Imported from MCP server ({$displayName}). Review required.",
                        'workflow_json' => $normalizedJson,
                        'output_type'   => $this->inferOutputType($workflowId),
                        'input_types'   => [],
                        'inject_keys'   => [],
                        'is_active'     => false,
                    ]);
                    $this->line("    <info>[IMPORTED]</info> Saved as inactive — set output_type and inject_keys in admin panel.");
                    $results['imported']++;

                    if (! empty($unmapped)) {
                        Log::warning("workflows:sync: imported '{$workflowId}' with unmapped PARAM_* tokens", [
                            'unmapped' => $unmapped,
                        ]);
                    }
                } catch (\Illuminate\Database\QueryException $e) {
                    $this->error("    [ERROR] DB insert failed for '{$workflowId}': " . $e->getMessage());
                    $this->line("    Hint: check the `type` column. Run with --type=<value> to override (default: {$typeValue}).");
                    $results['skipped']++;
                }
            } else {
                $this->line("    [DRY RUN] Would import '{$workflowId}' (type: {$typeValue}, output_type: {$this->inferOutputType($workflowId)}, active: false).");
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->line('');
        $this->info('Summary:');
        $this->line("  Imported : {$results['imported']}");
        $this->line("  Updated  : {$results['updated']}");
        $this->line("  Skipped  : {$results['skipped']}");
        $this->line("  Flagged  : " . count($results['flagged']));

        if (! empty($results['flagged'])) {
            $this->line('');
            $this->warn('Flagged workflows have unmapped PARAM_* tokens that need manual attention:');
            foreach ($results['flagged'] as $f) {
                $this->line("  - {$f['name']}: " . implode(', ', $f['unmapped']));
            }
        }

        if (! $importing) {
            $this->line('');
            $this->line('Run with <comment>--import</comment> to write to the database.');
        } else {
            $this->line('');
            $this->line('Remember: imported workflows are <comment>inactive</comment> by default.');
            $this->line('Set output_type, inject_keys, and input_types in the admin panel, then activate.');
        }

        return self::SUCCESS;
    }

    /**
     * Translate all PARAM_* tokens in a workflow JSON string to {{PLACEHOLDER}} format.
     */
    private function translatePlaceholders(string $json): array
    {
        $unmapped = [];

        preg_match_all('/PARAM_[A-Z0-9_]+/', $json, $matches);
        $found = array_unique($matches[0] ?? []);

        foreach ($found as $token) {
            if (isset(self::PARAM_MAP[$token])) {
                $json = str_replace($token, self::PARAM_MAP[$token], $json);
            } else {
                $unmapped[] = $token;
            }
        }

        return [$json, $unmapped];
    }

    /**
     * Wrap bare numeric {{PLACEHOLDER}} tokens in quotes so the JSON is valid.
     */
    private function normalizePlaceholders(string $json): string
    {
        $numericPlaceholders = [
            'SEED', 'STEPS', 'CFG', 'WIDTH', 'HEIGHT',
            'FRAME_COUNT', 'FPS', 'MOTION_STRENGTH', 'DURATION',
            'SAMPLE_RATE', 'DENOISE', 'LYRICS_STRENGTH',
        ];

        foreach ($numericPlaceholders as $name) {
            $pattern     = '/(?<!")\{\{' . $name . '\}\}(?!")/';
            $replacement = '"{{' . $name . '}}"';
            $json        = preg_replace($pattern, $replacement, $json);
        }

        return $json;
    }

    /**
     * Infer output_type from the workflow ID. Best-effort — admin must confirm.
     */
    private function inferOutputType(string $name): string
    {
        $lower = strtolower($name);

        if (str_contains($lower, 'video') || str_contains($lower, 'animate') || str_contains($lower, 'ltx')) {
            return 'video';
        }
        if (str_contains($lower, 'audio') || str_contains($lower, 'music') || str_contains($lower, 'song')) {
            return 'audio';
        }

        return 'image';
    }
}