<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\RenegotiationInvitation;
use App\Models\User;
use App\Notifications\SupplierRenegotiationInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RenegotiationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $analista;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
        $this->contractor = Contractor::factory()->create(['email' => 'proveedor@example.com']);
    }

    private function createOriginalProposal(Project $project): string
    {
        $response = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $this->contractor->code,
                'materialCost'             => 24000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 32000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 15,
                'description'              => 'Oferta original',
                'origen'                   => 'MANUAL',
                'fechaOferta'              => '2026-07-01',
            ]);
        $response->assertStatus(200);

        return $response->json('data.proposals')[0]['id'];
    }

    public function test_store_creates_invitation_and_sends_mail_to_contractor_email(): void
    {
        Notification::fake();

        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $response = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite");

        $response->assertStatus(201);
        $response->assertJsonStructure(['token', 'contractorEmail', 'createdAt', 'expiresAt']);
        $this->assertSame('proveedor@example.com', $response->json('contractorEmail'));

        $this->assertDatabaseHas('renegotiation_invitations', [
            'proposal_id' => $proposalId,
            'contractor_email' => 'proveedor@example.com',
        ]);

        Notification::assertSentOnDemand(SupplierRenegotiationInvitation::class);
    }

    public function test_store_fails_when_contractor_has_no_email(): void
    {
        $contractorNoEmail = Contractor::factory()->create(['email' => null]);
        $project = Project::factory()->confirmed()->create();

        $addResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractorNoEmail->code,
                'materialCost'             => 24000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 32000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 15,
                'description'              => 'Oferta original',
                'origen'                   => 'MANUAL',
                'fechaOferta'              => '2026-07-01',
            ]);
        $proposalId = $addResponse->json('data.proposals')[0]['id'];

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite")
            ->assertStatus(422);
    }

    public function test_public_info_returns_proposal_fields_needed_by_the_form(): void
    {
        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $inviteResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite");
        $token = $inviteResponse->json('token');

        $response = $this->getJson("/api/public/renegotiations/{$token}");

        $response->assertStatus(200);
        $response->assertJsonPath('proposal.id', $proposalId);
        $this->assertEquals(32000.0, $response->json('proposal.totalCost'));
        $response->assertJsonPath('project.id', $project->id);
    }

    public function test_public_info_rejects_invalid_token(): void
    {
        $this->getJson('/api/public/renegotiations/does-not-exist')
            ->assertStatus(404);
    }

    public function test_submit_applies_renegotiation_and_marks_invitation_used(): void
    {
        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $inviteResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite");
        $token = $inviteResponse->json('token');

        $response = $this->postJson("/api/public/renegotiations/{$token}/proposal", [
            'materialCost'             => 20000.00,
            'laborCost'                => 8000.00,
            'totalCost'                => 28000.00,
            'deliveryWeeks'            => 12,
            'negotiatedAdvancePercent' => 15,
            'description'              => 'Oferta renegociada via enlace publico',
            'fechaOferta'              => '2026-07-02',
            'motivo'                   => 'El proveedor bajó el precio via el portal público.',
        ]);

        $response->assertStatus(201);
        $newId = $response->json('id');
        $this->assertNotEquals($proposalId, $newId);

        $this->assertDatabaseHas('project_proposals', [
            'id' => $newId,
            'origen' => 'RENEGOCIACION',
            'precio_anterior' => 32000.00,
            'precio_nuevo' => 28000.00,
        ]);
        $this->assertDatabaseHas('project_proposals', [
            'id' => $proposalId,
            'replaced_by_id' => $newId,
        ]);
        $this->assertDatabaseHas('renegotiation_invitations', [
            'id' => $token,
        ]);
        $this->assertNotNull(RenegotiationInvitation::find($token)->used_at);

        // El enlace ya usado no puede reutilizarse.
        $this->postJson("/api/public/renegotiations/{$token}/proposal", [
            'materialCost'             => 1,
            'laborCost'                => 1,
            'totalCost'                => 2,
            'deliveryWeeks'            => 1,
            'negotiatedAdvancePercent' => 1,
            'description'              => 'Segundo intento',
            'fechaOferta'              => '2026-07-02',
            'motivo'                   => 'Reintento',
        ])->assertStatus(404);
    }

    public function test_submit_requires_motivo(): void
    {
        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $inviteResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite");
        $token = $inviteResponse->json('token');

        $this->postJson("/api/public/renegotiations/{$token}/proposal", [
            'materialCost'             => 20000.00,
            'laborCost'                => 8000.00,
            'totalCost'                => 28000.00,
            'deliveryWeeks'            => 12,
            'negotiatedAdvancePercent' => 15,
            'description'              => 'Oferta renegociada',
            'fechaOferta'              => '2026-07-02',
        ])->assertStatus(422);
    }

    public function test_submit_rejects_if_proposal_was_selected_after_invite_was_sent(): void
    {
        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $inviteResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite");
        $token = $inviteResponse->json('token');

        // El proyecto es adjudicado a esta propuesta DESPUÉS de haberse
        // enviado el enlace público — el proveedor no debe poder renegociar
        // una oferta ya ganadora.
        $project->update(['selected_proposal_id' => $proposalId]);

        $this->postJson("/api/public/renegotiations/{$token}/proposal", [
            'materialCost'             => 20000.00,
            'laborCost'                => 8000.00,
            'totalCost'                => 28000.00,
            'deliveryWeeks'            => 12,
            'negotiatedAdvancePercent' => 15,
            'description'              => 'Oferta renegociada',
            'fechaOferta'              => '2026-07-02',
            'motivo'                   => 'Intento de renegociar oferta ya adjudicada.',
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_proposals', [
            'id' => $proposalId,
            'replaced_by_id' => null,
        ]);
    }

    public function test_store_invalidates_previous_active_invitation_for_same_proposal(): void
    {
        Notification::fake();

        $project = Project::factory()->confirmed()->create();
        $proposalId = $this->createOriginalProposal($project);

        $first = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite")
            ->json('token');

        $second = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals/{$proposalId}/renegotiation-invite")
            ->json('token');

        $this->getJson("/api/public/renegotiations/{$first}")->assertStatus(404);
        $this->getJson("/api/public/renegotiations/{$second}")->assertStatus(200);
    }
}
