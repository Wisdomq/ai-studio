<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * McpService — ComfyUI communication layer.
 *
 * All existing direct-ComfyUI methods are unchanged.
 * MCP sidecar methods are additive and guarded by the COMFYUI_MCP_ENABLED flag.
 *
 * Direct ComfyUI tools (always available):
 *   healthCheck()              → reachable: bool, gpu_vram_free: string
 *   listWorkflows()            → ComfyUI /object_info node types (admin use)
 *   submitJob()                → prompt_id string
 *   checkJobStatus()           → status, queue_position, estimated_wait_seconds
 *   getJobResult()             → output_files[], media_type, storage_path
 *   uploadInputFile()          → comfy_filename
 *   cancelJob()                → bool (calls ComfyUI /queue DELETE directly)
 *   getQueueStatus()           → running: int, pending: int (from /queue directly)
 *   freeVram()                 → freed: bool  (POST /free — unloads models + clears VRAM)
 *
 * MCP sidecar tools (require COMFYUI_MCP_ENABLED=true):
 *   mcpListWorkflows()         → array of workflow descriptors from MCP server
 *   mcpGetWorkflowJson()       → raw JSON string for a workflow (used by workflows:sync)
 *   mcpSubmitJob()             → ['prompt_id' => string, 'asset_id' => string|null]
 *   mcpFetchWorkflowGraph()    → decoded node graph array for live-fetch execution path
 *   mcpPatchAndSubmit()        → prompt_id string (load + patch + submit in one MCP call)
 *   mcpGetWorkflowNodes()      → node map for admin node inspection
 */
class McpService
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $mcpUrl;
    protected bool   $mcpEnabled;

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('comfyui.base_url', 'http://172.16.10.13:8188'), '/');
        $this->clientId   = Str::uuid()->toString();
        $this->mcpUrl     = rtrim(config('services.comfyui_mcp.url', 'http://comfyui-mcp:9000/mcp'), '/');
        $this->mcpEnabled = (bool) config('services.comfyui_mcp.enabled', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 1: health_check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if ComfyUI is reachable and get GPU VRAM info.
     *
     * @return array{reachable: bool, gpu_vram_free: string}
     */
    public function healthCheck(): array
    {
        try {
            $response = $this->get('/system_stats');

            $vram = 'unknown';
            if ($response->successful()) {
                $data = $response->json();
                $free = $data['system']['vram_free'] ?? null;
                if ($free !== null) {
                    $vram = round($free / (1024 ** 3), 2) . ' GB';
                }
            }

            return [
                'reachable'     => $response->successful(),
                'gpu_vram_free' => $vram,
            ];
        } catch (\Exception $e) {
            Log::warning('McpService::healthCheck failed', ['error' => $e->getMessage()]);

            return ['reachable' => false, 'gpu_vram_free' => 'unknown'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 2: list_workflows (ComfyUI /object_info — admin/node discovery)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List node types available in ComfyUI via /object_info.
     * Used by admin sync for node-level discovery — not the same as
     * mcpListWorkflows() which returns file-based workflow descriptors.
     *
     * @param  string|null $filterOutputType
     * @return array<int, array{name: string, node_types: array}>
     */
    public function listWorkflows(?string $filterOutputType = null): array
    {
        $response = $this->get('/object_info');

        if (! $response->successful()) {
            throw new RuntimeException('ComfyUI /object_info request failed: ' . $response->status());
        }

        $objectInfo = $response->json();
        $workflows  = [];

        foreach ($objectInfo as $nodeName => $nodeData) {
            $workflows[] = [
                'name'       => $nodeName,
                'node_types' => array_keys($nodeData['input'] ?? []),
                'category'   => $nodeData['category'] ?? 'unknown',
            ];
        }

        return $workflows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 3: submit_job
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Submit a workflow to ComfyUI for execution.
     *
     * @param  string $workflowJson  Fully injected workflow JSON string
     * @return string                ComfyUI prompt_id (job_id)
     *
     * @throws RuntimeException on submission failure or node errors
     */
    public function submitJob(string $workflowJson): string
    {
        $nodes = json_decode($workflowJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid workflow JSON: ' . json_last_error_msg());
        }

        $payload = [
            'prompt'    => $nodes,
            'client_id' => $this->clientId,
        ];

        $response = $this->post('/prompt', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('ComfyUI /prompt submission failed: ' . $response->body());
        }

        $data = $response->json();

        // node_errors = custom nodes not installed in ComfyUI
        if (! empty($data['node_errors'])) {
            $errors = json_encode($data['node_errors']);
            throw new RuntimeException("ComfyUI node errors (custom nodes may be missing): {$errors}");
        }

        $promptId = $data['prompt_id'] ?? null;

        if (! $promptId) {
            throw new RuntimeException('ComfyUI did not return a prompt_id: ' . json_encode($data));
        }

        return $promptId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 4: check_job_status
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check the status of a submitted ComfyUI job.
     *
     * @param  string $jobId  ComfyUI prompt_id
     * @return array{status: string, queue_position: int|null, estimated_wait_seconds: int|null}
     */
    public function checkJobStatus(string $jobId): array
    {
        $historyResponse = $this->get("/history/{$jobId}");

        if ($historyResponse->successful()) {
            $history = $historyResponse->json();

            if (isset($history[$jobId])) {
                $jobHistory = $history[$jobId];
                $status     = $jobHistory['status'] ?? [];

                if (($status['status_str'] ?? '') === 'error' || ! empty($status['messages'])) {
                    foreach ($status['messages'] ?? [] as $msg) {
                        if (($msg[0] ?? '') === 'execution_error') {
                            return ['status' => 'failed', 'queue_position' => null, 'estimated_wait_seconds' => null];
                        }
                    }
                }

                return ['status' => 'completed', 'queue_position' => null, 'estimated_wait_seconds' => null];
            }
        }

        $queueResponse = $this->get('/queue');

        if ($queueResponse->successful()) {
            $queue   = $queueResponse->json();
            $running = $queue['queue_running'] ?? [];
            $pending = $queue['queue_pending'] ?? [];

            foreach ($running as $runningJob) {
                if (($runningJob[1] ?? '') === $jobId) {
                    return ['status' => 'running', 'queue_position' => 0, 'estimated_wait_seconds' => null];
                }
            }

            foreach ($pending as $pos => $pendingJob) {
                if (($pendingJob[1] ?? '') === $jobId) {
                    return [
                        'status'                 => 'queued',
                        'queue_position'         => $pos + 1,
                        'estimated_wait_seconds' => ($pos + 1) * 30,
                    ];
                }
            }
        }

        return ['status' => 'queued', 'queue_position' => null, 'estimated_wait_seconds' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 5: get_job_result
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve output files from a completed ComfyUI job and save to storage.
     *
     * @param  string $jobId      ComfyUI prompt_id
     * @param  string $mediaType  'image' | 'video' | 'audio'
     * @return array{output_files: array, media_type: string, storage_path: string}
     */
    public function getJobResult(string $jobId, string $mediaType = 'image'): array
    {
        $historyResponse = $this->get("/history/{$jobId}");

        if (! $historyResponse->successful() || ! isset($historyResponse->json()[$jobId])) {
            throw new RuntimeException("Job {$jobId} not found in ComfyUI history");
        }

        $jobHistory  = $historyResponse->json()[$jobId];
        $outputs     = $jobHistory['outputs'] ?? [];
        $outputFiles = [];
        $storagePath = null;

        foreach ($outputs as $nodeId => $nodeOutputs) {
            foreach ($nodeOutputs['images'] ?? [] as $image) {
                $filename    = $image['filename'];
                $subfolder   = $image['subfolder'] ?? '';
                $type        = $image['type'] ?? 'output';
                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'image');
                $outputFiles[] = ['filename' => $filename, 'storage_path' => $storagePath, 'media_type' => 'image'];
            }

            foreach ($nodeOutputs['gifs'] ?? [] as $video) {
                $filename    = $video['filename'];
                $subfolder   = $video['subfolder'] ?? '';
                $type        = $video['type'] ?? 'output';
                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'video');
                $outputFiles[] = ['filename' => $filename, 'storage_path' => $storagePath, 'media_type' => 'video'];
            }

            foreach ($nodeOutputs['audio'] ?? [] as $audio) {
                $filename    = $audio['filename'];
                $subfolder   = $audio['subfolder'] ?? '';
                $type        = $audio['type'] ?? 'output';
                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'audio');
                $outputFiles[] = ['filename' => $filename, 'storage_path' => $storagePath, 'media_type' => 'audio'];
            }
        }

        if (empty($outputFiles)) {
            throw new RuntimeException("No output files found for job {$jobId}");
        }

        $primary = $outputFiles[0];

        return [
            'output_files' => $outputFiles,
            'media_type'   => $primary['media_type'],
            'storage_path' => $primary['storage_path'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 6: upload_input_file
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upload a file from Laravel storage to ComfyUI input directory.
     *
     * @param  string $storagePath  Storage-relative path
     * @param  string $mediaType    'image' | 'video' | 'audio'
     * @return string               ComfyUI-assigned filename
     */
    public function uploadInputFile(string $storagePath, string $mediaType = 'image'): string
    {
        $fullPath = Storage::disk('public')->path($storagePath);

        if (! file_exists($fullPath)) {
            throw new RuntimeException("File not found in storage: {$storagePath}");
        }

        $response = Http::timeout(60)
            ->attach('image', fopen($fullPath, 'r'), basename($storagePath))
            ->post("{$this->baseUrl}/upload/image");

        if (! $response->successful()) {
            throw new RuntimeException('ComfyUI file upload failed: ' . $response->body());
        }

        $data     = $response->json();
        $filename = $data['name'] ?? null;

        if (! $filename) {
            throw new RuntimeException('ComfyUI did not return a filename after upload: ' . json_encode($data));
        }

        return $filename;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 7: cancel_job
    // ─────────────────────────────────────────────────────────────────────────

    public function cancelJob(string $jobId): bool
    {
        try {
            $response = $this->post('/queue', ['delete' => [$jobId]]);

            if (! $response->successful()) {
                Log::warning("McpService::cancelJob: ComfyUI returned {$response->status()} for job {$jobId}");
                return false;
            }

            Log::info("McpService::cancelJob: Cancelled ComfyUI job {$jobId}");
            return true;

        } catch (\Exception $e) {
            Log::error("McpService::cancelJob failed", ['job_id' => $jobId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 8: get_queue_status
    // ─────────────────────────────────────────────────────────────────────────

    public function getQueueStatus(): array
    {
        try {
            $response = $this->get('/queue');

            if (! $response->successful()) {
                return ['running' => 0, 'pending' => 0];
            }

            $queue = $response->json();

            return [
                'running' => count($queue['queue_running'] ?? []),
                'pending' => count($queue['queue_pending'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::warning('McpService::getQueueStatus failed', ['error' => $e->getMessage()]);
            return ['running' => 0, 'pending' => 0];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tool 9: free_vram
    // ─────────────────────────────────────────────────────────────────────────

    public function freeVram(): array
    {
        try {
            $response = $this->post('/free', [
                'unload_models' => true,
                'free_memory'   => true,
            ]);

            if (! $response->successful()) {
                Log::warning('McpService::freeVram: ComfyUI returned non-success', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 300),
                ]);
                return ['freed' => false, 'error' => "HTTP {$response->status()}"];
            }

            Log::info('McpService::freeVram: VRAM freed and models unloaded.');
            return ['freed' => true];

        } catch (\Exception $e) {
            Log::error('McpService::freeVram failed', ['error' => $e->getMessage()]);
            return ['freed' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpListWorkflows
    // ─────────────────────────────────────────────────────────────────────────

    public function mcpListWorkflows(): array
    {
        $this->assertMcpEnabled('mcpListWorkflows');

        $result = $this->mcpCall('list_workflows');

        if (isset($result['_text'])) {
            Log::warning('McpService::mcpListWorkflows: server returned plain text — cannot parse as workflow list.', [
                'text' => substr($result['_text'], 0, 300),
            ]);
            return [];
        }

        $workflows = $result['workflows'] ?? (array_is_list($result) ? $result : []);

        if (empty($workflows)) {
            Log::warning('McpService::mcpListWorkflows: empty workflow list returned.', ['result' => $result]);
            return [];
        }

        return array_map(function (array $wf) {
            return [
                'id'          => $wf['id'] ?? '',
                'name'        => $wf['name'] ?? '',
                'description' => $wf['description'] ?? '',
                'parameters'  => $wf['available_inputs'] ?? $wf['parameters'] ?? [],
            ];
        }, $workflows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpGetWorkflowJson
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve the raw ComfyUI node-graph JSON string for a workflow from the MCP server.
     * Used by the workflows:sync artisan command to import JSON into the DB.
     *
     * @param  string $workflowId  MCP workflow ID (e.g. 'generate_image')
     * @return string|null         Raw JSON string, or null on failure
     */
    public function mcpGetWorkflowJson(string $workflowId): ?string
    {
        $this->assertMcpEnabled('mcpGetWorkflowJson');

        $result = $this->mcpCall('get_workflow_json', ['workflow_id' => $workflowId]);

        if (isset($result['error'])) {
            Log::warning("McpService::mcpGetWorkflowJson: server returned error for '{$workflowId}'", [
                'error' => $result['error'],
            ]);
            return null;
        }

        $json = $result['workflow_json'] ?? null;

        if (! $json || ! is_string($json)) {
            Log::warning("McpService::mcpGetWorkflowJson: no workflow_json in response for '{$workflowId}'", [
                'result' => $result,
            ]);
            return null;
        }

        return $json;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpSubmitJob
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Submit a fully-injected workflow JSON to ComfyUI via the MCP sidecar.
     * Used by the stored-JSON execution path (Workflow::injectPrompt() path).
     * When MCP is disabled, falls back to submitJob() directly.
     *
     * @param  string $resolvedWorkflowJson  Fully injected, valid workflow JSON string
     * @return array{prompt_id: string, asset_id: string|null}
     */
    public function mcpSubmitJob(string $resolvedWorkflowJson): array
    {
        if (! $this->mcpEnabled) {
            $promptId = $this->submitJob($resolvedWorkflowJson);
            return ['prompt_id' => $promptId, 'asset_id' => null];
        }

        $nodes = json_decode($resolvedWorkflowJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid workflow JSON: ' . json_last_error_msg());
        }

        $result = $this->mcpCall('submit_raw_workflow', ['workflow_json' => $nodes]);

        $promptId = $result['prompt_id'] ?? null;
        $assetId  = $result['asset_id']  ?? null;

        if (! $promptId) {
            Log::error('McpService::mcpSubmitJob: no prompt_id in MCP response', ['response' => $result]);
            throw new RuntimeException('MCP server did not return a prompt_id: ' . json_encode($result));
        }

        Log::info("McpService::mcpSubmitJob: job submitted via MCP", [
            'prompt_id' => $promptId,
            'asset_id'  => $assetId,
        ]);

        return ['prompt_id' => $promptId, 'asset_id' => $assetId];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpFetchWorkflowGraph  ← NEW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch and decode the live ComfyUI node graph for a workflow from the MCP server.
     *
     * Used by ExecutePlanJob for the live-fetch execution path where workflow_json
     * is NOT stored in the Laravel database (mcp_workflow_id is set instead).
     * The returned array is passed directly to Workflow::injectPromptIntoGraph()
     * for prompt + file injection before submission.
     *
     * This is identical to mcpGetWorkflowJson() but returns a decoded array
     * rather than a raw JSON string, since the caller needs to operate on the
     * structure directly.
     *
     * @param  string $workflowId  MCP workflow ID (e.g. 'generate_image')
     * @return array|null          Decoded node graph array, or null on failure
     */
    public function mcpFetchWorkflowGraph(string $workflowId): ?array
    {
        $this->assertMcpEnabled('mcpFetchWorkflowGraph');

        $result = $this->mcpCall('get_workflow_json', ['workflow_id' => $workflowId]);

        if (isset($result['error'])) {
            Log::warning("McpService::mcpFetchWorkflowGraph: server returned error for '{$workflowId}'", [
                'error' => $result['error'],
            ]);
            return null;
        }

        $json = $result['workflow_json'] ?? null;

        if (! $json || ! is_string($json)) {
            Log::warning("McpService::mcpFetchWorkflowGraph: no workflow_json in response for '{$workflowId}'", [
                'result' => $result,
            ]);
            return null;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("McpService::mcpFetchWorkflowGraph: MCP returned invalid JSON for '{$workflowId}'", [
                'error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpPatchAndSubmit  ← NEW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load a workflow from the MCP sidecar, apply node-level patches, and
     * submit the modified graph to ComfyUI — all in a single MCP call.
     *
     * This is the most direct MCP-native execution path. It is useful when
     * you want to override specific node inputs (model names, sampler parameters,
     * prompt text, file references) without storing any JSON in Laravel.
     *
     * The patches dict maps string node IDs to input override dicts:
     *
     *   [
     *     '6'  => ['text' => 'a beautiful sunset'],
     *     '3'  => ['seed' => 87634512, 'steps' => 25, 'cfg' => 7.0],
     *     '4'  => ['ckpt_name' => 'wan2.1_t2v_14B_fp8_scaled.safetensors'],
     *     '11' => ['audio' => 'uploaded_sound_abc.wav'],
     *   ]
     *
     * Unknown node IDs are silently ignored by the MCP server (not an error).
     * Use mcpGetWorkflowNodes() first to discover available node IDs.
     *
     * Note: This bypasses Workflow::injectPrompt() entirely — no PHP-side
     * placeholder substitution occurs. The patches must be fully resolved
     * values (actual prompt strings, actual filenames, etc.).
     *
     * @param  string $workflowId  MCP workflow ID (e.g. 'generate_image')
     * @param  array  $patches     Node-level input patches: ['node_id' => ['key' => value]]
     * @return string              ComfyUI prompt_id for status polling
     *
     * @throws RuntimeException on MCP failure or missing prompt_id
     */
    public function mcpPatchAndSubmit(string $workflowId, array $patches): string
    {
        $this->assertMcpEnabled('mcpPatchAndSubmit');

        $result = $this->mcpCall('patch_and_submit_workflow', [
            'workflow_id' => $workflowId,
            'patches'     => empty($patches) ? (object) [] : $patches,
        ]);

        if (isset($result['error'])) {
            throw new RuntimeException(
                "McpService::mcpPatchAndSubmit error for '{$workflowId}': " . $result['error']
            );
        }

        $promptId = $result['prompt_id'] ?? null;

        if (! $promptId) {
            Log::error('McpService::mcpPatchAndSubmit: no prompt_id in response', [
                'workflow_id' => $workflowId,
                'response'    => $result,
            ]);
            throw new RuntimeException(
                "MCP server did not return a prompt_id for workflow '{$workflowId}': " . json_encode($result)
            );
        }

        Log::info("McpService::mcpPatchAndSubmit: job submitted", [
            'workflow_id'   => $workflowId,
            'prompt_id'     => $promptId,
            'nodes_patched' => $result['nodes_patched'] ?? [],
        ]);

        return $promptId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpGetWorkflowNodes  ← NEW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve a simplified node map for a workflow from the MCP sidecar.
     *
     * Returns each node's ID, class_type, and editable (non-linked) inputs.
     * Used by the admin panel to show which nodes and parameters are available
     * to patch before using mcpPatchAndSubmit() or the live-fetch execution path.
     *
     * Linked inputs (wired from another node's output) are excluded because
     * they are resolved by ComfyUI's graph topology at runtime and cannot be
     * overridden as static values.
     *
     * @param  string $workflowId  MCP workflow ID (e.g. 'generate_image')
     * @return array               Node map keyed by node_id, or empty array on failure.
     *                             Shape: ['node_id' => ['class_type' => string, 'inputs' => array]]
     */
    public function mcpGetWorkflowNodes(string $workflowId): array
    {
        $this->assertMcpEnabled('mcpGetWorkflowNodes');

        $result = $this->mcpCall('get_workflow_nodes', ['workflow_id' => $workflowId]);

        if (isset($result['error'])) {
            Log::warning("McpService::mcpGetWorkflowNodes: error for '{$workflowId}'", [
                'error' => $result['error'],
            ]);
            return [];
        }

        return $result['nodes'] ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpFetchWorkflowFromComfyUI  ← NEW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a workflow JSON directly from ComfyUI's API via the MCP sidecar.
     *
     * This fetches the workflow file from ComfyUI server on-demand without
     * needing to copy files to the MCP server's local folder. The workflow
     * is fetched fresh for each execution.
     *
     * @param  string $workflowName  Workflow filename (e.g. 'faceswap.json' or 'faceswap')
     * @return array|null           Decoded workflow JSON array, or null on failure
     */
    public function mcpFetchWorkflowFromComfyUI(string $workflowName): ?array
    {
        $this->assertMcpEnabled('mcpFetchWorkflowFromComfyUI');

        // Ensure .json extension
        $workflowName = rtrim($workflowName, '.');
        if (! str_ends_with(strtolower($workflowName), '.json')) {
            $workflowName .= '.json';
        }

        // Also send the spaces variant — Python tool will try both if the primary name fails.
        // Stored names may have underscores substituted for spaces at import time,
        // but the actual file on ComfyUI may still use spaces.
        $workflowNameSpaces = str_replace('_', ' ', $workflowName);

        $result = $this->mcpCall('get_workflow_from_comfyui', [
            'workflow_name'          => $workflowName,
            'workflow_name_fallback' => $workflowNameSpaces,
        ]);

        if (isset($result['error'])) {
            Log::warning("McpService::mcpFetchWorkflowFromComfyUI: error for '{$workflowName}'", [
                'error' => $result['error'],
            ]);
            return null;
        }

        $json = $result['workflow_json'] ?? null;

        if (! $json || ! is_string($json)) {
            Log::warning("McpService::mcpFetchWorkflowFromComfyUI: no workflow_json in response for '{$workflowName}'", [
                'result' => $result,
            ]);
            return null;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("McpService::mcpFetchWorkflowFromComfyUI: MCP returned invalid JSON for '{$workflowName}'", [
                'error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Direct ComfyUI fetch (no MCP)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a workflow JSON directly from ComfyUI's userdata API.
     *
     * This fetches the workflow file from ComfyUI server on-demand without
     * using the MCP sidecar. Use this when workflows are stored on ComfyUI
     * and you don't want to copy them anywhere.
     *
     * @param  string $workflowName  Workflow filename (e.g. 'faceswap.json')
     * @return array|null           Decoded workflow JSON array, or null on failure
     */
    public function fetchComfyuiWorkflow(string $workflowName): ?array
    {
        // Clean up the workflow name - remove leading slashes and ensure proper path
        $workflowName = ltrim($workflowName, '/');

        // If it doesn't end with .json, add it
        if (! str_ends_with(strtolower($workflowName), '.json')) {
            $workflowName .= '.json';
        }

        Log::info("McpService::fetchComfyuiWorkflow: fetching from {$workflowName}");

        try {
            $response = $this->get("/api/workflow/" . urlencode($workflowName));

            if ($response->status() === 404) {
                // Try without folder prefix (some ComfyUI setups)
                $basename = basename($workflowName);
                Log::warning("McpService::fetchComfyuiWorkflow: workflow not found at {$workflowName}, trying {$basename}");
                $response = $this->get("/api/workflow/" . urlencode($basename));

                if ($response->status() === 404) {
                    Log::warning("McpService::fetchComfyuiWorkflow: workflow not found: {$workflowName}");
                    return null;
                }
            }

            if (! $response->successful()) {
                Log::warning("McpService::fetchComfyuiWorkflow: ComfyUI returned {$response->status()} for {$workflowName}");
                return null;
            }

            $data = $response->json();

            Log::info("McpService::fetchComfyuiWorkflow: ComfyUI RAW RESPONSE", [
                'status'       => $response->status(),
                'body_preview' => substr($response->body(), 0, 300),
            ]);

            // ❗ Detect wrong response (directory listing instead of workflow JSON)
            if (is_array($data) && isset($data[0]) && is_string($data[0])) {
                Log::error("McpService::fetchComfyuiWorkflow: Received file list instead of workflow JSON", [
                    'workflow'         => $workflowName,
                    'response_preview' => array_slice($data, 0, 5),
                ]);
                return null;
            }

            // Handle nested workflow format (ComfyUI sometimes wraps it)
            if (is_array($data) && isset($data['workflow'])) {
                $data = $data['workflow'];
            }

            // Convert from node format to API format if needed
            if (is_array($data) && isset($data['nodes']) && ! $this->hasApiFormat($data)) {
                $data = $this->convertNodesToApiFormat($data);
            }

            return $data;

        } catch (\Exception $e) {
            Log::error("McpService::fetchComfyuiWorkflow failed for {$workflowName}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if workflow data is in ComfyUI API format (has class_type keys).
     */
    protected function hasApiFormat(array $data): bool
    {
        foreach ($data as $node) {
            if (is_array($node) && isset($node['class_type'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert ComfyUI node format to API format.
     */
    protected function convertNodesToApiFormat(array $workflowData): array
    {
        $apiNodes = [];

        foreach ($workflowData['nodes'] ?? [] as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            $inputs = [];

            foreach ($node['inputs'] ?? [] as $input) {
                if (isset($input['link'])) {
                    continue; // Skip linked inputs
                }
                $inputs[$input['name']] = $input['widget']['value'] ?? null;
            }

            $apiNodes[$nodeId] = [
                'inputs'     => $inputs,
                'class_type' => $node['type'] ?? 'unknown',
            ];
        }

        return $apiNodes;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Transport (private)
    // ─────────────────────────────────────────────────────────────────────────

    private function mcpCall(string $tool, array $params = []): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        ];

        $arguments = empty($params) ? (object) [] : $params;

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => Str::uuid()->toString(),
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $tool,
                'arguments' => $arguments,
            ],
        ];

        try {
            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post($this->mcpUrl, $payload);
        } catch (\Exception $e) {
            throw new RuntimeException("MCP server unreachable at {$this->mcpUrl}: " . $e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "MCP server returned HTTP {$response->status()} for tool '{$tool}': " . $response->body()
            );
        }

        $body = $response->body();
        $data = null;

        if (str_contains($body, 'data:')) {
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data:')) {
                    $json    = trim(substr($line, 5));
                    $decoded = json_decode($json, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            }
        } else {
            $data = $response->json();
        }

        if ($data === null) {
            throw new RuntimeException(
                "MCP server returned unparseable response for tool '{$tool}': " . substr($body, 0, 500)
            );
        }

        if (isset($data['error'])) {
            throw new RuntimeException(
                "MCP tool '{$tool}' error: " . ($data['error']['message'] ?? json_encode($data['error']))
            );
        }

        $result = $data['result'] ?? $data;

        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $block) {
                if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $inner = json_decode($block['text'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($inner)) {
                        return $inner;
                    }
                    return ['_text' => $block['text']];
                }
            }
        }

        return $result;
    }

    private function assertMcpEnabled(string $method): void
    {
        if (! $this->mcpEnabled) {
            throw new RuntimeException(
                "McpService::{$method} requires COMFYUI_MCP_ENABLED=true in .env"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    protected function get(string $endpoint): Response
    {
        return Http::timeout(30)->get($this->baseUrl . $endpoint);
    }

    protected function post(string $endpoint, array $data): Response
    {
        return Http::timeout(30)->post($this->baseUrl . $endpoint, $data);
    }

    protected function downloadOutputFile(string $filename, string $subfolder, string $type): string
    {
        $url = "{$this->baseUrl}/view?" . http_build_query([
            'filename'  => $filename,
            'subfolder' => $subfolder,
            'type'      => $type,
        ]);

        $response = Http::timeout(120)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download output file {$filename}: " . $response->status());
        }

        return $response->body();
    }

    protected function saveToStorage(string $originalFilename, string $content, string $mediaType): string
    {
        $storagePath = 'comfyui-outputs/' . $originalFilename;
        Storage::disk('public')->put($storagePath, $content);
        return $storagePath;
    }
}