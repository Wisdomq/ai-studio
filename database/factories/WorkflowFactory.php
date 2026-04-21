<?php

namespace Database\Factories;

use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        $types = [
            Workflow::TYPE_IMAGE,
            Workflow::TYPE_VIDEO,
            Workflow::TYPE_AUDIO,
            Workflow::TYPE_IMAGE_TO_VIDEO,
            Workflow::TYPE_IMAGE_TO_IMAGE,
        ];

        $type = $this->faker->randomElement($types);
        
        return [
            'type' => $type,
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'workflow_json' => json_encode([
                '1' => [
                    'class_type' => 'KSampler',
                    'inputs' => [
                        'seed' => 12345,
                        'steps' => 20,
                        'cfg' => 7.0,
                    ],
                ],
            ]),
            'is_active' => true,
            'input_types' => $this->faker->randomElement([
                null,
                ['image'],
                ['audio'],
                ['image', 'text'],
            ]),
            'output_type' => $this->getOutputType($type),
            'inject_keys' => null,
            'comfy_workflow_name' => null,
            'discovered_at' => now(),
            'default_for_type' => false,
            'mcp_workflow_id' => null,
        ];
    }

    protected function getOutputType(string $type): string
    {
        return match($type) {
            Workflow::TYPE_IMAGE, Workflow::TYPE_IMAGE_TO_IMAGE => 'image',
            Workflow::TYPE_VIDEO, Workflow::TYPE_IMAGE_TO_VIDEO => 'video',
            Workflow::TYPE_AUDIO => 'audio',
            default => 'image',
        };
    }

    /**
     * Indicate that the workflow is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the workflow is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set as default for type.
     */
    public function defaultForType(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_for_type' => true,
        ]);
    }
}
