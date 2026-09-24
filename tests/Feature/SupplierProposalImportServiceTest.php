<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre SupplierProposalImportService::import() → savePriceHistory(), sin
 * test previo pese a ser un tercer write-path de product_price_history
 * (además de CatalogSyncService y ProjectProposalObserver) — detectado al
 * debuggear la integración de histórico de productos (Fase 4): este service
 * no seteaba `quantity`/`project_id` en la fila creada, a diferencia de los
 * otros dos paths, dejando ese caso concreto del histórico incompleto.
 *
 * Escenario cubierto: proveedor sin Contractor registrado al momento del
 * submit del portal (CatalogSyncService no crea fila en product_price_history
 * en ese caso — ver test_unknown_supplier_skips_catalog_link_but_still_normalizes_line
 * en SupplierProposalCatalogSyncTest), el Contractor se registra después, y
 * un ANALISTA importa la propuesta — es la única ruta real donde
 * savePriceHistory() efectivamente inserta (si CatalogSyncService ya
 * insertó al submit, el chequeo alreadyLogged hace que este método no
 * duplique).
 */
class SupplierProposalImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_price_history_with_quantity_and_project_id(): void
    {
        $project = Project::factory()->create();

        $invitation = SupplierInvitation::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'supplier_name' => 'Acero del Sur',
            'supplier_company' => 'Acero del Sur C.A.',
            'supplier_contact' => 'contacto@acerodelsur.com',
            'expires_at' => now()->addDays(7),
        ]);

        // Submit sin Contractor registrado todavía: CatalogSyncService normaliza
        // la línea (resuelve/crea catalog_product_id) pero no crea fila en
        // product_price_history (sin supplier_code que vincular).
        $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [[
                'materialName' => 'Cemento Portland',
                'quantity' => 12,
                'unit' => 'saco',
                'unitPrice' => 8.5,
                'totalPrice' => 102,
                'conditionStatus' => 'new',
                'warrantyDescription' => 'Garantía de fábrica',
            ]],
        ])->assertStatus(201);

        $this->assertDatabaseCount('product_price_history', 0);

        // El proveedor se registra después del submit.
        $contractor = Contractor::create([
            'code' => Contractor::nextCode(),
            'name' => 'Acero del Sur',
            'rif' => 'J-12345678-9',
            'specialty' => 'Materiales',
            'status' => 'active',
            'email' => 'contacto@acerodelsur.com',
        ]);

        $analista = User::factory()->create(['role' => 'ANALISTA']);

        $this->actingAs($analista)
            ->postJson("/api/projects/{$project->id}/import-supplier-proposals")
            ->assertStatus(200);

        $this->assertDatabaseCount('product_price_history', 1);

        $row = ProductPriceHistory::first();
        $this->assertSame(12.0, (float) $row->quantity);
        $this->assertSame($project->id, $row->project_id);
        $this->assertSame($contractor->code, $row->supplier_code);
    }
}
