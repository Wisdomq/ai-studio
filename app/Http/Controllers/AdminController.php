<?php

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Services\McpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    protected string $comfyUrl;

    public function __construct()
    {
        $this->comfyUrl = rtrim(config('comfyui.base_url', 'http://172.16.10.11:8188'), '/');
    }

    // ── Views ─────────────────────────────────────────────────────────────────

    /**
     * GET /admin/workflows
     */
    public function workflows(): \Illuminate\View\View
    {
        $workflows = Workflow::orderBy('output_type')->orderBy('name')->get();
        return view('admin.workflows', compact('workflows'));
    }

    // ── Workflow CRUD ─────────────────────────────────────────────────────────

    /**
     * PATCH /admin/workflows/{workflow}/toggle
     */
    public function toggleWorkflow(Workflow $workflow): JsonResponse
    {
        $workflow->update(['is_active' => ! $workflow->is_active]);
        return response()->json(['success' => true, 'is_active' => $workflow->is_active]);
    }

    /**
     * PATCH /admin/workflows/{workflow}/set-default
     */
    public function setDefault(Workflow $workflow): JsonResponse
    {
        Workflow::where('output_type', $workflow->output_type)
            ->update(['default_for_type' => false]);
        $workflow->update(['default_for_type' => true]);

        return response()->json([
            'success'          => true,
            'default_for_type' => true,
            'output_type'      => $workflow->output_type,
        ]);
    }

    /**
     * PATCH /admin/workflows/{workflow}
     * Update workflow metadata — name, description, type, output_type,
     * input_types, inject_keys. Does NOT update workflow_json.
     */
    public function updateWorkflow(Request $request, Workflow $workflow): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'required|string',
            'type'        => 'required|string',
            'output_type' => 'required|string',
            'input_types' => 'nullable|array',
            'inject_keys' => 'nullable|array',
        ]);

        $workflow->update([
            'name'        => $request->name,
            'description' => $request->description,
            'type'        => $request->type,
            'output_type' => $request->output_type,
            'input_types' => $request->input_types ?? [],
            'inject_keys' => $request->inject_keys ?? [],
        ]);

        Log::info('AdminController: Workflow updated', [
            'workflow_id' => $workflow->id,
            'name'        => $workflow->name,
        ]);

        return response()->json([
            'success'  => true,
            'workflow' => [
                'id'          => $workflow->id,
                'name'        => $workflow->name,
                'description' => $workflow->description,
                'type'        => $workflow->type,
                'output_type' => $workflow->output_type,
                'input_types' => $workflow->input_types,
                'inject_keys' => $workflow->inject_keys,
            ],
        ]);
    }

    /**
     * DELETE /admin/workflows/{workflow}
     * Permanently delete a workflow record.
     */
    public function deleteWorkflow(Workflow $workflow): JsonResponse
    {
        $name = $workflow->name;
        $workflow->delete();

        Log::info('AdminController: Workflow deleted', [
            'workflow_id' => $workflow->id,
            'name'        => $name,
        ]);

        return response()->json(['success' => true]);
    }

    // ── ComfyUI Import ────────────────────────────────────────────────────────

    /**
     * GET /admin/workflows/comfy-list
     * Fetch all saved workflows from ComfyUI server.
     * Returns a list for the admin to browse and pick from.
     */
    public function listComfyWorkflows(): JsonResponse
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->comfyUrl}/api/userdata", [
                    'dir'       => 'workflows',
                    'recurse'   => true,
                    'split'     => false,
                    'full_info' => false,
                ]);

            if ($response->successful()) {
                $files = $response->json();

                $workflows = collect($files)
                    ->filter(fn($f) => str_ends_with($f, '.json'))
                    ->map(fn($f) => [
                        'path'  => $f,  // keep exactly as returned — used verbatim in import
                        'name'  => pathinfo(basename($f), PATHINFO_FILENAME),
                        'label' => str_replace(['_', '-'], ' ', pathinfo(basename($f), PATHINFO_FILENAME)),
                    ])
                    ->values()
                    ->all();

                return response()->json([
                    'success'   => true,
                    'workflows' => $workflows,
                    'raw_paths' => $files, // debug
                ]);
            }

            return $this->fallbackListWorkflows();

        } catch (\Throwable $e) {
            Log::warning('AdminController: Failed to list ComfyUI workflows', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/workflows/comfy-import
     * Fetch a specific workflow JSON from ComfyUI and save it to the DB.
     *
     * Body:
     *   path        — the workflow file path returned by comfy-list
     *   name        — display name for this workflow
     *   type        — image|video|audio|image_to_video|video_to_video|avatar_video
     *   output_type — image|video|audio
     *   description — what this workflow does
     *   input_types — [] or ["image","audio"] etc.
     *   inject_keys — {} or {"image":"{{INPUT_IMAGE}}"} etc.
     */
    public function importComfyWorkflow(Request $request): JsonResponse
    {
        $request->validate([
            'path'        => 'required|string',
            'name'        => 'required|string|max:255',
            'type'        => 'required|string',
            'output_type' => 'required|string',
            'description' => 'required|string',
            'input_types' => 'nullable|array',
            'inject_keys' => 'nullable|array',
        ]);

        try {
            // Fetch the workflow JSON from ComfyUI
            // The path from listing already includes 'workflows/' prefix
            $encodedPath = rawurlencode($request->path);
            $response = Http::timeout(15)
                ->get("{$this->comfyUrl}/api/userdata/{$encodedPath}");

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'error'   => "ComfyUI returned HTTP {$response->status()} for workflow file.",
                ], 422);
            }

            $workflowData = $response->json();

            if (! $workflowData) {
                return response()->json([
                    'success' => false,
                    'error'   => 'ComfyUI returned empty or invalid JSON for this workflow.',
                ], 422);
            }

            // ComfyUI saves workflows in "graph format" — we need API format.
            // The /api/userdata endpoint returns the full workflow object.
            // We need to extract just the nodes portion for API submission.
            $apiJson = $this->convertToApiFormat($workflowData);

            if (! $apiJson) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Could not extract API-format nodes from this workflow. Try exporting it manually using "Save (API Format)" in ComfyUI.',
                ], 422);
            }

            // Validate it's real ComfyUI JSON
            $decoded = json_decode($apiJson, true);
            $hasNodes = is_array($decoded) && collect($decoded)->contains(fn($n) => isset($n['class_type']));

            if (! $hasNodes) {
                return response()->json([
                    'success' => false,
                    'error'   => 'This workflow does not appear to contain ComfyUI nodes. It may be in an unsupported format.',
                ], 422);
            }

            // Save to DB
            $workflow = Workflow::updateOrCreate(
                ['comfy_workflow_name' => $request->path],
                [
                    'name'                => $request->name,
                    'type'                => $request->type,
                    'output_type'         => $request->output_type,
                    'description'         => $request->description,
                    'workflow_json'       => $apiJson,
                    'input_types'         => $request->input_types ?? [],
                    'inject_keys'         => $request->inject_keys ?? [],
                    'is_active'           => false, // Admin must enable after reviewing
                    'discovered_at'       => now(),
                    'comfy_workflow_name' => $request->path,
                ]
            );

            Log::info('AdminController: Imported workflow from ComfyUI', [
                'workflow_id'   => $workflow->id,
                'name'          => $workflow->name,
                'comfy_path'    => $request->path,
                'node_count'    => count($decoded),
            ]);

            return response()->json([
                'success'     => true,
                'workflow_id' => $workflow->id,
                'name'        => $workflow->name,
                'node_count'  => count($decoded),
                'message'     => "Workflow imported successfully with {$workflow->id} nodes. Enable it in the admin panel when ready.",
            ]);

        } catch (\Throwable $e) {
            Log::error('AdminController: Import failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/workflows/comfy-import-json
     * Direct JSON paste import — user pastes API-format JSON into a textarea.
     * This is the most reliable import method since it bypasses format conversion.
     */
    public function importJsonDirect(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_json' => 'required|string',
            'name'          => 'required|string|max:255',
            'type'          => 'required|string',
            'output_type'   => 'required|string',
            'description'   => 'required|string',
            'input_types'   => 'nullable|array',
            'inject_keys'   => 'nullable|array',
        ]);

        $json = trim($request->workflow_json);

        // Normalize placeholders: convert unquoted {{PLACEHOLDER}} to quoted "{{PLACEHOLDER}}"
        // This makes the JSON valid for database storage while preserving the placeholders
        $json = $this->normalizePlaceholders($json);

        // Validate the normalized JSON
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid JSON: ' . json_last_error_msg(),
            ], 422);
        }

        // Check it has ComfyUI nodes
        $hasNodes = is_array($decoded) && collect($decoded)->contains(fn($n) => isset($n['class_type']));
        if (! $hasNodes) {
            return response()->json([
                'success' => false,
                'error'   => 'This does not appear to be ComfyUI API-format JSON. Make sure you use "Save (API Format)" when exporting from ComfyUI.',
            ], 422);
        }

        $workflow = Workflow::create([
            'name'          => $request->name,
            'type'          => $request->type,
            'output_type'   => $request->output_type,
            'description'   => $request->description,
            'workflow_json' => $json,  // original — placeholders preserved
            'input_types'   => $request->input_types ?? [],
            'inject_keys'   => $request->inject_keys ?? [],
            'is_active'     => false,
            'discovered_at' => now(),
        ]);

        return response()->json([
            'success'     => true,
            'workflow_id' => $workflow->id,
            'node_count'  => count($decoded),
            'message'     => "Workflow '{$workflow->name}' imported with " . count($decoded) . " nodes. Enable it when ready.",
        ]);
    }

    /**
     * POST /admin/workflows/sync
     * Legacy MCP sync (kept for compatibility).
     */
    public function syncWorkflows(McpService $mcp): JsonResponse
    {
        try {
            $discovered = $mcp->listWorkflows();
            $upserted   = 0;

            foreach ($discovered as $workflowData) {
                Workflow::updateOrCreate(
                    ['comfy_workflow_name' => $workflowData['name']],
                    [
                        'name'                => $workflowData['name'],
                        'description'         => $workflowData['name'] . ' (discovered from ComfyUI)',
                        'type'                => 'image',
                        'output_type'         => 'image',
                        'workflow_json'       => '{}',
                        'is_active'           => false,
                        'discovered_at'       => now(),
                    ]
                );
                $upserted++;
            }

            return response()->json([
                'success'  => true,
                'message'  => "Synced {$upserted} workflow(s) from ComfyUI.",
                'upserted' => $upserted,
            ]);

        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Normalize placeholders in workflow JSON: convert unquoted {{PLACEHOLDER}}
     * to quoted "{{PLACEHOLDER}}" so the JSON is valid for database storage.
     *
     * Unquoted placeholders like:
     *   "seed": {{SEED}}
     * become:
     *   "seed": "{{SEED}}"
     *
     * This allows the JSON to be stored in the database while still being
     * replaceable at runtime by injectPrompt().
     */
    protected function normalizePlaceholders(string $json): string
    {
        // Strip any Blade-escape @ prefix the user may have typed (e.g. @{{SEED}} → {{SEED}})
        // This keeps the rest of the logic uniform.
        $json = preg_replace('/@(\{\{[A-Z_]+\}\})/', '$1', $json);

        $placeholders = [
            '{{PROMPT}}', '{{POSITIVE_PROMPT}}', '{{NEGATIVE_PROMPT}}',
            '{{SEED}}', '{{STEPS}}', '{{CFG}}', '{{WIDTH}}', '{{HEIGHT}}',
            '{{FRAME_COUNT}}', '{{FPS}}', '{{MOTION_STRENGTH}}', '{{DURATION}}',
            '{{SAMPLE_RATE}}', '{{DENOISE}}',
            '{{INPUT_IMAGE}}', '{{INPUT_VIDEO}}', '{{INPUT_AUDIO}}',
        ];

        foreach ($placeholders as $placeholder) {
            // Match unquoted placeholder: colon + whitespace + placeholder + comma/brace
            // Must NOT be already quoted (no quote before colon, and placeholder not wrapped)
            $json = preg_replace_callback(
                '/(?<!")(:)\s*' . preg_quote($placeholder, '/') . '\s*([,\}])/i',
                function ($matches) use ($placeholder) {
                    return $matches[1] . ' "' . $placeholder . '" ' . $matches[2];
                },
                $json
            );
        }

        return $json;
    }

    /**
     * Replace numeric injectPrompt placeholders with valid dummy values so that
     * json_decode() can validate the overall structure without choking on bare
     * tokens like {{SEED}} that are not valid JSON number literals.
     *
     * Only numeric placeholders need this treatment — string placeholders
     * ({{POSITIVE_PROMPT}}, {{NEGATIVE_PROMPT}}, {{INPUT_IMAGE}}, etc.) sit
     * inside JSON quoted strings already and are valid as-is.
     */
    protected function substituteNumericPlaceholders(string $json): string
    {
        $numericPlaceholders = [
            '{{SEED}}'            => '1',
            '{{STEPS}}'           => '20',
            '{{CFG}}'             => '7',
            '{{WIDTH}}'           => '512',
            '{{HEIGHT}}'          => '512',
            '{{FRAME_COUNT}}'     => '16',
            '{{FPS}}'             => '8',
            '{{MOTION_STRENGTH}}' => '127',
            '{{DURATION}}'        => '10',
            '{{SAMPLE_RATE}}'     => '44100',
            '{{DENOISE}}'         => '1',
        ];

        return str_replace(
            array_keys($numericPlaceholders),
            array_values($numericPlaceholders),
            $json
        );
    }

    /**
     * ComfyUI saves workflows in "graph format" with extra metadata.
     * API format is just the nodes object keyed by node ID.
     * Try to extract the nodes from the graph format.
     */
    protected function convertToApiFormat(array $workflowData): ?string
    {
        // Already in API format (keys are numeric node IDs with class_type)
        $firstVal = reset($workflowData);
        if (is_array($firstVal) && isset($firstVal['class_type'])) {
            return json_encode($workflowData);
        }

        // Graph format has a 'nodes' array
        if (isset($workflowData['nodes']) && is_array($workflowData['nodes'])) {
            $apiNodes = [];
            foreach ($workflowData['nodes'] as $node) {
                $nodeId = (string) $node['id'];
                $inputs = [];

                // Extract widget values into inputs
                foreach ($node['inputs'] ?? [] as $input) {
                    if (isset($input['link'])) {
                        // Linked input — we can't easily resolve this without the link table
                        continue;
                    }
                    if (isset($input['widget']['value'])) {
                        $inputs[$input['name']] = $input['widget']['value'];
                    }
                }

                // Widget values are in a separate array in some formats
                $widgetValues = $node['widgets_values'] ?? [];
                $widgetIdx    = 0;
                foreach ($node['inputs'] ?? [] as $input) {
                    if (! isset($input['link']) && isset($widgetValues[$widgetIdx])) {
                        $inputs[$input['name']] = $widgetValues[$widgetIdx];
                        $widgetIdx++;
                    }
                }

                $apiNodes[$nodeId] = [
                    'inputs'     => $inputs,
                    'class_type' => $node['type'],
                ];
            }

            if (! empty($apiNodes)) {
                return json_encode($apiNodes);
            }
        }

        // Extra format: workflow is nested under 'workflow' key
        if (isset($workflowData['workflow'])) {
            return $this->convertToApiFormat($workflowData['workflow']);
        }

        return null;
    }

    /**
     * Fallback: list node types as a proxy for available workflows
     * when /api/userdata is not available.
     */
    protected function fallbackListWorkflows(): JsonResponse
    {
        return response()->json([
            'success'   => false,
            'error'     => 'ComfyUI /api/userdata endpoint not available. Use the "Paste JSON" import method instead.',
            'workflows' => [],
        ]);
    }
}