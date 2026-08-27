<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\MaterialCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_superadmin_cannot_access_catalog_categories(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($user)->getJson('/api/catalog-categories')->assertStatus(403);
    }

    public function test_superadmin_can_create_a_category_with_spec_schema(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($admin)->postJson('/api/catalog-categories', [
            'name' => 'Cemento',
            'spec_schema' => [
                ['key' => 'resistencia_mpa', 'label' => 'Resistencia (MPa)', 'type' => 'number', 'unit' => 'MPa', 'required' => true],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('catalog_categories', ['name' => 'Cemento']);
        $response->assertJsonPath('data.auditLog.action', 'Alta de categoría de catálogo');
    }

    public function test_rejects_spec_schema_entry_with_invalid_type(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)->postJson('/api/catalog-categories', [
            'name' => 'Cemento',
            'spec_schema' => [
                ['key' => 'x', 'label' => 'X', 'type' => 'not-a-real-type'],
            ],
        ])->assertStatus(422);
    }

    public function test_superadmin_can_update_a_category(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $category = CatalogCategory::create(['name' => 'Cemento']);

        $response = $this->actingAs($admin)->patchJson("/api/catalog-categories/{$category->id}", ['name' => 'Cemento y agregados']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('catalog_categories', ['id' => $category->id, 'name' => 'Cemento y agregados']);
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $category = CatalogCategory::create(['name' => 'Cemento']);

        $this->actingAs($admin)
            ->patchJson("/api/catalog-categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(422);
    }

    public function test_superadmin_can_delete_an_empty_category(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $category = CatalogCategory::create(['name' => 'Cemento']);

        $response = $this->actingAs($admin)->deleteJson("/api/catalog-categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('catalog_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_with_subcategories(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $parent = CatalogCategory::create(['name' => 'Materiales']);
        CatalogCategory::create(['name' => 'Cemento', 'parent_id' => $parent->id]);

        $this->actingAs($admin)->deleteJson("/api/catalog-categories/{$parent->id}")->assertStatus(422);
    }

    public function test_cannot_delete_category_with_products(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $category = CatalogCategory::create(['name' => 'Cemento']);
        MaterialCatalog::create(['name' => 'Cemento Portland', 'unit' => 'saco', 'estimated_unit_price' => 10, 'is_active' => true, 'category_id' => $category->id]);

        $this->actingAs($admin)->deleteJson("/api/catalog-categories/{$category->id}")->assertStatus(422);
    }
}
