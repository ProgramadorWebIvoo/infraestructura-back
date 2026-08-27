<?php

namespace Tests\Feature;

use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\SupplierMaterialProposal;
use App\Models\SupplierMaterialProposalLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomProductResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomLine(): SupplierMaterialProposalLine
    {
        $project = Project::factory()->create();
        $proposal = SupplierMaterialProposal::create([
            'id' => SupplierMaterialProposal::nextId(),
            'project_id' => $project->id,
            'project_title_snapshot' => $project->title,
            'supplier_name' => 'Acero del Sur',
            'supplier_contact' => 'contacto@acerodelsur.com',
            'items' => [],
        ]);

        $customProduct = MaterialCatalog::create([
            'name' => 'Varilla especial',
            'unit' => 'unidad',
            'estimated_unit_price' => 5,
            'is_active' => true,
            'is_custom_origin' => true,
        ]);

        return SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $proposal->id,
            'catalog_product_id' => $customProduct->id,
            'custom_product_name' => 'Varilla especial',
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1,
            'unit_price' => 5,
            'unit_price_usd' => 5,
            'quantity' => 10,
            'unit' => 'unidad',
            'technical_specs' => [],
        ]);
    }

    public function test_non_authorized_role_cannot_view_pending_list(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $this->actingAs($user)->getJson('/api/custom-product-resolutions/pending')->assertStatus(403);
    }

    public function test_presidencia_can_view_pending_custom_products(): void
    {
        $line = $this->makeCustomLine();
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->getJson('/api/custom-product-resolutions/pending');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $line->id);
    }

    public function test_lines_linked_to_a_non_custom_product_are_excluded(): void
    {
        $project = Project::factory()->create();
        $proposal = SupplierMaterialProposal::create([
            'id' => SupplierMaterialProposal::nextId(),
            'project_id' => $project->id,
            'project_title_snapshot' => $project->title,
            'supplier_name' => 'Acero del Sur',
            'supplier_contact' => 'x@x.com',
            'items' => [],
        ]);
        $standardProduct = MaterialCatalog::create(['name' => 'Cemento', 'unit' => 'saco', 'estimated_unit_price' => 8, 'is_active' => true, 'is_custom_origin' => false]);
        SupplierMaterialProposalLine::create([
            'supplier_material_proposal_id' => $proposal->id,
            'catalog_product_id' => $standardProduct->id,
            'condition_status' => 'new',
            'quote_currency' => 'USD',
            'fx_rate_to_usd' => 1,
            'unit_price' => 8,
            'unit_price_usd' => 8,
            'quantity' => 1,
            'unit' => 'saco',
            'technical_specs' => [],
        ]);

        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $response = $this->actingAs($presidencia)->getJson('/api/custom-product-resolutions/pending');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_presidencia_can_resolve_a_custom_product(): void
    {
        $line = $this->makeCustomLine();
        $target = MaterialCatalog::create(['name' => 'Varilla 3/8', 'unit' => 'unidad', 'estimated_unit_price' => 5, 'is_active' => true]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($presidencia)->postJson("/api/supplier-material-proposal-lines/{$line->id}/resolve-product", [
            'resolved_catalog_product_id' => $target->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('custom_product_resolutions', [
            'supplier_material_proposal_line_id' => $line->id,
            'resolved_catalog_product_id' => $target->id,
        ]);
        // La línea original y su catalog_product_id NO se reescriben.
        $this->assertDatabaseHas('supplier_material_proposal_lines', [
            'id' => $line->id,
            'catalog_product_id' => $line->catalog_product_id,
        ]);
    }

    public function test_cannot_resolve_the_same_line_twice(): void
    {
        $line = $this->makeCustomLine();
        $target = MaterialCatalog::create(['name' => 'Varilla 3/8', 'unit' => 'unidad', 'estimated_unit_price' => 5, 'is_active' => true]);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $this->actingAs($presidencia)->postJson("/api/supplier-material-proposal-lines/{$line->id}/resolve-product", [
            'resolved_catalog_product_id' => $target->id,
        ])->assertStatus(201);

        $this->actingAs($presidencia)->postJson("/api/supplier-material-proposal-lines/{$line->id}/resolve-product", [
            'resolved_catalog_product_id' => $target->id,
        ])->assertStatus(422);
    }

    public function test_cannot_resolve_to_the_same_product_the_line_already_has(): void
    {
        $line = $this->makeCustomLine();
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $this->actingAs($presidencia)->postJson("/api/supplier-material-proposal-lines/{$line->id}/resolve-product", [
            'resolved_catalog_product_id' => $line->catalog_product_id,
        ])->assertStatus(422);
    }
}
