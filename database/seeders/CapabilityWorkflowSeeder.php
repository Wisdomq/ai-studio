<?php

namespace Database\Seeders;

use App\Models\Capability;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class CapabilityWorkflowSeeder extends Seeder
{
    /**
     * Link capabilities to workflows based on their output types.
     * 
     * This seeder creates the many-to-many relationships between
     * capabilities and workflows.
     */
    public function run(): void
    {
        $workflows = Workflow::all();

        if ($workflows->isEmpty()) {
            $this->command->warn('No workflows found. Import workflows first before linking capabilities.');
            return;
        }

        $linked = 0;

        foreach ($workflows as $workflow) {
            // Find matching capabilities based on output type and input requirements
            $capabilities = Capability::where('is_active', true)
                ->where(function ($query) use ($workflow) {
                    // Match by output type in metadata
                    $query->whereJsonContains('metadata->output_type', $workflow->output_type);
                })
                ->get();

            // If no exact match, try to match by category
            if ($capabilities->isEmpty()) {
                $category = $this->getCategoryFromOutputType($workflow->output_type);
                $capabilities = Capability::where('is_active', true)
                    ->where('category', $category)
                    ->get();
            }

            // Link the capabilities to this workflow
            if ($capabilities->isNotEmpty()) {
                $workflow->capabilities()->syncWithoutDetaching($capabilities->pluck('id')->toArray());
                $linked += $capabilities->count();
                
                $this->command->info("✓ Linked {$capabilities->count()} capabilities to workflow: {$workflow->name}");
            }
        }

        $this->command->info("\n✅ Linked {$linked} capability-workflow relationships");
    }

    protected function getCategoryFromOutputType(string $outputType): string
    {
        return match($outputType) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default => 'general',
        };
    }
}
