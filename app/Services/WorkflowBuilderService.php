<?php

namespace App\Services;

use App\Models\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowBuilderService
{
    protected string $comfyUrl;

    public function __construct()
    {
        $this->comfyUrl = config('services.comfyui.url', 'http://172.16.10.11:8188');
    }

    /**
     * Main entry point
     */
    public function buildFromIntent(string $intent): Workflow
    {
        // 1. Generate candidate workflow JSON
        $workflowJson = $this->generateWorkflowJson($intent);

        // 2. Validate nodes against ComfyUI
        $this->validateWorkflow($workflowJson);

        // 3. Save to DB
        return $this->saveWorkflow($intent, $workflowJson);
    }

    /**
     * Generate workflow JSON (SAFE BASE VERSION)
     * Replace later with Comfy Pilot / OpenCode logic
     */
    protected function generateWorkflowJson(string $intent): string
    {
        // Minimal safe template (guaranteed valid)
        // This prevents hallucination at MVP stage

        return json_encode([
            "1" => [
                "class_type" => "KSampler",
                "inputs" => [
                    "seed" => "{{SEED}}",
                    "steps" => "{{STEPS}}",
                    "cfg" => "{{CFG}}"
                ]
            ],
            "2" => [
                "class_type" => "CLIPTextEncode",
                "inputs" => [
                    "text" => "{{POSITIVE_PROMPT}}"
                ]
            ]
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Validate workflow against real ComfyUI nodes
     */
    protected function validateWorkflow(string $json): void
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new \Exception("Invalid workflow JSON format.");
        }

        $availableNodes = $this->fetchAvailableNodes();

        foreach ($decoded as $nodeId => $node) {
            $class = $node['class_type'] ?? null;

            if (! $class) {
                throw new \Exception("Node {$nodeId} missing class_type.");
            }

            if (! in_array($class, $availableNodes)) {
                throw new \Exception("Invalid node detected: {$class}");
            }
        }
    }

    /**
     * Fetch available nodes from ComfyUI
     */
    protected function fetchAvailableNodes(): array
    {
        try {
            $res = Http::timeout(10)->get("{$this->comfyUrl}/object_info");

            if (! $res->ok()) {
                throw new \Exception("Failed to fetch ComfyUI nodes.");
            }

            $data = $res->json();

            return array_keys($data);

        } catch (\Throwable $e) {
            Log::error("WorkflowBuilder: Failed to fetch nodes", [
                'error' => $e->getMessage()
            ]);

            // Fail safe: block creation if we cannot verify
            throw new \Exception("Cannot validate workflow nodes.");
        }
    }

    /**
     * Save workflow into DB
     */
    protected function saveWorkflow(string $intent, string $json): Workflow
    {
        return Workflow::create([
            'name'        => $this->generateName($intent),
            'type'        => Workflow::TYPE_IMAGE,
            'output_type' => 'image',
            'description' => $intent,
            'workflow_json' => $json,
            'is_active'   => true,
            'input_types' => [],
            'inject_keys' => [],
            'comfy_workflow_name' => null,
            'discovered_at' => now(),
            'default_for_type' => false,
        ]);
    }

    /**
     * Generate human-friendly workflow name
     */
    protected function generateName(string $intent): string
    {
        return Str::title(Str::limit($intent, 40, ''));
    }
}