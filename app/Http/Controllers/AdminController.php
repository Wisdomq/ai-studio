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
        $this->comfyUrl = rtrim(config('comfyui.base_url', 'http://172.16.10.13:8188'), '/');
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
     * input_types, inject_keys, mcp_workflow_id. Does NOT update workflow_json.
     */
    public function updateWorkflow(Request $request, Workflow $workflow): JsonResponse
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'required|string',
            'type'             => 'required|string',
            'output_type'      => 'required|string',
            'input_types'      => 'nullable|array',
            'inject_keys'      => 'nullable|array',
            'mcp_workflow_id'  => 'nullable|string|max:255',
        ]);

        $workflow->update([
            'name'            => $request->name,
            'description'     => $request->description,
            'type'            => $request->type,
            'output_type'     => $request->output_type,
            'input_types'     => $request->input_types ?? [],
            'inject_keys'     => $request->inject_keys ?? [],
            'mcp_workflow_id' => $request->mcp_workflow_id ?: null,
        ]);

        Log::info('AdminController: Workflow updated', [
            'workflow_id'     => $workflow->id,
            'name'            => $workflow->name,
            'mcp_workflow_id' => $workflow->mcp_workflow_id,
        ]);

        return response()->json([
            'success'  => true,
            'workflow' => [
                'id'             => $workflow->id,
                'name'           => $workflow->name,
                'description'    => $workflow->description,
                'type'           => $workflow->type,
                'output_type'    => $workflow->output_type,
                'input_types'    => $workflow->input_types,
                'inject_keys'    => $workflow->inject_keys,
                'mcp_workflow_id'=> $workflow->mcp_workflow_id,
            ],
        ]);
    }

    /**
     * DELETE /admin/workflows/{workflow}
     */
    public function deleteWorkflow(Workflow $workflow): JsonResponse
    {
        $name = $workflow->name;

        $workflow->planSteps()->delete();
        $workflow->delete();

        Log::info('AdminController: Workflow deleted', [
            'workflow_id' => $workflow->id,
            'name'        => $name,
        ]);

        return response()->json(['success' => true]);
    }

    // ── MCP Node Inspection ───────────────────────────────────────────────────

    /**
     * GET /admin/workflows/{workflow}/preview-live
     *
     * Fetch the live node map for this workflow from the MCP sidecar and
     * return it as JSON for admin inspection.
     *
     * Only works when:
     *   - COMFYUI_MCP_ENABLED=true in .env
     *   - The workflow has a mcp_workflow_id set
     *
     * The returned node map lists each node's ID, class_type, and editable
     * (non-linked) inputs — useful for knowing what to patch and what
     * placeholder tokens or file inputs each node expects.
     */
    public function previewLiveWorkflow(McpService $mcp, Workflow $workflow): JsonResponse
    {
        if (! config('services.comfyui_mcp.enabled', false)) {
            return response()->json([
                'success' => false,
                'error'   => 'MCP is not enabled. Set COMFYUI_MCP_ENABLED=true in your .env file.',
            ], 422);
        }

        if (empty($workflow->mcp_workflow_id)) {
            return response()->json([
                'success' => false,
                'error'   => 'This workflow does not have an MCP Workflow ID set. Edit the workflow and add one first.',
            ], 422);
        }

        try {
            $nodes = $mcp->mcpGetWorkflowNodes($workflow->mcp_workflow_id);

            if (empty($nodes)) {
                return response()->json([
                    'success' => false,
                    'error'   => "MCP returned an empty node map for '{$workflow->mcp_workflow_id}'. Check the workflow file exists in the sidecar's workflows/ directory.",
                ], 404);
            }

            Log::info('AdminController: previewLiveWorkflow fetched node map', [
                'workflow_id'     => $workflow->id,
                'mcp_workflow_id' => $workflow->mcp_workflow_id,
                'node_count'      => count($nodes),
            ]);

            return response()->json([
                'success'         => true,
                'workflow_name'   => $workflow->name,
                'mcp_workflow_id' => $workflow->mcp_workflow_id,
                'node_count'      => count($nodes),
                'nodes'           => $nodes,
            ]);

        } catch (\Throwable $e) {
            Log::warning('AdminController: previewLiveWorkflow failed', [
                'workflow_id'     => $workflow->id,
                'mcp_workflow_id' => $workflow->mcp_workflow_id,
                'error'           => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── ComfyUI Import ────────────────────────────────────────────────────────

    /**
     * GET /admin/workflows/comfy-list
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
                        'path'  => $f,
                        'name'  => pathinfo(basename($f), PATHINFO_FILENAME),
                        'label' => str_replace(['_', '-'], ' ', pathinfo(basename($f), PATHINFO_FILENAME)),
                    ])
                    ->values()
                    ->all();

                return response()->json([
                    'success'   => true,
                    'workflows' => $workflows,
                    'raw_paths' => $files,
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
     *
     * When skip_json=true, stores only metadata with comfy_workflow_name set.
     * The workflow JSON is fetched from ComfyUI on-demand at execution time.
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
            'skip_json'   => 'nullable|boolean',
        ]);

        $skipJson = $request->boolean('skip_json', false);

        // When skip_json=true, don't fetch or store the workflow JSON
        if ($skipJson) {
            $workflow = Workflow::updateOrCreate(
                ['comfy_workflow_name' => $request->path],
                [
                    'name'                => $request->name,
                    'type'                => $request->type,
                    'output_type'         => $request->output_type,
                    'description'         => $request->description,
                    'workflow_json'       => null, // No JSON stored
                    'input_types'         => $request->input_types ?? [],
                    'inject_keys'         => $request->inject_keys ?? [],
                    'is_active'           => false,
                    'discovered_at'       => now(),
                    'comfy_workflow_name' => $request->path,
                ]
            );

            Log::info('AdminController: Imported workflow from ComfyUI (ComfyUI-direct, no JSON stored)', [
                'workflow_id'    => $workflow->id,
                'name'           => $workflow->name,
                'comfy_workflow_name' => $request->path,
            ]);

            return response()->json([
                'success'     => true,
                'workflow_id' => $workflow->id,
                'name'        => $workflow->name,
                'mode'        => 'comfyui_direct',
                'message'     => "Workflow registered in ComfyUI-direct mode. JSON will be fetched from ComfyUI at execution time.",
            ]);
        }

        // Original behavior: fetch and store JSON
        try {
            $response = Http::timeout(15)
                ->get("{$this->comfyUrl}/api/userdata", [
                    'dir'  => 'workflows',
                    'file' => $request->path,
                ]);

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

            $apiJson = $this->convertToApiFormat($workflowData);

            if (! $apiJson) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Could not extract API-format nodes from this workflow. Try exporting it manually using "Save (API Format)" in ComfyUI.',
                ], 422);
            }

            $decoded  = json_decode($apiJson, true);
            $hasNodes = is_array($decoded) && collect($decoded)->contains(fn($n) => isset($n['class_type']));

            if (! $hasNodes) {
                return response()->json([
                    'success' => false,
                    'error'   => 'This workflow does not appear to contain ComfyUI nodes. It may be in an unsupported format.',
                ], 422);
            }

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
                    'is_active'           => false,
                    'discovered_at'       => now(),
                    'comfy_workflow_name' => $request->path,
                ]
            );

            Log::info('AdminController: Imported workflow from ComfyUI', [
                'workflow_id' => $workflow->id,
                'name'        => $workflow->name,
                'comfy_path'  => $request->path,
                'node_count'  => count($decoded),
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
     */
    public function importJsonDirect(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_json'   => 'required|string',
            'name'            => 'required|string|max:255',
            'type'            => 'required|string',
            'output_type'     => 'required|string',
            'description'     => 'required|string',
            'input_types'     => 'nullable|array',
            'inject_keys'     => 'nullable|array',
            'mcp_workflow_id' => 'nullable|string|max:255',
        ]);

        $json = trim($request->workflow_json);
        $json = $this->normalizePlaceholders($json);

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid JSON: ' . json_last_error_msg(),
            ], 422);
        }

        $hasNodes = is_array($decoded) && collect($decoded)->contains(fn($n) => isset($n['class_type']));
        if (! $hasNodes) {
            return response()->json([
                'success' => false,
                'error'   => 'This does not appear to be ComfyUI API-format JSON. Make sure you use "Save (API Format)" when exporting from ComfyUI.',
            ], 422);
        }

        $workflow = Workflow::create([
            'name'            => $request->name,
            'type'            => $request->type,
            'output_type'     => $request->output_type,
            'description'     => $request->description,
            'workflow_json'   => $json,
            'input_types'     => $request->input_types ?? [],
            'inject_keys'     => $request->inject_keys ?? [],
            'mcp_workflow_id' => $request->mcp_workflow_id ?: null,
            'is_active'       => false,
            'discovered_at'   => now(),
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

    protected function normalizePlaceholders(string $json): string
    {
        $json = preg_replace('/@(\{\{[A-Z_]+\}\})/', '$1', $json);

        $placeholders = [
            '{{PROMPT}}', '{{POSITIVE_PROMPT}}', '{{NEGATIVE_PROMPT}}',
            '{{SEED}}', '{{STEPS}}', '{{CFG}}', '{{WIDTH}}', '{{HEIGHT}}',
            '{{FRAME_COUNT}}', '{{FPS}}', '{{MOTION_STRENGTH}}', '{{DURATION}}',
            '{{SAMPLE_RATE}}', '{{DENOISE}}',
            '{{INPUT_IMAGE}}', '{{INPUT_VIDEO}}', '{{INPUT_AUDIO}}',
        ];

        foreach ($placeholders as $placeholder) {
            $json = preg_replace_callback(
                '/(?<!")([:])\\s*' . preg_quote($placeholder, '/') . '\\s*([,\\}])/i',
                function ($matches) use ($placeholder) {
                    return $matches[1] . ' "' . $placeholder . '" ' . $matches[2];
                },
                $json
            );
        }

        return $json;
    }

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

    protected function convertToApiFormat(array $workflowData): ?string
    {
        $firstVal = reset($workflowData);
        if (is_array($firstVal) && isset($firstVal['class_type'])) {
            return json_encode($workflowData);
        }

        if (isset($workflowData['nodes']) && is_array($workflowData['nodes'])) {
            $apiNodes = [];
            foreach ($workflowData['nodes'] as $node) {
                $nodeId = (string) $node['id'];
                $inputs = [];

                foreach ($node['inputs'] ?? [] as $input) {
                    if (isset($input['link'])) {
                        continue;
                    }
                    if (isset($input['widget']['value'])) {
                        $inputs[$input['name']] = $input['widget']['value'];
                    }
                }

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

        if (isset($workflowData['workflow'])) {
            return $this->convertToApiFormat($workflowData['workflow']);
        }

        return null;
    }

    protected function fallbackListWorkflows(): JsonResponse
    {
        return response()->json([
            'success'   => false,
            'error'     => 'ComfyUI /api/userdata endpoint not available. Use the "Paste JSON" import method instead.',
            'workflows' => [],
        ]);
    }
}