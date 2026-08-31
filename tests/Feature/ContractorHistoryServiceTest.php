<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\ProjectProposal;
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

    public function test_custom_products_lists_only_custom_origin_products_with_price_trend(): void
    {
        $customProduct = MaterialCatalog::factory()->create(['name' => 'Rejilla a medida', 'is_custom_origin' => true]);
        $catalogProduct = MaterialCatalog::factory()->create(['name' => 'Cemento', 'is_custom_origin' => false]);
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($customProduct, $contractor, 100.0, now()->subMonths(2));
        $this->createPriceHistoryRow($customProduct, $contractor, 130.0, now()->subMonths(1));
        $this->createPriceHistoryRow($catalogProduct, $contractor, 50.0, now()->subMonths(1));

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertCount(1, $history['customProducts']);
        $this->assertSame('Rejilla a medida', $history['customProducts'][0]['productName']);
        $this->assertSame(2, $history['customProducts'][0]['quoteCount']);
        $this->assertSame(100.0, $history['customProducts'][0]['firstPriceUsd']);
        $this->assertSame(130.0, $history['customProducts'][0]['lastPriceUsd']);
        $this->assertSame(30.0, $history['customProducts'][0]['variationPercent']);
        $this->assertSame(1, $history['stats']['customProductCount']);
    }

    public function test_custom_products_empty_when_supplier_has_no_custom_origin_quotes(): void
    {
        $catalogProduct = MaterialCatalog::factory()->create(['is_custom_origin' => false]);
        $contractor = Contractor::factory()->create();

        $this->createPriceHistoryRow($catalogProduct, $contractor, 50.0, now()->subMonths(1));

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertSame([], $history['customProducts']);
        $this->assertSame(0, $history['stats']['customProductCount']);
    }

    public function test_project_history_lists_projects_bid_on_and_flags_the_awarded_one(): void
    {
        $contractor = Contractor::factory()->create();
        $awardedProject = Project::factory()->create(['title' => 'Ampliación de galpón']);
        $lostProject = Project::factory()->create(['title' => 'Pintura de fachada']);

        $awardedProposal = ProjectProposal::factory()->create([
            'project_id' => $awardedProject->id,
            'contractor_code' => $contractor->code,
            'fecha_oferta' => now()->subDays(10)->toDateString(),
        ]);
        $awardedProject->update(['selected_proposal_id' => $awardedProposal->id, 'selected_contractor_code' => $contractor->code]);

        ProjectProposal::factory()->create([
            'project_id' => $lostProject->id,
            'contractor_code' => $contractor->code,
            'fecha_oferta' => now()->subDays(5)->toDateString(),
        ]);

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertCount(2, $history['projects']);
        $this->assertSame(2, $history['stats']['totalProjectsBidOn']);
        $this->assertSame(1, $history['stats']['awardedProjectCount']);

        $awardedEntry = collect($history['projects'])->firstWhere('projectId', $awardedProject->id);
        $this->assertTrue($awardedEntry['isAwarded']);
        $lostEntry = collect($history['projects'])->firstWhere('projectId', $lostProject->id);
        $this->assertFalse($lostEntry['isAwarded']);

        // No debe filtrar montos de pago/costo — deliberadamente fuera de alcance.
        $this->assertArrayNotHasKey('totalCost', $awardedEntry);
        $this->assertArrayNotHasKey('materialCost', $awardedEntry);
    }

    public function test_project_history_flags_superseded_and_withdrawn_proposals(): void
    {
        $contractor = Contractor::factory()->create();
        $project = Project::factory()->create();

        $original = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
        ]);
        $renegotiated = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
            'origen' => 'RENEGOCIACION',
        ]);
        $original->update(['replaced_by_id' => $renegotiated->id]);

        $withdrawn = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
        ]);
        $withdrawn->delete();

        $history = $this->service->getSupplierHistory($contractor->code, 6);
        $byProposalId = collect($history['projects'])->keyBy('proposalId');

        $this->assertTrue($byProposalId[$original->id]['isSuperseded']);
        $this->assertFalse($byProposalId[$renegotiated->id]['isSuperseded']);
        $this->assertTrue($byProposalId[$withdrawn->id]['isWithdrawn']);
    }

    public function test_project_history_empty_when_contractor_never_bid(): void
    {
        $contractor = Contractor::factory()->create();

        $history = $this->service->getSupplierHistory($contractor->code, 6);

        $this->assertSame([], $history['projects']);
        $this->assertSame(0, $history['stats']['totalProjectsBidOn']);
        $this->assertSame(0, $history['stats']['awardedProjectCount']);
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
