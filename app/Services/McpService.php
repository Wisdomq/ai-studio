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
 *   healthCheck()          → reachable: bool, gpu_vram_free: string
 *   listWorkflows()        → ComfyUI /object_info node types (admin use)
 *   submitJob()            → prompt_id string
 *   checkJobStatus()       → status, queue_position, estimated_wait_seconds
 *   getJobResult()         → output_files[], media_type, storage_path
 *   uploadInputFile()      → comfy_filename
 *   cancelJob()            → bool (calls ComfyUI /queue DELETE directly)
 *   getQueueStatus()       → running: int, pending: int (from /queue directly)
 *   freeVram()             → freed: bool  (POST /free — unloads models + clears VRAM)
 *
 * MCP sidecar tools (require COMFYUI_MCP_ENABLED=true):
 *   mcpListWorkflows()     → array of workflow descriptors from MCP server
 *   mcpSubmitJob()         → ['prompt_id' => string, 'asset_id' => string|null]
 *   mcpPollUntilComplete() → not used — ExecutePlanJob::pollUntilComplete() handles this
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
     * Jobs appear in /history/{id} only after completion.
     * While running, check /queue for position.
     *
     * @param  string $jobId  ComfyUI prompt_id
     * @return array{status: string, queue_position: int|null, estimated_wait_seconds: int|null}
     *               status: 'queued' | 'running' | 'completed' | 'failed'
     */
    public function checkJobStatus(string $jobId): array
    {
        // Check if job is already in history (completed)
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

        // Job not in history yet — check queue
        $queueResponse = $this->get('/queue');

        if ($queueResponse->successful()) {
            $queue   = $queueResponse->json();
            $running = $queue['queue_running'] ?? [];
            $pending = $queue['queue_pending'] ?? [];

            // Check if actively running
            foreach ($running as $runningJob) {
                if (($runningJob[1] ?? '') === $jobId) {
                    return ['status' => 'running', 'queue_position' => 0, 'estimated_wait_seconds' => null];
                }
            }

            // Check queue position
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

        // Not found anywhere — may have just completed
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
     *
     * @throws RuntimeException if job not found or download fails
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
            // Images
            foreach ($nodeOutputs['images'] ?? [] as $image) {
                $filename    = $image['filename'];
                $subfolder   = $image['subfolder'] ?? '';
                $type        = $image['type'] ?? 'output';
                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'image');
                $outputFiles[] = ['filename' => $filename, 'storage_path' => $storagePath, 'media_type' => 'image'];
            }

            // Videos / GIFs
            foreach ($nodeOutputs['gifs'] ?? [] as $video) {
                $filename    = $video['filename'];
                $subfolder   = $video['subfolder'] ?? '';
                $type        = $video['type'] ?? 'output';
                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'video');
                $outputFiles[] = ['filename' => $filename, 'storage_path' => $storagePath, 'media_type' => 'video'];
            }

            // Audio files
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
     * Despite the endpoint name /upload/image, ComfyUI accepts all media types.
     * ComfyUI may rename the file — always use the returned filename.
     *
     * @param  string $storagePath  Storage-relative path (e.g. comfyui-outputs/foo.png)
     * @param  string $mediaType    'image' | 'video' | 'audio'
     * @return string               ComfyUI-assigned filename (use this, not the original)
     *
     * @throws RuntimeException on upload failure
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
    // Tool 7: cancel_job  (direct ComfyUI — no MCP server needed)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancel a queued or running ComfyUI job.
     *
     * ComfyUI accepts DELETE via POST /queue with {"delete": [prompt_id]}.
     * Jobs already completed cannot be cancelled (returns true silently).
     *
     * @param  string $jobId  ComfyUI prompt_id (stored as comfy_job_id on step)
     * @return bool           true on success or if job was already done
     */
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
    // Tool 8: get_queue_status  (direct ComfyUI — no MCP server needed)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get current ComfyUI queue depth — running and pending job counts.
     * Used by the frontend to display "N jobs ahead of yours".
     *
     * @return array{running: int, pending: int}
     */
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
    // Tool 9: free_vram  (direct ComfyUI — no MCP server needed)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Unload all models from GPU VRAM and free memory via ComfyUI /free.
     *
     * Called by ExecutePlanJob after every generation step completes so that
     * the next step (which may use entirely different models) starts with a
     * clean VRAM slate. Without this, heavy workflows fail with OOM errors
     * when model weights from the prior step are still resident in GPU memory.
     *
     * ComfyUI /free accepts:
     *   unload_models — evict all loaded checkpoints / VAEs / LoRAs from VRAM
     *   free_memory   — release all cached tensors and intermediate buffers
     *
     * The call is fire-and-forget safe: if ComfyUI is temporarily busy the
     * caller logs a warning and continues rather than failing the plan.
     *
     * @return array{freed: bool, error?: string}
     */
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
    // MCP Sidecar: mcpListWorkflows  (requires COMFYUI_MCP_ENABLED=true)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List workflows discovered by the MCP sidecar server from its workflows/ directory.
     * Each entry includes the workflow name, description, and exposed PARAM_* parameters.
     *
     * This is distinct from listWorkflows() which queries ComfyUI /object_info for node types.
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     * @throws RuntimeException if MCP is not enabled or server is unreachable
     */
    public function mcpListWorkflows(): array
    {
        $this->assertMcpEnabled('mcpListWorkflows');

        $result = $this->mcpCall('list_workflows');

        // mcpCall() unwraps the FastMCP content envelope and JSON-decodes the
        // text block. The tool may return {"workflows": [...]} or just [...].
        // If the server returned plain text (_text key), log and bail out.
        if (isset($result['_text'])) {
            Log::warning('McpService::mcpListWorkflows: server returned plain text — cannot parse as workflow list.', [
                'text' => substr($result['_text'], 0, 300),
            ]);
            return [];
        }

        // Support both {"workflows": [...]} and a bare array
        $workflows = $result['workflows'] ?? (array_is_list($result) ? $result : []);

        if (empty($workflows)) {
            Log::warning('McpService::mcpListWorkflows: empty workflow list returned.', ['result' => $result]);
            return [];
        }

        return array_map(function (array $wf) {
            return [
                // 'id' is the filename stem used by run_workflow / get_workflow_json
                'id'          => $wf['id'] ?? '',
                'name'        => $wf['name'] ?? '',
                'description' => $wf['description'] ?? '',
                // Server returns 'available_inputs', not 'parameters'
                'parameters'  => $wf['available_inputs'] ?? $wf['parameters'] ?? [],
            ];
        }, $workflows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MCP Sidecar: mcpGetWorkflowJson  (requires COMFYUI_MCP_ENABLED=true)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve the raw ComfyUI node-graph JSON for a workflow from the MCP server.
     *
     * Used by SyncMcpWorkflows artisan command to import workflow JSON into the DB
     * so injectPrompt() can operate on it for the direct-ComfyUI execution path.
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
    // MCP Sidecar: mcpSubmitJob  (requires COMFYUI_MCP_ENABLED=true)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Submit a fully-injected workflow JSON to ComfyUI via the MCP sidecar.
     *
     * The workflow JSON has already been processed by Workflow::injectPrompt()
     * so all {{PLACEHOLDERS}} are resolved. The MCP server receives the final
     * JSON — its own PARAM_* system is bypassed entirely.
     *
     * When MCP is disabled, falls back transparently to submitJob().
     *
     * @param  string $resolvedWorkflowJson  Fully injected, valid workflow JSON string
     * @return array{prompt_id: string, asset_id: string|null}
     */
    public function mcpSubmitJob(string $resolvedWorkflowJson): array
    {
        if (! $this->mcpEnabled) {
            // Feature flag off — use direct ComfyUI path, wrap return in same shape
            $promptId = $this->submitJob($resolvedWorkflowJson);
            return ['prompt_id' => $promptId, 'asset_id' => null];
        }

        // Decode to array so we can pass as structured parameter
        $nodes = json_decode($resolvedWorkflowJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid workflow JSON: ' . json_last_error_msg());
        }

        // Call submit_raw_workflow — accepts a fully-resolved node graph.
        // (run_workflow expects a workflow_id string + overrides dict, which is
        //  the wrong shape here; injectPrompt() has already resolved everything.)
        $result = $this->mcpCall('submit_raw_workflow', ['workflow_json' => $nodes]);

        $promptId = $result['prompt_id'] ?? null;
        $assetId  = $result['asset_id']  ?? null;

        if (! $promptId) {
            // MCP server may return the job info nested differently — log full response for debugging
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
    // MCP Transport (private)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Make a JSON-RPC 2.0 tool call to the MCP sidecar server.
     *
     * @param  string $tool    MCP tool name (e.g. 'list_workflows', 'run_workflow')
     * @param  array  $params  Tool arguments
     * @return array           Decoded response content
     *
     * @throws RuntimeException on HTTP failure or MCP error response
     */
    private function mcpCall(string $tool, array $params = []): array
    {
        // ── NOTE: server.py runs with stateless_http=True ─────────────────────
        // Each POST to /mcp is fully self-contained. No initialize handshake,
        // no Mcp-Session-Id. We call tools/call directly — one round trip.

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        ];

        // arguments must be a JSON object ({}) not array ([]).
        // PHP serialises an empty [] as JSON array — force (object) for empty params.
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

        // ── Parse SSE response ────────────────────────────────────────────────
        // streamable-http always wraps in SSE: "event: message\ndata: {...}\n\n"
        // Walk every data: line and keep the last valid JSON object.
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

        // Surface JSON-RPC errors (e.g. tool not found, invalid params)
        if (isset($data['error'])) {
            throw new RuntimeException(
                "MCP tool '{$tool}' error: " . ($data['error']['message'] ?? json_encode($data['error']))
            );
        }

        // ── Unwrap FastMCP content envelope ───────────────────────────────────
        // FastMCP wraps every tool result in:
        //   {"result": {"content": [{"type": "text", "text": "<payload>"}], "isError": false}}
        //
        // If the tool returned JSON, decode the text block.
        // If the tool returned a plain string, surface it under '_text'.
        $result = $data['result'] ?? $data;

        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $block) {
                if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $inner = json_decode($block['text'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($inner)) {
                        return $inner;               // ← tool returned JSON object/array
                    }
                    return ['_text' => $block['text']]; // ← tool returned plain string
                }
            }
        }

        return $result;
    }

    /**
     * Assert that the MCP sidecar is enabled before calling MCP-only methods.
     * Throws a clear error rather than silently doing nothing.
     */
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