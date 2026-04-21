<?php

namespace App\Services;

use App\Models\Capability;
use App\Models\Workflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CapabilityService
{
    /**
     * Get all active capabilities.
     */
    public function getAllActive(): Collection
    {
        return Capability::active()->get();
    }

    /**
     * Get capabilities grouped by category.
     */
    public function getByCategory(): Collection
    {
        return Capability::active()
            ->get()
            ->groupBy('category');
    }

    /**
     * Find a capability by slug.
     */
    public function findBySlug(string $slug): ?Capability
    {
        return Capability::where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get all capabilities for a specific category.
     */
    public function getForCategory(string $category): Collection
    {
        return Capability::active()
            ->category($category)
            ->get();
    }

    /**
     * Link a capability to workflows.
     * 
     * @param Capability $capability
     * @param array $workflowIds
     * @return void
     */
    public function linkWorkflows(Capability $capability, array $workflowIds): void
    {
        $capability->workflows()->sync($workflowIds);
        
        Log::info('CapabilityService: Linked workflows to capability', [
            'capability_id' => $capability->id,
            'capability_name' => $capability->name,
            'workflow_ids' => $workflowIds,
        ]);
    }

    /**
     * Get workflows that have a specific capability.
     */
    public function getWorkflowsForCapability(Capability $capability): Collection
    {
        return $capability->workflows()->active()->get();
    }

    /**
     * Check if a capability has any workflows linked.
     */
    public function hasWorkflows(Capability $capability): bool
    {
        return $capability->workflows()->exists();
    }

    /**
     * Get capability statistics.
     */
    public function getStats(): array
    {
        $total = Capability::count();
        $active = Capability::active()->count();
        $byCategory = Capability::active()
            ->get()
            ->groupBy('category')
            ->map->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'by_category' => $byCategory->toArray(),
        ];
    }

    /**
     * Build capability list for AI agent system prompt.
     * This provides semantic descriptions to the AI.
     */
    public function buildPromptList(): string
    {
        $capabilities = Capability::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        if ($capabilities->isEmpty()) {
            return 'No capabilities available yet.';
        }

        $lines = [];
        $currentCategory = null;

        foreach ($capabilities as $capability) {
            // Add category header
            if ($currentCategory !== $capability->category) {
                $currentCategory = $capability->category;
                $lines[] = "\n## " . strtoupper($currentCategory) . " CAPABILITIES:";
            }

            // Format capability info
            $metadata = $capability->metadata ?? [];
            $inputTypes = $metadata['input_types'] ?? [];
            $outputType = $metadata['output_type'] ?? 'unknown';
            $tags = $metadata['tags'] ?? [];

            $inputStr = empty($inputTypes) ? 'text only' : implode(' + ', $inputTypes);
            $tagsStr = empty($tags) ? '' : ' [' . implode(', ', $tags) . ']';

            $lines[] = sprintf(
                '- ID:%d | %s | needs:%s → produces:%s%s | %s',
                $capability->id,
                $capability->name,
                $inputStr,
                $outputType,
                $tagsStr,
                $capability->description
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Search capabilities by name or description.
     */
    public function search(string $query): Collection
    {
        return Capability::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->get();
    }

    /**
     * Toggle capability active status.
     */
    public function toggleActive(Capability $capability): bool
    {
        $newStatus = !$capability->is_active;
        $capability->update(['is_active' => $newStatus]);

        Log::info('CapabilityService: Toggled capability status', [
            'capability_id' => $capability->id,
            'capability_name' => $capability->name,
            'new_status' => $newStatus ? 'active' : 'inactive',
        ]);

        return $newStatus;
    }
}
