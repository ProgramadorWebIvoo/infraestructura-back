<?php

namespace Tests\Feature;

use App\Models\ProjectProposal;
use App\Models\ProductPriceHistory;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\MaterialCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectProposalObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear datos base necesarios
        MaterialCatalog::factory()->create(['id' => 1, 'name' => 'Producto Test']);
        Project::factory()->create(['id' => 'proj-1']);
        Contractor::factory()->create(['code' => 'PROV-001', 'name' => 'Proveedor Test']);
    }

    /**
     * Verifica que una propuesta MANUAL se sincroniza a product_price_history.
     */
    public function test_manual_proposal_syncs_to_price_history(): void
    {
        $proposal = ProjectProposal::create([
            'id' => 'prop-1',
            'project_id' => 'proj-1',
            'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test',
            'material_cost' => 1000,
            'labor_cost' => 500,
            'total_cost' => 1500,
            'delivery_weeks' => 2,
            'negotiated_advance_percent' => 30,
            'description' => 'Test proposal',
            'origen' => 'MANUAL',
            'fecha_oferta' => now(),
            'quote_currency' => 'USD',
            'material_items' => [
                [
                    'catalog_product_id' => 1,
                    'material_name' => 'Producto Test',
                    'quantity' => 10,
                    'unit' => 'unidad',
                    'unit_price' => 100,
                    'total_price' => 1000,
                ]
            ],
        ]);

        // Verificar que se creó un registro en product_price_history
        $history = ProductPriceHistory::where('project_proposal_id', $proposal->id)
            ->where('catalog_product_id', 1)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('PROV-001', $history->supplier_code);
        $this->assertEquals(1000, $history->price_usd);
        $this->assertEquals('USD', $history->original_currency);
        $this->assertEquals('PROJECT_PROPOSAL', $history->origin);
    }

    /**
     * Verifica que una propuesta EUR se sincroniza correctamente.
     */
    public function test_renegotiation_proposal_with_eur_syncs_correctly(): void
    {
        $proposal = ProjectProposal::create([
            'id' => 'prop-2',
            'project_id' => 'proj-1',
            'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test',
            'material_cost' => 917, // 1000 EUR @ 0.917
            'labor_cost' => 459, // 500 EUR @ 0.917
            'total_cost' => 1376, // Convertido a USD aprox
            'delivery_weeks' => 2,
            'negotiated_advance_percent' => 30,
            'description' => 'Renegotiation in EUR',
            'origen' => 'RENEGOCIACION',
            'fecha_oferta' => now(),
            'quote_currency' => 'EUR',
            'fx_rate_to_base' => 0.917, // EUR a USD
            'material_items' => [
                [
                    'catalog_product_id' => 1,
                    'material_name' => 'Producto Test',
                    'quantity' => 10,
                    'unit' => 'unidad',
                    'unit_price' => 100,
                    'total_price' => 1000,
                ]
            ],
        ]);

        $history = ProductPriceHistory::where('project_proposal_id', $proposal->id)
            ->where('catalog_product_id', 1)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('EUR', $history->original_currency);
        $this->assertEquals(1000, $history->original_price); // Precio en EUR
        $this->assertEquals(917, $history->price_usd); // Convertido aproximadamente
        $this->assertEquals(0.917, $history->fx_rate_to_usd);
    }

    /**
     * Verifica que propuestas sin material_items no se sincronizan.
     */
    public function test_proposal_without_material_items_not_synced(): void
    {
        $proposal = ProjectProposal::create([
            'id' => 'prop-3',
            'project_id' => 'proj-1',
            'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test',
            'material_cost' => 1000,
            'labor_cost' => 500,
            'total_cost' => 1500,
            'delivery_weeks' => 2,
            'negotiated_advance_percent' => 30,
            'description' => 'No items',
            'origen' => 'MANUAL',
            'fecha_oferta' => now(),
            'material_items' => [], // Vacío
        ]);

        $history = ProductPriceHistory::where('project_proposal_id', $proposal->id)->exists();
        $this->assertFalse($history);
    }

    /**
     * Verifica que propuestas con material_items sin catalog_product_id se omiten.
     */
    public function test_items_without_catalog_product_id_are_skipped(): void
    {
        $proposal = ProjectProposal::create([
            'id' => 'prop-4',
            'project_id' => 'proj-1',
            'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test',
            'material_cost' => 1000,
            'labor_cost' => 500,
            'total_cost' => 1500,
            'delivery_weeks' => 2,
            'negotiated_advance_percent' => 30,
            'description' => 'No catalog ID',
            'origen' => 'MANUAL',
            'fecha_oferta' => now(),
            'material_items' => [
                [
                    'material_name' => 'Custom Item',
                    'quantity' => 5,
                    'unit' => 'unidad',
                    'unit_price' => 200,
                    'total_price' => 1000,
                    // Sin catalog_product_id
                ]
            ],
        ]);

        $history = ProductPriceHistory::where('project_proposal_id', $proposal->id)->exists();
        $this->assertFalse($history);
    }

    /**
     * Verifica que actualizar una propuesta resincroniza los datos.
     */
    public function test_proposal_update_resync_price_history(): void
    {
        $proposal = ProjectProposal::create([
            'id' => 'prop-5',
            'project_id' => 'proj-1',
            'contractor_code' => 'PROV-001',
            'contractor_name_snapshot' => 'Proveedor Test',
            'material_cost' => 1000,
            'labor_cost' => 500,
            'total_cost' => 1500,
            'delivery_weeks' => 2,
            'negotiated_advance_percent' => 30,
            'description' => 'Test',
            'origen' => 'MANUAL',
            'fecha_oferta' => now(),
            'quote_currency' => 'USD',
            'material_items' => [
                [
                    'catalog_product_id' => 1,
                    'material_name' => 'Producto Test',
                    'quantity' => 10,
                    'unit' => 'unidad',
                    'unit_price' => 100,
                    'total_price' => 1000,
                ]
            ],
        ]);

        $originalHistory = ProductPriceHistory::where('project_proposal_id', $proposal->id)->count();
        $this->assertEquals(1, $originalHistory);

        // Actualizar la propuesta
        $proposal->update([
            'quote_currency' => 'EUR',
            'fx_rate_to_base' => 0.917,
        ]);

        // Verificar que se resincronizó
        $updatedHistory = ProductPriceHistory::where('project_proposal_id', $proposal->id)->first();
        $this->assertEquals('EUR', $updatedHistory->original_currency);
        $this->assertEquals(0.917, $updatedHistory->fx_rate_to_usd);
    }
}
