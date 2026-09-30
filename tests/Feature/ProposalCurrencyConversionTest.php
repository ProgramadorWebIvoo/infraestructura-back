<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Propuestas cargadas a mano y renegociadas en una moneda distinta a la base:
 * el backend convierte y guarda original + tasa + moneda base, igual que el
 * import del portal de proveedores (EUR y USDT se comportan igual).
 */
class ProposalCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $analista;
    private Project $project;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
        $this->contractor = Contractor::factory()->create();
        $this->project = Project::factory()->confirmed()->create();

        // USD/EUR/USDT ya vienen sembradas por migración. Tasas en Bs.
        $at = now()->subMinute();
        foreach (['USD' => 800, 'EUR' => 900, 'USDT' => 1000] as $code => $rate) {
            ExchangeRate::create(['currency_code' => $code, 'rate_to_usd' => $rate, 'source' => 'TEST', 'effective_at' => $at]);
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'contractorCode' => $this->contractor->code,
            'materialCost' => 1000.00,
            'laborCost' => 200.00,
            'totalCost' => 1200.00,
            'deliveryWeeks' => 4,
            'negotiatedAdvancePercent' => 20,
            'description' => 'Oferta en otra moneda',
            'origen' => 'MANUAL',
            'fechaOferta' => '2026-07-01',
        ], $overrides);
    }

    public function test_manual_proposal_in_eur_is_converted_to_base_and_keeps_the_original(): void
    {
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload(['quoteCurrency' => 'EUR']))
            ->assertStatus(200);

        // 1 EUR = 900 / 800 = 1.125 USD
        $this->assertDatabaseHas('project_proposals', [
            'project_id' => $this->project->id,
            'quote_currency' => 'EUR',
            'material_cost' => 1125.00,
            'labor_cost' => 225.00,
            'total_cost' => 1350.00,
            'material_cost_original' => 1000.00,
            'labor_cost_original' => 200.00,
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.125,
            'base_currency_at_import' => 'USD',
        ]);
    }

    public function test_manual_proposal_in_usdt_is_converted_with_the_usdt_rate(): void
    {
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload(['quoteCurrency' => 'USDT']))
            ->assertStatus(200);

        // 1 USDT = 1000 / 800 = 1.25 USD
        $this->assertDatabaseHas('project_proposals', [
            'project_id' => $this->project->id,
            'quote_currency' => 'USDT',
            'total_cost' => 1500.00,
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.25,
            'base_currency_at_import' => 'USD',
        ]);
    }

    public function test_manual_proposal_in_the_base_currency_is_stored_as_is(): void
    {
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload())
            ->assertStatus(200);

        $this->assertDatabaseHas('project_proposals', [
            'project_id' => $this->project->id,
            'quote_currency' => null,
            'total_cost' => 1200.00,
            'total_cost_original' => null,
            'fx_rate_to_base' => null,
            'base_currency_at_import' => null,
        ]);
    }

    public function test_manual_proposal_rejects_an_unknown_currency(): void
    {
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload(['quoteCurrency' => 'XXXX']))
            ->assertStatus(422);
    }

    public function test_manual_proposal_fails_cleanly_when_the_currency_has_no_rate(): void
    {
        Currency::create(['code' => 'GBP', 'name' => 'Libra', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload(['quoteCurrency' => 'GBP']))
            ->assertStatus(422);

        $this->assertDatabaseCount('project_proposals', 0);
    }

    public function test_renegotiation_in_usdt_converts_and_compares_against_the_base_price(): void
    {
        $add = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals", $this->payload([
                'materialCost' => 1600.00,
                'laborCost' => 400.00,
                'totalCost' => 2000.00,
            ]))
            ->assertStatus(200);
        $originalId = $add->json('data.proposals')[0]['id'];

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$this->project->id}/proposals/{$originalId}/renegotiate", [
                'quoteCurrency' => 'USDT',
                'materialCost' => 1000.00,
                'laborCost' => 200.00,
                'totalCost' => 1200.00,
                'deliveryWeeks' => 4,
                'negotiatedAdvancePercent' => 20,
                'description' => 'Renegociada en USDT',
                'fechaOferta' => '2026-07-02',
                'motivo' => 'El proveedor ofreció pagar en USDT con descuento.',
            ])
            ->assertStatus(200);

        // 1200 USDT * 1.25 = 1500 USD; precio anterior 2000 USD
        $this->assertDatabaseHas('project_proposals', [
            'origen' => 'RENEGOCIACION',
            'quote_currency' => 'USDT',
            'total_cost' => 1500.00,
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.25,
            'precio_anterior' => 2000.00,
            'precio_nuevo' => 1500.00,
            'diferencia' => -500.00,
        ]);
    }
}
