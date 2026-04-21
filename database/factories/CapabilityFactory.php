<?php

namespace Database\Factories;

use App\Models\Capability;
use Illuminate\Database\Eloquent\Factories\Factory;

class CapabilityFactory extends Factory
{
    protected $model = Capability::class;

    public function definition(): array
    {
        $categories = ['image', 'video', 'audio', 'style', 'effect'];
        $category = $this->faker->randomElement($categories);
        
        $name = $this->faker->unique()->slug(2);
        
        return [
            'name' => $name,
            'slug' => $name,
            'description' => $this->faker->sentence(),
            'category' => $category,
            'metadata' => [
                'input_types' => $this->faker->randomElement([
                    [],
                    ['text'],
                    ['image'],
                    ['image', 'text'],
                    ['audio', 'text'],
                ]),
                'output_type' => $category,
                'tags' => $this->faker->randomElements(
                    ['generation', 'transformation', 'enhancement', 'creative', 'artistic'],
                    $this->faker->numberBetween(1, 3)
                ),
            ],
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
        ];
    }

    /**
     * Indicate that the capability is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the capability is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'output_type' => $category,
            ]),
        ]);
    }
}
