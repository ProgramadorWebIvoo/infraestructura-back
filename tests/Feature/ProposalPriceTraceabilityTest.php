<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectProposal;
use App\Support\ProposalMaterialItemsNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalPriceTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        MaterialCatalog::factory()->create(['id' => 7, 'name' => 'Cemento']);
        Contractor::factory()->create(['code' => 'PROV-001', 'name' => 'Proveedor Test']);
        $this->project = Project::factory()->create(['id' => 'proj-t']);
        ProjectMaterial::create([
            'id' => 'm-1', 'project_id' => 'proj-t', 'material_catalog_id' => 7,
            'name' => 'Cemento', 'quantity' => 10, 'unit' => 'saco', 'estimated_unit_price' => 5,
        ]);
    }

    private function apiPayloadItems(): array
    {
        // Forma real que guarda la API: camelCase y sin catalogProductId.
        return [
            ['materialName' => 'Cemento', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 6, 'totalPrice' => 60],
            ['materialName' => 'Item libre', 'quantity' => 1, 'unit' => 'u', 'unitPrice' => 9, 'totalPrice' => 9],
        ];
    }

    private function makeProposal(array $items): ProjectProposal
    {
        return ProjectProposal::create([
            'id' => 'prop-t', 'project_id' => 'proj-t', 'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test', 'material_cost' => 69, 'labor_cost' => 0,
            'total_cost' => 69, 'delivery_weeks' => 1, 'negotiated_advance_percent' => 30,
            'description' => 't', 'origen' => 'MANUAL', 'fecha_oferta' => now(),
            'material_items' => $items,
        ]);
    }

    public function test_normalizer_links_catalog_id_by_material_name_and_skips_custom_items(): void
    {
        $items = ProposalMaterialItemsNormalizer::withCatalogIds($this->project, $this->apiPayloadItems());

        $this->assertSame(7, $items[0]['catalogProductId']);
        $this->assertArrayNotHasKey('catalogProductId', $items[1]);
    }

    public function test_normalized_api_proposal_writes_unit_price_to_history_with_project_and_quantity(): void
    {
        $items = ProposalMaterialItemsNormalizer::withCatalogIds($this->project, $this->apiPayloadItems());
        $proposal = $this->makeProposal($items);

        $rows = ProductPriceHistory::where('project_proposal_id', $proposal->id)->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(6, $rows[0]->price_usd);
        $this->assertEquals(10, $rows[0]->quantity);
        $this->assertSame('proj-t', $rows[0]->project_id);
        $this->assertSame(7, $rows[0]->catalog_product_id);
    }

    public function test_resync_command_repairs_legacy_proposals_idempotently(): void
    {
        // Propuesta "legacy": ítems sin catalogProductId => no llegó al histórico.
        $proposal = $this->makeProposal($this->apiPayloadItems());
        $this->assertSame(0, ProductPriceHistory::where('project_proposal_id', $proposal->id)->count());

        $this->artisan('price-history:resync-proposals')->assertSuccessful();
        $this->artisan('price-history:resync-proposals')->assertSuccessful();

        $rows = ProductPriceHistory::where('project_proposal_id', $proposal->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(6, $rows[0]->price_usd);
    }
}
