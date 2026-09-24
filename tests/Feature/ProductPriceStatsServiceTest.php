<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Services\ProductPriceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre el histórico de productos de Fase 4 (último, mínimo, máximo,
 * promedio, variación, %cambio) — mismo patrón de setup de datos crudos
 * sobre product_price_history que PriceEstimationServiceTest.
 */
class ProductPriceStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductPriceStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductPriceStatsService::class);
    }

    private function insertRow(
        MaterialCatalog $product,
        Contractor $contractor,
        float $priceUsd,
        \DateTimeInterface $quotedAt,
        ?float $quantity = null,
        ?string $projectId = null
    ): void {
        DB::table('product_price_history')->insert([
            'catalog_product_id' => $product->id,
            'supplier_code' => $contractor->code,
            'quantity' => $quantity,
            'price_usd' => $priceUsd,
            'original_currency' => 'USD',
            'original_price' => $priceUsd,
            'fx_rate_to_usd' => 1,
            'fx_rate_source' => 'test',
            'quoted_at' => $quotedAt,
            'created_at' => now(),
            'origin' => 'PORTAL_PROV',
            'project_id' => $projectId,
        ]);
    }

    public function test_computes_last_min_max_avg_and_variation(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->insertRow($product, $contractor, 100.0, now()->subDays(30), quantity: 5);
        $this->insertRow($product, $contractor, 80.0, now()->subDays(20), quantity: 3);
        $this->insertRow($product, $contractor, 120.0, now()->subDays(10), quantity: 4);

        $stats = $this->service->getStats($product->id);

        $this->assertSame(120.0, $stats['lastPriceUsd']);
        $this->assertSame(80.0, $stats['minPriceUsd']);
        $this->assertSame(120.0, $stats['maxPriceUsd']);
        $this->assertEqualsWithDelta(100.0, $stats['avgPriceUsd'], 0.01);
        $this->assertEqualsWithDelta(40.0, $stats['variationUsd'], 0.01);
        $this->assertEqualsWithDelta(50.0, $stats['variationPercent'], 0.01);
        $this->assertSame(3, $stats['dataPoints']);
        $this->assertCount(3, $stats['series']);
    }

    public function test_returns_null_variation_with_single_data_point(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->insertRow($product, $contractor, 50.0, now());

        $stats = $this->service->getStats($product->id);

        $this->assertSame(50.0, $stats['lastPriceUsd']);
        $this->assertNull($stats['variationUsd']);
        $this->assertNull($stats['variationPercent']);
    }

    public function test_returns_empty_stats_when_no_history(): void
    {
        $product = MaterialCatalog::factory()->create();

        $stats = $this->service->getStats($product->id);

        $this->assertSame(0, $stats['dataPoints']);
        $this->assertNull($stats['lastPriceUsd']);
        $this->assertSame([], $stats['series']);
    }

    public function test_filters_by_supplier_code(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractorA = Contractor::factory()->create();
        $contractorB = Contractor::factory()->create();

        $this->insertRow($product, $contractorA, 10.0, now()->subDays(2));
        $this->insertRow($product, $contractorB, 999.0, now()->subDays(1));

        $stats = $this->service->getStats($product->id, supplierCode: $contractorA->code);

        $this->assertSame(1, $stats['dataPoints']);
        $this->assertSame(10.0, $stats['lastPriceUsd']);
    }

    public function test_filters_by_project_id(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();
        $project = Project::factory()->create();

        $this->insertRow($product, $contractor, 10.0, now()->subDays(2), projectId: $project->id);
        $this->insertRow($product, $contractor, 999.0, now()->subDays(1), projectId: null);

        $stats = $this->service->getStats($product->id, projectId: $project->id);

        $this->assertSame(1, $stats['dataPoints']);
        $this->assertSame(10.0, $stats['lastPriceUsd']);
    }
}
