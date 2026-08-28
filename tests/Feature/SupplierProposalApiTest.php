<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\SupplierMaterialProposal;
use App\Models\SupplierMaterialProposalLine;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use App\Models\Contractor;
use App\Models\User;
use Carbon\Carbon;

class SupplierProposalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor = Contractor::factory()->create([
            'code' => 'SUPPLIER-001',
            'name' => 'Test Supplier',
        ]);

        $this->catalog = MaterialCatalog::factory()->create(['name' => 'Acero A-36']);
        $this->proposal = SupplierMaterialProposal::factory()->create([
            'supplier_name' => $this->contractor->code,
        ]);

        // Crear usuario admin para autenticación
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    /** @test */
    public function api_returns_estimation_fields_in_proposal_lines()
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
            'supplier_code' => $this->contractor->code,
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear línea nueva
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

        // SIMULATE: Si fuera un GET /api/proposals/{id}/lines/{lineId}
        // Verificar que los datos se serializan correctamente
        $newLine->refresh();

        $serialized = [
            'estimated_price_usd' => $newLine->estimated_price_usd,
            'estimated_price_source' => $newLine->estimated_price_source,
            'estimated_price_display' => $newLine->estimated_price_display,
            'variation_percent' => $newLine->variation_percent,
            'variation_direction' => $newLine->variation_direction,
            'variation_label' => $newLine->variation_label,
            'variation_badge_color' => $newLine->variation_badge_color,
        ];

        // ASSERT
        $this->assertEquals(100, $serialized['estimated_price_usd']);
        $this->assertEquals('historical_avg', $serialized['estimated_price_source']);
        $this->assertEquals('$100.00', $serialized['estimated_price_display']);
        $this->assertEqualsWithDelta(10.0, $serialized['variation_percent'], 0.01);
        $this->assertEquals('increase', $serialized['variation_direction']);
        $this->assertEquals('+10.00%', $serialized['variation_label']);
        $this->assertEquals('danger', $serialized['variation_badge_color']);
    }

    /** @test */
    public function api_serializes_multiple_lines_with_different_variations()
    {
        // SETUP: Histórico
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
            'supplier_code' => $this->contractor->code,
            'supplier_material_proposal_line_id' => $historicalLine->id,
            'price_usd' => 100,
            'original_currency' => 'USD',
            'original_price' => 100,
            'fx_rate_to_usd' => 1.0,
            'fx_rate_source' => 'usd_only',
            'quoted_at' => Carbon::now()->subMonth(),
        ]);

        // ACTION: Crear 3 líneas con diferentes precios
        $lines = [
            ['price' => 110, 'direction' => 'increase'],  // +10% (> 5%)
            ['price' => 85, 'direction' => 'decrease'],   // -15% (< -5%)
            ['price' => 102, 'direction' => 'stable'],    // +2% (within ±5%)
        ];

        foreach ($lines as $data) {
            SupplierMaterialProposalLine::create([
                'supplier_material_proposal_id' => $this->proposal->id,
                'catalog_product_id' => $this->catalog->id,
                'condition_status' => 'new',
                'quote_currency' => 'USD',
                'fx_rate_to_usd' => 1.0,
                'unit_price' => $data['price'],
                'unit_price_usd' => $data['price'],
                'quantity' => 10,
                'unit' => 'unidad',
                'technical_specs' => [],
            ]);
        }

        // ASSERT: Todos se guardaron con dirección correcta
        $allLines = SupplierMaterialProposalLine::where('supplier_material_proposal_id', $this->proposal->id)
            ->where('id', '!=', $historicalLine->id)
            ->orderBy('unit_price_usd')
            ->get();

        $this->assertCount(3, $allLines);

        // Primera: $95 (decrease)
        $this->assertEquals('decrease', $allLines[0]->variation_direction);
        $this->assertEquals('success', $allLines[0]->variation_badge_color);

        // Segunda: $102 (stable)
        $this->assertEquals('stable', $allLines[1]->variation_direction);
        $this->assertEquals('neutral', $allLines[1]->variation_badge_color);

        // Tercera: $110 (increase)
        $this->assertEquals('increase', $allLines[2]->variation_direction);
        $this->assertEquals('danger', $allLines[2]->variation_badge_color);
    }
}
