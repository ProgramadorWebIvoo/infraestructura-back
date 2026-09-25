<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F2-R R2: propiedad de proyectos. INFRAESTRUCTURA solo ve los que creó;
 * RESIDENTE queda con lista blanca; el resto de roles no cambia.
 */
class ProjectOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $other;
    private Project $mine;
    private Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->other = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->mine = Project::factory()->create(['requested_by_user_id' => $this->owner->id]);
        $this->theirs = Project::factory()->create(['requested_by_user_id' => $this->other->id]);
    }

    public function test_store_records_the_creator_as_owner(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/projects', [
            'title' => 'Obra nueva de prueba',
            'type' => 'INFRAESTRUCTURA',
            'description' => 'Descripción suficientemente larga de la obra',
            'location' => 'Caracas',
            'materials' => [['name' => 'Cemento', 'quantity' => 5, 'unit' => 'Saco', 'estimatedUnitPrice' => 10, 'condition' => 'NUEVO']],
        ])->assertStatus(201);

        $this->assertSame($this->owner->id, Project::find($response->json('data.id'))->requested_by_user_id);
    }

    public function test_index_only_lists_own_projects_for_infraestructura(): void
    {
        $ids = collect($this->actingAs($this->owner)->getJson('/api/projects')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$this->mine->id], $ids->all());
    }

    public function test_projects_without_owner_are_invisible_to_infraestructura(): void
    {
        $orphan = Project::factory()->create();

        $ids = collect($this->actingAs($this->owner)->getJson('/api/projects')->json('data'))->pluck('id');
        $this->assertNotContains($orphan->id, $ids);
        $this->actingAs($this->owner)->getJson("/api/projects/{$orphan->id}")->assertNotFound();
    }

    public function test_non_owner_gets_404_on_every_project_route(): void
    {
        $id = $this->theirs->id;

        foreach ([
            ['getJson', "/api/projects/{$id}"],
            ['getJson', "/api/projects/{$id}/documents"],
            ['getJson', "/api/projects/{$id}/closure-report"],
            ['getJson', "/api/projects/{$id}/rate-freezes"],
            ['getJson', "/api/ai/evaluate-proposals/status/{$id}"],
            ['postJson', "/api/projects/{$id}/resubmit"],
            ['postJson', "/api/projects/{$id}/closure-report/resend-link"],
            ['patchJson', "/api/projects/{$id}/resident"],
        ] as [$method, $uri]) {
            $this->actingAs($this->owner)->{$method}($uri)->assertNotFound();
        }
    }

    public function test_owner_reaches_own_project(): void
    {
        $this->actingAs($this->owner)->getJson("/api/projects/{$this->mine->id}")->assertOk();
        $this->actingAs($this->owner)->getJson("/api/projects/{$this->mine->id}/documents")->assertOk();
    }

    public function test_supplier_invitations_are_scoped_by_project_id(): void
    {
        $body = ['project_id' => $this->theirs->id, 'supplierName' => 'X', 'supplierContact' => 'x@test.com'];

        $this->actingAs($this->owner)->postJson('/api/supplier-invitations', $body)->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/supplier-invitations/latest?project_id=' . $this->theirs->id . '&supplierContact=x@test.com')->assertNotFound();
        $this->actingAs($this->owner)->postJson('/api/supplier-invitations', [...$body, 'project_id' => $this->mine->id])->assertStatus(201);
    }

    public function test_proposals_index_only_returns_own_projects_for_infraestructura(): void
    {
        foreach ([$this->mine, $this->theirs] as $p) {
            SupplierMaterialProposal::factory()->create(['project_id' => $p->id]);
        }

        $projectIds = collect($this->actingAs($this->owner)->getJson('/api/supplier-material-proposals')->assertOk()->json('data'))->pluck('projectId');
        $this->assertSame([$this->mine->id], $projectIds->unique()->values()->all());
    }

    public function test_internal_proposal_image_is_scoped_to_the_invitation_project(): void
    {
        Storage::fake('local');
        $invitation = SupplierInvitation::create([
            'id' => (string) Str::uuid(), 'project_id' => $this->theirs->id,
            'supplier_name' => 'A', 'supplier_contact' => 'a@test.com', 'expires_at' => now()->addDays(7),
        ]);
        Storage::disk('local')->put("supplier-proposal-images/{$invitation->id}/img.jpg", 'x');

        $this->actingAs($this->owner)->get("/api/supplier-proposal-images/{$invitation->id}/img.jpg")->assertNotFound();
    }

    public function test_other_roles_see_everything(): void
    {
        foreach (['ADMIN', 'SUPERADMIN', 'AUDITORIA', 'PROCURA'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertCount(2, $this->actingAs($user)->getJson('/api/projects')->assertOk()->json('data'), $role);
            $this->actingAs($user)->getJson("/api/projects/{$this->theirs->id}")->assertOk();
        }
    }

    public function test_residente_is_limited_to_session_endpoints(): void
    {
        $resident = User::factory()->create(['role' => 'RESIDENTE']);

        $this->actingAs($resident)->getJson('/api/user')->assertOk();
        $this->actingAs($resident)->getJson('/api/auth/permissions')->assertOk();
        $this->actingAs($resident)->getJson('/api/notifications')->assertOk();
        $this->actingAs($resident)->getJson('/api/projects')->assertForbidden();
        $this->actingAs($resident)->getJson("/api/projects/{$this->mine->id}")->assertForbidden();
        $this->actingAs($resident)->getJson("/api/projects/{$this->mine->id}/closure-report")->assertForbidden();
        $this->actingAs($resident)->getJson('/api/contractors')->assertForbidden();
    }
}
