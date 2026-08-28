<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\SupplierMaterialProposal;
use App\Models\SupplierMaterialProposalLine;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use App\Models\Contractor;
use Carbon\Carbon;

class PriceEstimationObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear contratista (proveedor)
        $this->contractor = Contractor::factory()->create([
            'code' => 'SUPPLIER-001',
            'name' => 'Test Supplier',
        ]);

        $this->catalog = MaterialCatalog::factory()->create(['name' => 'Acero A-36']);
        $this->proposal = SupplierMaterialProposal::factory()->create([
            'supplier_name' => $this->contractor->code,
        ]);
    }

    /** @test */
    public function observer_calculates_estimated_price_on_line_creation()
    {
        // SETUP: Crear 3 líneas históricas (simular propuestas anteriores con precios)
        // Cada una crea un entry en ProductPriceHistory
        $historicalLine1 = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 100,
            'unit_price_usd' => 100,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // Crear histórico para este proveedor/producto
        ProductPriceHistory::create([
            'catalog_product_id' => $this->catalog->id,
            'supplier_code' => $this->contractor->code,
            'supplier_material_proposal_line_id' => $historicalLine1->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea nueva con precio $105
        $newLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 105,
            'unit_price_usd' => 105,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT: Observer debe haber calculado EST
        $newLine->refresh();
        $this->assertNotNull($newLine->estimated_price_usd);
        $this->assertEquals(100, $newLine->estimated_price_usd);
        $this->assertEquals('historical_avg', $newLine->estimated_price_source);
    }

    /** @test */
    public function observer_calculates_variation_increase()
    {
        // SETUP: Crear histórico
        $historicalLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 100,
            'unit_price_usd' => 100,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        ProductPriceHistory::create([
            'catalog_product_id' => $this->catalog->id,
            'supplier_code' => $this->proposal->supplier_code ?? 'SUPPLIER-001',
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea con precio $110 (10% aumento)
        $newLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 110,
            'unit_price_usd' => 110,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT
        $newLine->refresh();
        $this->assertNotNull($newLine->variation_percent);
        $this->assertEqualsWithDelta(10.0, $newLine->variation_percent, 0.01);
        $this->assertEquals('increase', $newLine->variation_direction);
    }

    /** @test */
    public function observer_calculates_variation_decrease()
    {
        // SETUP
        $historicalLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 100,
            'unit_price_usd' => 100,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        ProductPriceHistory::create([
            'catalog_product_id' => $this->catalog->id,
            'supplier_code' => $this->proposal->supplier_code ?? 'SUPPLIER-001',
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea con precio $85 (15% disminución)
        $newLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 85,
            'unit_price_usd' => 85,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT
        $newLine->refresh();
        $this->assertEqualsWithDelta(-15.0, $newLine->variation_percent, 0.01);
        $this->assertEquals('decrease', $newLine->variation_direction);
    }

    /** @test */
    public function observer_marks_stable_when_within_threshold()
    {
        // SETUP
        $historicalLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 100,
            'unit_price_usd' => 100,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        ProductPriceHistory::create([
            'catalog_product_id' => $this->catalog->id,
            'supplier_code' => $this->proposal->supplier_code ?? 'SUPPLIER-001',
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea con precio $103 (3% aumento, dentro del ±5%)
        $newLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 103,
            'unit_price_usd' => 103,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT
        $newLine->refresh();
        $this->assertEquals('stable', $newLine->variation_direction);
    }

    /** @test */
    public function observer_skips_custom_products()
    {
        // ACTION: Crear línea sin catalog_product_id (producto personalizado)
        $line = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => null,
            'custom_product_name' => 'Producto Personalizado',
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 999,
            'unit_price_usd' => 999,
            'quantity' => 1,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT: No debe haber calculado EST
        $line->refresh();
        $this->assertNull($line->estimated_price_usd);
        $this->assertNull($line->variation_percent);
    }

    /** @test */
    public function api_resource_includes_estimation_fields()
    {
        // SETUP
        $historicalLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 100,
            'unit_price_usd' => 100,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        ProductPriceHistory::create([
            'catalog_product_id' => $this->catalog->id,
            'supplier_code' => $this->proposal->supplier_code ?? 'SUPPLIER-001',
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea
        $newLine = SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $this->proposal->id,
            'catalog_product_id' => $this->catalog->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1.0,
            'unit_price' => 110,
            'unit_price_usd' => 110,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);

        // ASSERT: Atributos computed están disponibles
        $newLine->refresh();
        $this->assertNotNull($newLine->estimated_price_display);
        $this->assertNotNull($newLine->variation_label);
        $this->assertEquals('danger', $newLine->variation_badge_color);
        $this->assertStringContainsString('$', $newLine->estimated_price_display);
        $this->assertStringContainsString('%', $newLine->variation_label);
    }
}
