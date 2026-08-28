<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\SupplierMaterialProposal;
use App\Services\PriceEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vive en Feature (no Unit) porque PriceEstimationService lee de BD
 * (product_price_history, catalog_product_suppliers) — necesita
 * RefreshDatabase. Cubre el gap señalado en ARQUITECTURA_BACKEND.md: el
 * servicio no tenía tests propios pese a ser central en el cálculo de EST.
 */
class PriceEstimationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceEstimationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PriceEstimationService::class);
    }

    private function createPriceHistoryRow(MaterialCatalog $product, Contractor $contractor, float $priceUsd, \DateTimeInterface $quotedAt): void
    {
        $proposal = SupplierMaterialProposal::factory()->create();
        $line = $proposal->lines()->create([
            'catalog_product_id' => $product->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1,
            'unit_price' => $priceUsd,
            'unit_price_usd' => $priceUsd,
            'quantity' => 1,
            'unit' => 'unidad',
            'technical_specs' => [],
            'warranty_description' => 'N/A',
        ]);

        DB::table('product_price_history')->insert([
            'catalog_product_id' => $product->id,
            'supplier_code' => $contractor->code,
            'supplier_material_proposal_line_id' => $line->id,
            'price_usd' => $priceUsd,
            'original_currency' => 'USD',
            'original_price' => $priceUsd,
            'fx_rate_to_usd' => 1,
            'fx_rate_source' => 'test',
            'quoted_at' => $quotedAt,
            'created_at' => now(),
        ]);
    }

    public function test_returns_historical_average_when_recent_data_exists(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(1));
        $this->createPriceHistoryRow($product, $contractor, 200.0, now()->subMonths(2));

        $estimate = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);

        $this->assertNotNull($estimate);
        $this->assertSame('historical_avg', $estimate->source);
        $this->assertSame(150.0, $estimate->value);
        $this->assertSame(2, $estimate->dataPoints);
    }

    public function test_ignores_data_older_than_months_back_window(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($product, $contractor, 999.0, now()->subMonths(8));

        $estimate = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);

        // Sin datos dentro de la ventana y sin fila en catalog_product_suppliers: null.
        $this->assertNull($estimate);
    }

    public function test_falls_back_to_last_quoted_price_when_no_recent_history(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        DB::table('catalog_product_suppliers')->insert([
            'catalog_product_id' => $product->id,
            'supplier_code' => $contractor->code,
            'last_quoted_at' => now()->subDays(1),
            'last_quoted_price_usd' => 75.5,
            'quote_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $estimate = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);

        $this->assertNotNull($estimate);
        $this->assertSame('last_quoted', $estimate->source);
        $this->assertSame(75.5, $estimate->value);
    }

    public function test_returns_null_when_no_data_at_all(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $estimate = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);

        $this->assertNull($estimate);
    }

    public function test_caches_result_and_does_not_recompute_on_second_call(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(1));

        $first = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);
        $this->assertSame(100.0, $first->value);

        // Nueva cotización que NO debería reflejarse — sigue sirviendo el
        // valor cacheado hasta que expire el TTL de 24h.
        $this->createPriceHistoryRow($product, $contractor, 500.0, now()->subDays(1));

        $second = $this->service->getEstimatedPrice($product->id, $contractor->code, 6);
        $this->assertSame(100.0, $second->value, 'El segundo llamado debe servir desde caché, no recalcular.');
    }
}
