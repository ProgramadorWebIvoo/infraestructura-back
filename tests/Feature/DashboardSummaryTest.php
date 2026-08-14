<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $presidencia;
    private User $superadmin;
    private User $finanzas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
    }

    private function buildProjectWithWinner(
        string $status,
        float $estimated,
        ?float $approved,
        ?ProjectProposal $winner = null,
        array $payments = []
    ): Project {
        $project = Project::factory()->create([
            'status' => $status,
            'estimated_total' => $estimated,
            'approved_investment_amount' => $approved,
            'created_date' => now()->subDays(20)->toDateString(),
        ]);

        if ($winner) {
            ProjectProposal::create([
                'id' => $winner->id,
                'project_id' => $project->id,
                'contractor_code' => $winner->contractor_code,
                'contractor_name_snapshot' => $winner->contractor_name_snapshot,
                'material_cost' => $winner->material_cost,
                'labor_cost' => $winner->labor_cost,
                'total_cost' => $winner->total_cost,
                'delivery_weeks' => $winner->delivery_weeks,
                'negotiated_advance_percent' => $winner->negotiated_advance_percent,
                'description' => 'Propuesta ganadora de prueba',
            ]);
            $project->update([
                'selected_contractor_code' => $winner->contractor_code,
                'selected_proposal_id' => $winner->id,
            ]);
            $project->refresh();
        }

        foreach ($payments as $payment) {
            ProjectPayment::create([
                'project_id' => $project->id,
                'proposal_id' => $winner?->id,
                'payment_type' => $payment['type'],
                'amount' => $payment['amount'],
                'paid_date' => now()->toDateString(),
            ]);
        }

        return $project;
    }

    public function test_requires_presidencia_or_superadmin_role(): void
    {
        $this->actingAs($this->finanzas)
            ->getJson('/api/dashboard/summary')
            ->assertForbidden();
    }

    public function test_returns_empty_summary_when_no_projects(): void
    {
        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertEquals(0, $response->json('totalProjects'));
        $this->assertEquals(0, $response->json('totalApprovedInvestment'));
        $this->assertEquals(0, $response->json('releasedPercent'));
        $this->assertCount(9, $response->json('funnel'));
    }

    public function test_aggregates_financials_and_funnel(): void
    {
        $contractor = Contractor::factory()->create(['rating' => 4.5]);
        $winner = new ProjectProposal([
            'id' => 'PROP-TEST-1',
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => 1000.00,
            'labor_cost' => 500.00,
            'total_cost' => 1500.00,
            'delivery_weeks' => 3,
            'negotiated_advance_percent' => 30.00,
        ]);

        // Contratado con anticipo pagado (committed) + pago final → exceso respecto al approval
        $this->buildProjectWithWinner('CONTRATADO', 1000.00, 1200.00, $winner, [
            ['type' => 'ADVANCE', 'amount' => 450.00],
        ]);
        // Completado y pagado
        $this->buildProjectWithWinner('COMPLETADO_PAGADO', 2000.00, 2500.00, null, [
            ['type' => 'ADVANCE', 'amount' => 750.00],
            ['type' => 'FINAL', 'amount' => 1750.00],
        ]);
        // Sin adjudicar, solo estimado
        Project::factory()->create([
            'status' => 'CREADO',
            'estimated_total' => 500.00,
            'approved_investment_amount' => null,
            'created_date' => now()->toDateString(),
        ]);
        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        // approved = 1200 + 2500 + 500 (fallback a estimated en CREADO)
        $this->assertEquals(4200.00, $response->json('totalApprovedInvestment'));
        // released = 450 + 750 + 1750
        $this->assertEquals(2950.00, $response->json('totalReleasedFunds'));
        // committed = solo el CONTRATADO (1500), no el completado
        $this->assertEquals(1500.00, $response->json('totalCommittedAmount'));
        $this->assertEquals(1250.00, $response->json('pendingFunds'));
        // 2950/4200*100 ≈ 70.2
        $this->assertEquals(70.2, $response->json('releasedPercent'));
        $this->assertEquals(0, $response->json('excessReleased'));
        $this->assertEquals(3, $response->json('totalProjects'));

        $funnel = collect($response->json('funnel'))->keyBy('status');
        $this->assertEquals(1, $funnel['CONTRATADO']['count']);
        $this->assertEquals(1500.00, $funnel['CONTRATADO']['committedAmount']);
        $this->assertEquals(1, $funnel['CREADO']['count']);
        $this->assertEquals(1, $funnel['COMPLETADO_PAGADO']['count']);

        // Top contractor
        $this->assertCount(1, $response->json('topContractors'));
        $this->assertEquals($contractor->code, $response->json('topContractors.0.contractorCode'));
        $this->assertEquals(1500.00, $response->json('topContractors.0.totalAmount'));

        // Negotiation metrics solo sobre ganadoras
        $this->assertEquals(30.00, $response->json('negotiationMetrics.avgAdvancePercent'));
        $this->assertEquals(3.0, $response->json('negotiationMetrics.avgDeliveryWeeks'));
    }

    public function test_detects_excess_released_over_approved(): void
    {
        $this->buildProjectWithWinner('COMPLETADO_PAGADO', 1000.00, 1000.00, null, [
            ['type' => 'ADVANCE', 'amount' => 700.00],
            ['type' => 'FINAL', 'amount' => 800.00],
        ]);

        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertEquals(500.00, $response->json('excessReleased'));
        $this->assertEquals(0, $response->json('pendingFunds'));
        $this->assertEquals(150.0, $response->json('releasedPercent'));
    }

    public function test_lists_stalled_projects_after_14_days_without_activity(): void
    {
        $old = Project::factory()->create([
            'status' => 'REVISADO_CIERRE',
            'created_date' => now()->subDays(40)->toDateString(),
            'updated_at' => now()->subDays(40),
        ]);
        Project::factory()->create([
            'status' => 'CREADO',
            'created_date' => now()->toDateString(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $stalled = $response->json('stalledProjects');
        $this->assertCount(1, $stalled);
        $this->assertEquals($old->id, $stalled[0]['id']);
        $this->assertEquals('REVISADO_CIERRE', $stalled[0]['status']);
        $this->assertGreaterThanOrEqual(14, $stalled[0]['daysSinceUpdate']);
    }

    public function test_stalled_threshold_is_configurable_via_app_setting(): void
    {
        // Proyecto con 10 días de inactividad: no estancado con el umbral
        // default (14), pero sí con un umbral configurado a 7.
        $project = Project::factory()->create([
            'status' => 'REVISADO_CIERRE',
            'created_date' => now()->subDays(10)->toDateString(),
            'updated_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();
        $this->assertCount(0, $response->json('stalledProjects'));

        AppSetting::where('key', 'proyecto_estancado_umbral_dias')->update(['value' => '7']);
        SettingsService::forget();

        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();
        $stalled = $response->json('stalledProjects');
        $this->assertCount(1, $stalled);
        $this->assertEquals($project->id, $stalled[0]['id']);
    }

    public function test_winner_falls_back_to_selected_contractor_code(): void
    {
        // Proyecto adjudicado solo por selected_contractor_code (sin selected_proposal_id):
        // la ganadora debe contarse igual en committed/top/negociación (misma regla que el espejo cliente).
        $contractor = Contractor::factory()->create(['rating' => 4.0]);
        $project = Project::factory()->create([
            'status' => 'EN_EJECUCION',
            'estimated_total' => 1000.00,
            'approved_investment_amount' => 1000.00,
            'created_date' => now()->subDays(5)->toDateString(),
            'selected_contractor_code' => $contractor->code,
            'selected_proposal_id' => null,
        ]);
        ProjectProposal::create([
            'id' => 'PROP-FALLBACK-1',
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => 800.00,
            'labor_cost' => 200.00,
            'total_cost' => 1000.00,
            'delivery_weeks' => 6,
            'negotiated_advance_percent' => 25.00,
            'description' => 'Propuesta ganadora por fallback',
        ]);

        $response = $this->actingAs($this->presidencia)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertEquals(1000.00, $response->json('totalCommittedAmount'));
        $this->assertCount(1, $response->json('topContractors'));
        $this->assertEquals($contractor->code, $response->json('topContractors.0.contractorCode'));
        $this->assertEquals(25.00, $response->json('negotiationMetrics.avgAdvancePercent'));
        $this->assertEquals(6.0, $response->json('negotiationMetrics.avgDeliveryWeeks'));
    }

    public function test_type_and_location_breakdown_and_monthly_trend(): void
    {
        Project::factory()->create([
            'type' => 'INFRAESTRUCTURA',
            'location' => 'Caracas',
            'estimated_total' => 1000.00,
            'approved_investment_amount' => 1000.00,
            'created_date' => '2026-05-10',
            'status' => 'CREADO',
        ]);
        Project::factory()->create([
            'type' => 'MANTENIMIENTO',
            'location' => 'Valencia',
            'estimated_total' => 2000.00,
            'approved_investment_amount' => 2000.00,
            'created_date' => '2026-06-15',
            'status' => 'CREADO',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $types = collect($response->json('typeBreakdown'))->keyBy('type');
        $this->assertEquals(1000.00, $types['INFRAESTRUCTURA']['approvedAmount']);
        $this->assertEquals(2000.00, $types['MANTENIMIENTO']['approvedAmount']);

        $locations = collect($response->json('locationBreakdown'))->keyBy('location');
        $this->assertEquals(1, $locations['Caracas']['count']);

        $months = collect($response->json('monthlyTrend'))->keyBy('month');
        $this->assertEquals(1, $months['2026-05']['count']);
        $this->assertEquals(1, $months['2026-06']['count']);
    }
}
