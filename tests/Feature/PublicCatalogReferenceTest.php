<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\Currency;
use App\Models\MaterialCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints públicos (sin auth) que alimentan el formulario de propuesta de
 * materiales del portal de proveedores — no requieren token de invitación
 * (son catálogos de referencia, no datos de un proyecto/proveedor
 * específico), solo throttle:public-api.
 */
class PublicCatalogReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_currencies_lists_only_active_ones(): void
    {
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);
        Currency::where('code', 'EUR')->update(['is_active' => false]);

        $response = $this->getJson('/api/public/currencies');

        $response->assertStatus(200);
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('USD'));
        $this->assertTrue($codes->contains('VES'));
        $this->assertFalse($codes->contains('EUR'));
    }

    public function test_public_currencies_does_not_require_auth(): void
    {
        $this->getJson('/api/public/currencies')->assertStatus(200);
    }

    public function test_public_catalog_categories_includes_spec_schema(): void
    {
        CatalogCategory::create([
            'name' => 'Cemento',
            'spec_schema' => [['key' => 'resistencia_mpa', 'label' => 'Resistencia (MPa)', 'type' => 'number']],
        ]);

        $response = $this->getJson('/api/public/catalog-categories');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Cemento');
        $response->assertJsonPath('data.0.spec_schema.0.key', 'resistencia_mpa');
    }

    public function test_public_catalog_products_search_requires_minimum_length(): void
    {
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);

        $response = $this->getJson('/api/public/catalog-products/search?search=c');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_public_catalog_products_search_finds_active_matches(): void
    {
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);
        MaterialCatalog::create(['name' => 'Cemento gris inactivo', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => false]);

        $response = $this->getJson('/api/public/catalog-products/search?search=cemento');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Cemento Portland');
    }

    public function test_public_catalog_products_search_does_not_leak_price(): void
    {
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true]);

        $response = $this->getJson('/api/public/catalog-products/search?search=cemento');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('estimated_unit_price', $response->json('data.0'));
    }
}
