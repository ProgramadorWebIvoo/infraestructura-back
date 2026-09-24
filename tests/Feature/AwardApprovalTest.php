<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AwardApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $presidencia;
    private User $finanzas;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->contractor = Contractor::factory()->create();
    }

    private function projectWithSelection(string $status = 'PENDIENTE_PRESIDENCIA'): Project
    {
        $project = Project::factory()->create(['status' => $status]);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 1000,
        ]);
        $project->update(['selected_contractor_code' => $this->contractor->code, 'selected_proposal_id' => $proposal->id]);

        return $project;
    }

    public function test_presidencia_approves_pending_award(): void
    {
        $project = $this->projectWithSelection();

        $this->actingAs($this->presidencia)
            ->postJson("/api/projects/{$project->id}/award-approval", ['observations' => 'ok'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APROBADO_PRESIDENCIA');

        $this->assertDatabaseHas('audit_logs', ['project_id' => $project->id, 'action' => 'Aprobacion de adjudicacion por Presidencia']);
    }

    public function test_approval_is_forbidden_for_procura_and_finanzas(): void
    {
        $project = $this->projectWithSelection();

        foreach ([$this->procura, $this->finanzas] as $user) {
            $this->actingAs($user)->postJson("/api/projects/{$project->id}/award-approval")->assertStatus(403);
        }
        $this->assertSame('PENDIENTE_PRESIDENCIA', $project->fresh()->status);
    }

    public function test_approval_requires_pending_status(): void
    {
        $project = $this->projectWithSelection('COMPARATIVA_ENVIADA');

        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval")->assertStatus(422);
    }

    public function test_rejection_requires_reason_and_returns_to_procura(): void
    {
        $project = $this->projectWithSelection();

        $this->actingAs($this->presidencia)
            ->postJson("/api/projects/{$project->id}/award-rejection", [])
            ->assertStatus(422);

        $this->actingAs($this->presidencia)
            ->postJson("/api/projects/{$project->id}/award-rejection", ['reason' => 'Monto excesivo'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPARATIVA_ENVIADA');

        $fresh = $project->fresh();
        $this->assertNull($fresh->selected_contractor_code);
        $this->assertNull($fresh->selected_proposal_id);
        $this->assertDatabaseHas('audit_logs', ['project_id' => $project->id, 'action' => 'Rechazo de adjudicacion por Presidencia']);
    }

    public function test_batch_approval_is_all_or_nothing(): void
    {
        $a = $this->projectWithSelection();
        $b = $this->projectWithSelection();
        $c = $this->projectWithSelection('COMPARATIVA_ENVIADA');

        $this->actingAs($this->presidencia)
            ->postJson('/api/projects/award-approvals/batch', ['projectIds' => [$a->id, $b->id, $c->id]])
            ->assertStatus(422);
        $this->assertSame('PENDIENTE_PRESIDENCIA', $a->fresh()->status);

        $this->actingAs($this->presidencia)
            ->postJson('/api/projects/award-approvals/batch', ['projectIds' => [$a->id, $b->id]])
            ->assertStatus(200);
        $this->assertSame('APROBADO_PRESIDENCIA', $a->fresh()->status);
        $this->assertSame('APROBADO_PRESIDENCIA', $b->fresh()->status);
    }

    public function test_only_procura_sends_to_finance_after_approval(): void
    {
        $project = $this->projectWithSelection();

        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/send-to-finance")->assertStatus(422);

        $project->update(['status' => 'APROBADO_PRESIDENCIA']);

        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/send-to-finance")->assertStatus(403);
        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/send-to-finance")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'CONTRATADO');
    }

    public function test_finance_cannot_pay_advance_before_sent_to_finance(): void
    {
        $project = $this->projectWithSelection('APROBADO_PRESIDENCIA');

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 100])
            ->assertStatus(422);
    }
}
