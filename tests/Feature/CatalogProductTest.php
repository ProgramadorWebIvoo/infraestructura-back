<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogProductSupplier;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_authorized_role_cannot_list_catalog(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $this->actingAs($user)->getJson('/api/catalog/products')->assertStatus(403);
    }

    public function test_presidencia_can_list_catalog_products(): void
    {
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson('/api/catalog/products');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Cemento Portland');
    }

    public function test_filters_by_category(): void
    {
        $cementCategory = CatalogCategory::create(['name' => 'Cemento']);
        $steelCategory = CatalogCategory::create(['name' => 'Acero']);
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true, 'category_id' => $cementCategory->id]);
        MaterialCatalog::create(['name' => 'Varilla 3/8', 'unit' => 'unidad', 'estimated_unit_price' => 5, 'is_active' => true, 'category_id' => $steelCategory->id]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson("/api/catalog/products?category_id={$cementCategory->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Cemento Portland');
    }

    public function test_filters_by_search(): void
    {
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        MaterialCatalog::create(['name' => 'Varilla 3/8', 'unit' => 'unidad', 'estimated_unit_price' => 5, 'is_active' => true]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson('/api/catalog/products?search=varilla');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_filters_by_supplier_code(): void
    {
        Contractor::create(['code' => 'CON-301', 'name' => 'Acero del Sur', 'rif' => 'J-12345678-9', 'specialty' => 'Materiales', 'status' => 'active']);
        $product = MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        $other = MaterialCatalog::create(['name' => 'Varilla 3/8', 'unit' => 'unidad', 'estimated_unit_price' => 5, 'is_active' => true]);
        CatalogProductSupplier::create(['catalog_product_id' => $product->id, 'supplier_code' => 'CON-301', 'last_quoted_at' => now(), 'last_quoted_price_usd' => 8]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson('/api/catalog/products?supplier_code=CON-301');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Cemento Portland');
    }

    public function test_show_returns_product_with_suppliers(): void
    {
        Contractor::create(['code' => 'CON-301', 'name' => 'Acero del Sur', 'rif' => 'J-12345678-9', 'specialty' => 'Materiales', 'status' => 'active']);
        $product = MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        CatalogProductSupplier::create(['catalog_product_id' => $product->id, 'supplier_code' => 'CON-301', 'last_quoted_at' => now(), 'last_quoted_price_usd' => 8]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson("/api/catalog/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.suppliers.0.supplier_code', 'CON-301');
        $response->assertJsonPath('data.suppliers.0.supplier.name', 'Acero del Sur');
    }

    public function test_price_history_returns_ordered_series(): void
    {
        Contractor::create(['code' => 'CON-301', 'name' => 'Acero del Sur', 'rif' => 'J-12345678-9', 'specialty' => 'Materiales', 'status' => 'active']);
        $product = MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        ProductPriceHistory::create([
            'catalog_product_id' => $product->id, 'supplier_code' => 'CON-301',
            'supplier_material_proposal_line_id' => $this->makeLineFor($product),
            'price_usd' => 9, 'original_currency' => 'USD', 'original_price' => 9,
            'fx_rate_to_usd' => 1, 'fx_rate_source' => 'BCV', 'quoted_at' => now(),
        ]);
        ProductPriceHistory::create([
            'catalog_product_id' => $product->id, 'supplier_code' => 'CON-301',
            'supplier_material_proposal_line_id' => $this->makeLineFor($product),
            'price_usd' => 8, 'original_currency' => 'USD', 'original_price' => 8,
            'fx_rate_to_usd' => 1, 'fx_rate_source' => 'BCV', 'quoted_at' => now()->subDays(3),
        ]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson("/api/catalog/products/{$product->id}/price-history");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.price_usd', 8);
        $response->assertJsonPath('data.1.price_usd', 9);
    }

    private function makeLineFor(MaterialCatalog $product): int
    {
        $project = \App\Models\Project::factory()->create();
        $proposal = \App\Models\SupplierMaterialProposal::create([
            'id' => \App\Models\SupplierMaterialProposal::nextId(),
            'project_id' => $project->id,
            'project_title_snapshot' => $project->title,
            'supplier_name' => 'Acero del Sur',
            'supplier_contact' => 'x@x.com',
            'items' => [],
        ]);

        return \App\Models\SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $proposal->id,
            'catalog_product_id' => $product->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1,
            'unit_price' => 8,
            'unit_price_usd' => 8,
            'quantity' => 1,
            'unit' => 'saco',
            'technical_specs' => [],
        ])->id;
    }
}
