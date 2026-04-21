<?php

namespace Database\Seeders;

use App\Models\Capability;
use Illuminate\Database\Seeder;

class CapabilitySeeder extends Seeder
{
    /**
     * Seed sample capabilities for the system.
     */
    public function run(): void
    {
        $capabilities = [
            // Image Generation
            [
                'name' => 'text-to-image',
                'slug' => 'text-to-image',
                'description' => 'Generate images from text descriptions using AI models',
                'category' => 'image',
                'metadata' => [
                    'input_types' => ['text'],
                    'output_type' => 'image',
                    'tags' => ['generation', 'creative', 'ai'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'image-to-image',
                'slug' => 'image-to-image',
                'description' => 'Transform or enhance existing images',
                'category' => 'image',
                'metadata' => [
                    'input_types' => ['image', 'text'],
                    'output_type' => 'image',
                    'tags' => ['transformation', 'enhancement'],
                ],
                'is_active' => true,
            ],
            
            // Video Generation
            [
                'name' => 'text-to-video',
                'slug' => 'text-to-video',
                'description' => 'Generate videos from text descriptions',
                'category' => 'video',
                'metadata' => [
                    'input_types' => ['text'],
                    'output_type' => 'video',
                    'tags' => ['generation', 'animation'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'image-to-video',
                'slug' => 'image-to-video',
                'description' => 'Animate static images into videos',
                'category' => 'video',
                'metadata' => [
                    'input_types' => ['image', 'text'],
                    'output_type' => 'video',
                    'tags' => ['animation', 'transformation'],
                ],
                'is_active' => true,
            ],
            
            // Audio Generation
            [
                'name' => 'text-to-speech',
                'slug' => 'text-to-speech',
                'description' => 'Convert text to natural-sounding speech',
                'category' => 'audio',
                'metadata' => [
                    'input_types' => ['text'],
                    'output_type' => 'audio',
                    'tags' => ['generation', 'voice'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'audio-to-audio',
                'slug' => 'audio-to-audio',
                'description' => 'Transform or enhance audio files',
                'category' => 'audio',
                'metadata' => [
                    'input_types' => ['audio', 'text'],
                    'output_type' => 'audio',
                    'tags' => ['transformation', 'enhancement'],
                ],
                'is_active' => true,
            ],
            
            // Style Transfer
            [
                'name' => 'style-transfer',
                'slug' => 'style-transfer',
                'description' => 'Apply artistic styles to images',
                'category' => 'style',
                'metadata' => [
                    'input_types' => ['image', 'text'],
                    'output_type' => 'image',
                    'tags' => ['style', 'artistic', 'transformation'],
                ],
                'is_active' => true,
            ],
            
            // Effects
            [
                'name' => 'upscale',
                'slug' => 'upscale',
                'description' => 'Increase image resolution while maintaining quality',
                'category' => 'effect',
                'metadata' => [
                    'input_types' => ['image'],
                    'output_type' => 'image',
                    'tags' => ['enhancement', 'quality'],
                ],
                'is_active' => true,
            ],
        ];

        foreach ($capabilities as $capability) {
            Capability::updateOrCreate(
                ['slug' => $capability['slug']],
                $capability
            );
        }

        $this->command->info('✅ Seeded ' . count($capabilities) . ' capabilities');
    }
}
