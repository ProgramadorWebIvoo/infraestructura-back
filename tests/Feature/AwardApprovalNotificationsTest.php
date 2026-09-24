<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Integración del circuito de aprobación con el sistema de notificaciones
 * real: reglas y listas blancas sembradas por las migraciones, sin mocks
 * del resolver ni de los settings.
 */
class AwardApprovalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $presidencia;
    private User $finanzas;
    private User $infra;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);

        $contractor = Contractor::factory()->create();
        $this->project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        ProjectProposal::factory()->create([
            'id' => 9001,
            'project_id' => $this->project->id,
            'contractor_code' => $contractor->code,
            'total_cost' => 1000,
        ]);
    }

    private function inbox(User $user, string $action): int
    {
        return AppNotification::where('user_id', $user->id)->where('action', $action)->count();
    }

    private function select(): void
    {
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/select-contractor", [
            'contractorCode' => $this->project->proposals()->first()->contractor_code,
            'proposalId' => 9001,
        ])->assertStatus(200);
    }

    public function test_selection_notifies_presidencia_by_app_and_mail_but_not_finanzas(): void
    {
        $this->select();

        $action = 'Seleccion de contratista pendiente de Presidencia';
        $this->assertSame(1, $this->inbox($this->presidencia, $action));
        $this->assertSame(0, $this->inbox($this->finanzas, $action));
        Notification::assertSentTo($this->presidencia, ProjectActionMail::class);
        Notification::assertNotSentTo($this->finanzas, ProjectActionMail::class);
    }

    public function test_finance_is_only_notified_when_procura_sends_to_finance(): void
    {
        $this->select();
        $this->assertSame(0, AppNotification::where('user_id', $this->finanzas->id)->count());

        $this->actingAs($this->presidencia)->postJson("/api/projects/{$this->project->id}/award-approval")->assertStatus(200);
        $this->assertSame(1, $this->inbox($this->procura, 'Aprobacion de adjudicacion por Presidencia'));
        $this->assertSame(0, AppNotification::where('user_id', $this->finanzas->id)->count());

        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/send-to-finance")->assertStatus(200);
        $this->assertSame(1, $this->inbox($this->finanzas, 'Confirmacion de contratacion'));
        $this->assertSame(1, $this->inbox($this->presidencia, 'Confirmacion de contratacion'));
        Notification::assertSentTo($this->finanzas, ProjectActionMail::class);
    }

    public function test_rejection_notifies_procura_by_app_and_mail(): void
    {
        $this->select();

        $this->actingAs($this->presidencia)
            ->postJson("/api/projects/{$this->project->id}/award-rejection", ['reason' => 'Monto excesivo'])
            ->assertStatus(200);

        $this->assertSame(1, $this->inbox($this->procura, 'Rechazo de adjudicacion por Presidencia'));
        Notification::assertSentTo($this->procura, ProjectActionMail::class);
    }

    public function test_batch_approval_notifies_procura_once_per_project(): void
    {
        $this->select();
        $other = Project::factory()->create(['status' => 'PENDIENTE_PRESIDENCIA']);

        $this->actingAs($this->presidencia)
            ->postJson('/api/projects/award-approvals/batch', ['projectIds' => [$this->project->id, $other->id]])
            ->assertStatus(200);

        $this->assertSame(2, $this->inbox($this->procura, 'Aprobacion de adjudicacion por Presidencia'));
    }
}
