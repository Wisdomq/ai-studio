<?php

namespace Tests\Unit;

use App\Models\Capability;
use App\Models\Workflow;
use App\Services\CapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CapabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CapabilityService();
    }

    /** @test */
    public function it_can_get_all_active_capabilities()
    {
        // Create test capabilities
        Capability::factory()->create(['is_active' => true]);
        Capability::factory()->create(['is_active' => true]);
        Capability::factory()->create(['is_active' => false]);

        $active = $this->service->getAllActive();

        $this->assertCount(2, $active);
    }

    /** @test */
    public function it_can_group_capabilities_by_category()
    {
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'video', 'is_active' => true]);

        $grouped = $this->service->getByCategory();

        $this->assertCount(2, $grouped); // 2 categories
        $this->assertCount(2, $grouped['image']);
        $this->assertCount(1, $grouped['video']);
    }

    /** @test */
    public function it_can_find_capability_by_slug()
    {
        $capability = Capability::factory()->create([
            'slug' => 'text-to-image',
            'is_active' => true,
        ]);

        $found = $this->service->findBySlug('text-to-image');

        $this->assertNotNull($found);
        $this->assertEquals($capability->id, $found->id);
    }

    /** @test */
    public function it_returns_null_for_inactive_capability_by_slug()
    {
        Capability::factory()->create([
            'slug' => 'text-to-image',
            'is_active' => false,
        ]);

        $found = $this->service->findBySlug('text-to-image');

        $this->assertNull($found);
    }

    /** @test */
    public function it_can_get_capabilities_for_category()
    {
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'video', 'is_active' => true]);

        $imageCapabilities = $this->service->getForCategory('image');

        $this->assertCount(2, $imageCapabilities);
    }

    /** @test */
    public function it_can_link_workflows_to_capability()
    {
        $capability = Capability::factory()->create();
        $workflow1 = Workflow::factory()->create();
        $workflow2 = Workflow::factory()->create();

        $this->service->linkWorkflows($capability, [$workflow1->id, $workflow2->id]);

        $this->assertCount(2, $capability->fresh()->workflows);
    }

    /** @test */
    public function it_can_check_if_capability_has_workflows()
    {
        $capability = Capability::factory()->create();
        $workflow = Workflow::factory()->create();

        $this->assertFalse($this->service->hasWorkflows($capability));

        $capability->workflows()->attach($workflow->id);

        $this->assertTrue($this->service->hasWorkflows($capability->fresh()));
    }

    /** @test */
    public function it_can_get_capability_stats()
    {
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'image', 'is_active' => true]);
        Capability::factory()->create(['category' => 'video', 'is_active' => true]);
        Capability::factory()->create(['category' => 'audio', 'is_active' => false]);

        $stats = $this->service->getStats();

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(3, $stats['active']);
        $this->assertEquals(1, $stats['inactive']);
        $this->assertEquals(2, $stats['by_category']['image']);
        $this->assertEquals(1, $stats['by_category']['video']);
    }

    /** @test */
    public function it_can_search_capabilities()
    {
        Capability::factory()->create([
            'name' => 'text-to-image',
            'description' => 'Generate images from text',
            'is_active' => true,
        ]);
        Capability::factory()->create([
            'name' => 'image-to-video',
            'description' => 'Animate images',
            'is_active' => true,
        ]);

        $results = $this->service->search('image');

        $this->assertCount(2, $results);
    }

    /** @test */
    public function it_can_toggle_capability_status()
    {
        $capability = Capability::factory()->create(['is_active' => true]);

        $newStatus = $this->service->toggleActive($capability);

        $this->assertFalse($newStatus);
        $this->assertFalse($capability->fresh()->is_active);

        $newStatus = $this->service->toggleActive($capability->fresh());

        $this->assertTrue($newStatus);
        $this->assertTrue($capability->fresh()->is_active);
    }

    /** @test */
    public function it_can_build_prompt_list()
    {
        Capability::factory()->create([
            'name' => 'text-to-image',
            'category' => 'image',
            'description' => 'Generate images',
            'is_active' => true,
            'metadata' => [
                'input_types' => ['text'],
                'output_type' => 'image',
                'tags' => ['generation'],
            ],
        ]);

        $promptList = $this->service->buildPromptList();

        $this->assertStringContainsString('IMAGE CAPABILITIES', $promptList);
        $this->assertStringContainsString('text-to-image', $promptList);
        $this->assertStringContainsString('Generate images', $promptList);
    }
}
