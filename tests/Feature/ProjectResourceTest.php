<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $infra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
    }

    public function test_project_resource_exposes_created_at_and_updated_at(): void
    {
        $project = Project::factory()->create([
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-20 15:30:00',
        ]);

        $response = $this->actingAs($this->infra)
            ->getJson("/api/projects/{$project->id}")
            ->assertOk();

        $this->assertNotEmpty($response->json('data.createdAt'));
        $this->assertNotEmpty($response->json('data.updatedAt'));
        $this->assertStringStartsWith('2026-07-01', $response->json('data.createdAt'));
        $this->assertStringStartsWith('2026-07-20', $response->json('data.updatedAt'));
    }

    public function test_project_resource_includes_contractor_rating_in_proposals(): void
    {
        $contractor = Contractor::factory()->create(['rating' => 4.7]);
        $project = Project::factory()->create();

        ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
        ]);

        $response = $this->actingAs($this->infra)
            ->getJson("/api/projects/{$project->id}")
            ->assertOk();

        $proposals = $response->json('data.proposals');
        $this->assertCount(1, $proposals);
        $this->assertEquals(4.7, $proposals[0]['contractorRating']);
    }
}
