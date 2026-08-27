<?php

namespace Tests\Feature;

use App\Models\CatalogProductSupplier;
use App\Models\Contractor;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\ProductPriceHistory;
use App\Models\SupplierInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el flujo submit → ProposalLineNormalizer → CatalogSyncService
 * disparado desde SupplierProposalController::store, no las clases de
 * servicio en aislamiento — es la integración la que importa acá (que un
 * envío real del portal público efectivamente alimente catálogo/histórico).
 */
class SupplierProposalCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(): SupplierInvitation
    {
        $project = Project::factory()->create();

        return SupplierInvitation::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'supplier_name' => 'Acero del Sur',
            'supplier_company' => 'Acero del Sur C.A.',
            'supplier_contact' => 'contacto@acerodelsur.com',
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_submitting_a_proposal_creates_normalized_lines(): void
    {
        $invitation = $this->makeInvitation();

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 8.5, 'totalPrice' => 85],
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('id') ?? $response->json('data.id');

        $this->assertDatabaseHas('supplier_material_proposal_lines', [
            'supplier_material_proposal_id' => $proposalId,
            'custom_product_name' => 'Cemento Portland',
            'quote_currency' => 'USD',
            'unit_price_usd' => 8.5,
        ]);
    }

    public function test_new_custom_product_creates_a_catalog_entry(): void
    {
        $invitation = $this->makeInvitation();

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Varilla 3/8 Corrugada', 'quantity' => 50, 'unit' => 'unidad', 'unitPrice' => 6, 'totalPrice' => 300],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('material_catalog', [
            'name' => 'Varilla 3/8 Corrugada',
            'is_custom_origin' => true,
        ]);
    }

    public function test_existing_catalog_product_is_reused_by_name(): void
    {
        $existing = MaterialCatalog::create([
            'name' => 'Cemento Portland',
            'unit' => 'saco',
            'estimated_unit_price' => 8,
            'is_active' => true,
        ]);
        $invitation = $this->makeInvitation();

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'cemento portland', 'quantity' => 5, 'unit' => 'saco', 'unitPrice' => 9, 'totalPrice' => 45],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('material_catalog', 1);
        $this->assertDatabaseHas('supplier_material_proposal_lines', [
            'catalog_product_id' => $existing->id,
        ]);
    }

    public function test_known_supplier_gets_price_history_and_catalog_link(): void
    {
        Contractor::create([
            'code' => Contractor::nextCode(),
            'name' => 'Acero del Sur',
            'specialty' => 'Materiales',
            'status' => 'active',
        ]);
        $invitation = $this->makeInvitation();

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 8.5, 'totalPrice' => 85],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('product_price_history', 1);
        $this->assertDatabaseHas('product_price_history', [
            'supplier_code' => 'CON-301',
            'price_usd' => 8.5,
            'original_currency' => 'USD',
        ]);
        $this->assertDatabaseHas('catalog_product_suppliers', [
            'supplier_code' => 'CON-301',
            'quote_count' => 1,
        ]);
    }

    public function test_unknown_supplier_skips_catalog_link_but_still_normalizes_line(): void
    {
        $invitation = $this->makeInvitation();

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 8.5, 'totalPrice' => 85],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('product_price_history', 0);
        $this->assertDatabaseCount('supplier_material_proposal_lines', 1);
    }

    public function test_repeated_quote_increments_quote_count_and_updates_last_price(): void
    {
        Contractor::create(['code' => Contractor::nextCode(), 'name' => 'Acero del Sur', 'specialty' => 'Materiales', 'status' => 'active']);

        $firstInvitation = $this->makeInvitation();
        $r1 = $this->postJson("/api/public/invitations/{$firstInvitation->id}/proposal", [
            'items' => [['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 8, 'totalPrice' => 80]],
        ]);
        $r1->assertStatus(201);

        $secondInvitation = $this->makeInvitation();
        $this->postJson("/api/public/invitations/{$secondInvitation->id}/proposal", [
            'items' => [['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 9, 'totalPrice' => 90]],
        ])->assertStatus(201);

        $link = CatalogProductSupplier::where('supplier_code', 'CON-301')->first();
        $this->assertEquals(2, $link->quote_count);
        $this->assertEquals(9, $link->last_quoted_price_usd);
        $this->assertEquals(2, ProductPriceHistory::count());
    }

    public function test_non_usd_quote_is_converted_using_latest_exchange_rate(): void
    {
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);
        ExchangeRate::create(['currency_code' => 'VES', 'rate_to_usd' => 0.01, 'source' => 'BCV', 'effective_at' => now()->subDay()]);
        $invitation = $this->makeInvitation();

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 800, 'totalPrice' => 8000, 'quoteCurrency' => 'ves'],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('supplier_material_proposal_lines', [
            'quote_currency' => 'VES',
            'unit_price' => 800,
            'unit_price_usd' => 8,
        ]);
    }

    public function test_submission_still_succeeds_even_if_currency_has_no_exchange_rate(): void
    {
        // La sincronización de catálogo va en try/catch fuera de la
        // transacción principal: el proveedor externo no debe recibir un
        // error 500 (ni perder su enlace de un solo uso) por un problema de
        // configuración de tasas que no le compete.
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);
        $invitation = $this->makeInvitation();

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento Portland', 'quantity' => 10, 'unit' => 'saco', 'unitPrice' => 800, 'totalPrice' => 8000, 'quoteCurrency' => 'ves'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('supplier_material_proposal_lines', 0);
        $this->assertDatabaseHas('supplier_material_proposals', ['id' => $response->json('id') ?? $response->json('data.id')]);
    }
}
