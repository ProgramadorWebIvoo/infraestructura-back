<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\SupplierMaterialProposal;
use App\Services\ContractorHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vive en Feature (no Unit) por la misma razón que PriceEstimationServiceTest:
 * ContractorHistoryService lee de product_price_history + material_catalog
 * vía query builder, necesita RefreshDatabase.
 */
class ContractorHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractorHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContractorHistoryService::class);
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

    public function test_builds_monthly_series_with_gaps_filled(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(1));
        $this->createPriceHistoryRow($product, $contractor, 120.0, now()->subMonths(1));
        $this->createPriceHistoryRow($product, $contractor, 200.0, now()->subMonths(3));

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertCount(6, $history['monthlySeries']);

        $monthWithTwoQuotes = now()->subMonths(1)->format('Y-m');
        $entry = collect($history['monthlySeries'])->firstWhere('month', $monthWithTwoQuotes);
        $this->assertSame(2, $entry['quoteCount']);
        $this->assertSame(110.0, $entry['avgPriceUsd']);

        $emptyMonth = now()->subMonths(5)->format('Y-m');
        $emptyEntry = collect($history['monthlySeries'])->firstWhere('month', $emptyMonth);
        $this->assertSame(0, $emptyEntry['quoteCount']);
        $this->assertNull($emptyEntry['avgPriceUsd']);
    }

    public function test_top_products_ranked_by_quote_count_with_variation(): void
    {
        $productA = MaterialCatalog::factory()->create(['name' => 'Cemento']);
        $productB = MaterialCatalog::factory()->create(['name' => 'Acero']);
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($productA, $contractor, 100.0, now()->subMonths(2));
        $this->createPriceHistoryRow($productA, $contractor, 110.0, now()->subMonths(1));
        $this->createPriceHistoryRow($productB, $contractor, 50.0, now()->subMonths(1));

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertCount(2, $history['topProducts']);
        $this->assertSame('Cemento', $history['topProducts'][0]['productName']);
        $this->assertSame(2, $history['topProducts'][0]['quoteCount']);
        $this->assertSame(10.0, $history['topProducts'][0]['variationPercent']);
    }

    public function test_stats_include_contractor_rating_and_totals(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create(['rating' => 4.5]);

        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(1));
        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(2));

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertSame($contractor->code, $history['stats']['contractorCode']);
        $this->assertSame($contractor->name, $history['stats']['contractorName']);
        $this->assertSame(4.5, $history['stats']['rating']);
        $this->assertSame(2, $history['stats']['totalQuoteCount']);
        $this->assertSame(1, $history['stats']['distinctProductCount']);
    }

    public function test_returns_empty_series_when_no_history(): void
    {
        $contractor = Contractor::factory()->create();

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertCount(6, $history['monthlySeries']);
        $this->assertSame([], $history['topProducts']);
        $this->assertSame(0, $history['stats']['totalQuoteCount']);
        $this->assertNull($history['stats']['trendPercent']);
    }

    public function test_caches_result_and_does_not_recompute_on_second_call(): void
    {
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($product, $contractor, 100.0, now()->subMonths(1));

        $first = $this->service->getSupplierHistory($contractor->code, 6);
        $this->assertSame(1, $first['stats']['totalQuoteCount']);

        // Nueva cotización que NO debería reflejarse hasta bump del CacheVersion.
        $this->createPriceHistoryRow($product, $contractor, 500.0, now()->subDays(1));

        $second = $this->service->getSupplierHistory($contractor->code, 6);
        $this->assertSame(1, $second['stats']['totalQuoteCount'], 'El segundo llamado debe servir desde caché.');

        \App\Support\CacheVersion::bump('contractor_history:' . $contractor->code);

        $third = $this->service->getSupplierHistory($contractor->code, 6);
        $this->assertSame(2, $third['stats']['totalQuoteCount'], 'Tras bump del CacheVersion debe recalcular.');
    }
}
