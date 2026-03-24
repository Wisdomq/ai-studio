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
 * Implements all 6 MCP tools as PHP methods. The method signatures match
 * the MCP tool spec exactly so that swapping to a real MCP server later
 * is transparent — only the transport layer changes, not the callers.
 *
 * Tool spec:
 *   list_workflows     → array of workflow descriptors
 *   submit_job         → job_id (prompt_id)
 *   check_job_status   → status, queue_position, estimated_wait_seconds
 *   get_job_result     → output_files[], media_type, storage_path
 *   upload_input_file  → comfy_filename
 *   health_check       → reachable: bool, gpu_vram_free: string
 */
class McpService
{
    protected string $baseUrl;
    protected string $clientId;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('comfyui.base_url', 'http://172.16.10.11:8188'), '/');
        $this->clientId = Str::uuid()->toString();
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
    // Tool 2: list_workflows
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List available workflows from ComfyUI /object_info.
     * Used by the admin sync endpoint to discover and upsert workflows.
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
            // Collect workflow-level metadata from node definitions
            // Each entry represents a node type available in ComfyUI
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
            $queue    = $queueResponse->json();
            $running  = $queue['queue_running'] ?? [];
            $pending  = $queue['queue_pending'] ?? [];

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
                        'status'                  => 'queued',
                        'queue_position'          => $pos + 1,
                        'estimated_wait_seconds'  => ($pos + 1) * 30, // rough estimate
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

        $jobHistory = $historyResponse->json()[$jobId];
        $outputs    = $jobHistory['outputs'] ?? [];

        $outputFiles  = [];
        $storagePath  = null;

        foreach ($outputs as $nodeId => $nodeOutputs) {
            // Images
            foreach ($nodeOutputs['images'] ?? [] as $image) {
                $filename  = $image['filename'];
                $subfolder = $image['subfolder'] ?? '';
                $type      = $image['type'] ?? 'output';

                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'image');

                $outputFiles[] = [
                    'filename'     => $filename,
                    'storage_path' => $storagePath,
                    'media_type'   => 'image',
                ];
            }

            // Videos / GIFs
            foreach ($nodeOutputs['gifs'] ?? [] as $video) {
                $filename  = $video['filename'];
                $subfolder = $video['subfolder'] ?? '';
                $type      = $video['type'] ?? 'output';

                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'video');

                $outputFiles[] = [
                    'filename'     => $filename,
                    'storage_path' => $storagePath,
                    'media_type'   => 'video',
                ];
            }

            // Audio files
            foreach ($nodeOutputs['audio'] ?? [] as $audio) {
                $filename  = $audio['filename'];
                $subfolder = $audio['subfolder'] ?? '';
                $type      = $audio['type'] ?? 'output';

                $fileContent = $this->downloadOutputFile($filename, $subfolder, $type);
                $storagePath = $this->saveToStorage($filename, $fileContent, 'audio');

                $outputFiles[] = [
                    'filename'     => $filename,
                    'storage_path' => $storagePath,
                    'media_type'   => 'audio',
                ];
            }
        }

        if (empty($outputFiles)) {
            throw new RuntimeException("No output files found for job {$jobId}");
        }

        // Return the first output as the primary result
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

        // Always use ComfyUI-assigned filename — it may have been renamed
        return $filename;
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
        // Always use storage-relative paths — never absolute
        $storagePath = 'comfyui-outputs/' . $originalFilename;

        Storage::disk('public')->put($storagePath, $content);

        return $storagePath;
    }
}