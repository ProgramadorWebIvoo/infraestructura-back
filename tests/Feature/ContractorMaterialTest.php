<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorMaterialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->admin->createToken('test')->plainTextToken];
    }

    // ─── AUTORIZACIÓN ───

    public function test_unauthorized_role_cannot_access_contractor_config_endpoints(): void
    {
        $analista = User::factory()->create(['role' => 'ANALISTA']);
        $headers = ['Authorization' => 'Bearer ' . $analista->createToken('test')->plainTextToken];
        $contractor = Contractor::factory()->create();

        $this->withHeaders($headers)->getJson('/api/contractors/config')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/contractors/config', [
            'name' => 'X', 'specialty' => 'Y', 'email' => 'z@z.com',
        ])->assertStatus(403);
        $this->withHeaders($headers)->patchJson("/api/contractors/config/{$contractor->code}", ['rating' => 4])->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/contractors/config/{$contractor->code}/toggle-status")->assertStatus(403);
    }

    public function test_unauthorized_role_cannot_access_material_config_endpoints(): void
    {
        $analista = User::factory()->create(['role' => 'ANALISTA']);
        $headers = ['Authorization' => 'Bearer ' . $analista->createToken('test')->plainTextToken];
        $material = MaterialCatalog::factory()->create();

        $this->withHeaders($headers)->getJson('/api/materials/config')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/materials/config', ['name' => 'X', 'unit' => 'kg'])->assertStatus(403);
        $this->withHeaders($headers)->patchJson("/api/materials/config/{$material->id}", ['estimatedUnitPrice' => 1])->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/materials/config/{$material->id}/toggle-status")->assertStatus(403);
    }

    // ─── CONTRACTOR CRUD ───

    public function test_contractor_index(): void
    {
        Contractor::factory()->count(3)->create();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/contractors/config');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_contractor_store(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/contractors/config', [
                'name'      => 'Constructora del Sur',
                'rif'       => 'J-12345678-9',
                'specialty' => 'Construcción Civil',
                'email'     => 'contacto@constructorasur.com',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name'     => 'Constructora del Sur',
            'specialty' => 'Construcción Civil',
            'status'   => 'ACTIVE',
        ]);
        $this->assertStringStartsWith('CON-', $response->json('code'));
        $this->assertEquals(4.0, $response->json('rating'));
        // El frontend inserta esta entrada en vivo en el panel de auditoría
        // (prependLocal) sin re-consultar /config-audit-logs.
        $response->assertJsonPath('auditLog.action', 'Alta de proveedor');
    }

    public function test_contractor_store_accepts_phone_only(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/contractors/config', [
                'name'      => 'Constructora del Norte',
                'rif'       => 'J-23456789-0',
                'specialty' => 'Plomería',
                'phone'     => '+58 412-1234567',
            ]);

        $response->assertStatus(201);
        $response->assertJson(['name' => 'Constructora del Norte', 'phone' => '+58 412-1234567']);
        $this->assertNull($response->json('email'));
    }

    public function test_contractor_store_rejects_without_email_and_phone(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/contractors/config', [
                'name'      => 'Constructora Sin Contacto',
                'specialty' => 'Plomería',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_contractor_update_rejects_clearing_both_email_and_phone(): void
    {
        $contractor = Contractor::factory()->create(['email' => 'existing@test.com', 'phone' => null]);

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/contractors/config/{$contractor->code}", ['email' => null]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_contractor_update_allows_clearing_email_when_phone_present(): void
    {
        $contractor = Contractor::factory()->create(['email' => 'existing@test.com', 'phone' => '04121234567']);

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/contractors/config/{$contractor->code}", ['email' => null]);

        $response->assertStatus(200);
        $this->assertNull($response->json('email'));
    }

    public function test_contractor_store_strips_html_tags(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/contractors/config', [
                'name'      => '<script>alert("xss")</script>Constructora',
                'rif'       => 'J-34567890-1',
                'specialty' => '<b>Especialidad</b>',
                'email'     => 'test@test.com',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name'     => 'alert("xss")Constructora',
            'specialty' => 'Especialidad',
        ]);
    }

    public function test_contractor_show(): void
    {
        $contractor = Contractor::factory()->create();

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/contractors/config/{$contractor->code}");

        $response->assertStatus(200);
        $response->assertJson(['code' => $contractor->code]);
    }

    public function test_contractor_update(): void
    {
        $contractor = Contractor::factory()->create();

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/contractors/config/{$contractor->code}", [
                'rating' => 4.5,
                'status' => 'INACTIVE',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'rating' => 4.5,
            'status' => 'INACTIVE',
        ]);
        $response->assertJsonPath('auditLog.action', 'Modificacion de proveedor');
    }

    public function test_contractor_toggle_status_cycles(): void
    {
        $contractor = Contractor::factory()->create(['status' => 'PENDING_REVIEW']);

        // PENDING_REVIEW -> ACTIVE
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/contractors/config/{$contractor->code}/toggle-status");
        $response->assertJson(['status' => 'ACTIVE']);
        $response->assertJsonPath('auditLog.action', 'Activacion/desactivacion de proveedor');

        // ACTIVE -> INACTIVE
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/contractors/config/{$contractor->code}/toggle-status");
        $response->assertJson(['status' => 'INACTIVE']);

        // INACTIVE -> ACTIVE
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/contractors/config/{$contractor->code}/toggle-status");
        $response->assertJson(['status' => 'ACTIVE']);
    }

    // ─── PUBLIC CONTRACTOR REGISTRATION ───

    public function test_public_contractor_registration_creates_pending(): void
    {
        $response = $this->postJson('/api/contractors', [
            'name'      => 'Proveedor Público',
            'rif'       => 'V-45678901-2',
            'specialty' => 'Electricidad',
            'email'     => 'proveedor@test.com',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'PENDING_REVIEW',
            'registration_source' => 'PUBLIC_PORTAL',
        ]);
        $this->assertStringStartsWith('CON-', $response->json('code'));
    }

    /**
     * Bug real detectado en QA: RIF_REGEX permite guiones opcionales en 2
     * posiciones ("V123456789", "V-123456789", "V12345678-9",
     * "V-12345678-9" son 4 strings distintos para el MISMO RIF), y
     * `unique:contractors,rif` compara el string literal — sin normalizar
     * antes de validar, un mismo RIF con guiones en formato distinto se
     * registraba dos veces sin que la regla unique lo detectara.
     */
    public function test_public_registration_rejects_same_rif_in_different_dash_format(): void
    {
        Contractor::factory()->create(['rif' => 'V-45678901-2']);

        $response = $this->postJson('/api/contractors', [
            'name'      => 'Otro Proveedor',
            'rif'       => 'V456789012', // mismo RIF, sin guiones
            'specialty' => 'Electricidad',
            'email'     => 'otro@test.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rif']);
    }

    public function test_active_contractors_listed_in_public_endpoint(): void
    {
        Contractor::factory()->count(2)->create(['status' => 'ACTIVE']);
        Contractor::factory()->create(['status' => 'PENDING_REVIEW']);
        Contractor::factory()->create(['status' => 'INACTIVE']);

        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/contractors');

        $response->assertStatus(200);
        // Only ACTIVE contractors (2)
        $this->assertCount(2, $response->json());
    }

    public function test_update_contractor_rating(): void
    {
        $contractor = Contractor::factory()->create(['rating' => 4.0]);

        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->postJson("/api/contractors/{$contractor->code}/rating", [
            'rating' => 4.7,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'code'   => $contractor->code,
            'rating' => 4.7,
        ]);
    }

    // ─── MATERIAL CATALOG CRUD ───

    public function test_material_index(): void
    {
        MaterialCatalog::factory()->count(3)->create();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/materials/config');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_material_store(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/materials/config', [
                'name'               => 'Cemento Portland',
                'unit'               => 'kg',
                'estimatedUnitPrice' => 15.50,
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name'               => 'Cemento Portland',
            'unit'               => 'kg',
            'estimatedUnitPrice' => 15.50,
            'isActive'           => true,
        ]);
        $response->assertJsonPath('auditLog.action', 'Alta de material');
    }

    public function test_material_store_strips_html_tags(): void
    {
        // Paridad con test_contractor_store_strips_html_tags — MaterialController
        // aplica strip_tags() igual que ContractorController pero no tenía
        // cobertura equivalente.
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/materials/config', [
                'name' => '<script>alert("xss")</script>Cemento',
                'unit' => '<b>kg</b>',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'alert("xss")Cemento',
            'unit' => 'kg',
        ]);
    }

    public function test_material_update_unique_conflict_with_partial_field_change(): void
    {
        MaterialCatalog::factory()->create(['name' => 'Arena', 'unit' => 'm3']);
        $other = MaterialCatalog::factory()->create(['name' => 'Grava', 'unit' => 'kg']);

        // Cambiar solo `unit` (sin tocar `name`) para forzar colisión con
        // "Arena"/"m3" — antes solo se probaba la colisión exacta en creación.
        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/materials/config/{$other->id}", ['name' => 'Arena', 'unit' => 'm3']);

        $response->assertStatus(422);
    }

    public function test_material_store_rejects_negative_price(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/materials/config', ['name' => 'X', 'unit' => 'kg', 'estimatedUnitPrice' => -0.01]);

        $response->assertStatus(422);
    }

    public function test_material_store_accepts_zero_price_as_boundary(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/materials/config', ['name' => 'X', 'unit' => 'kg', 'estimatedUnitPrice' => 0]);

        $response->assertStatus(201);
        $response->assertJson(['estimatedUnitPrice' => 0]);
    }

    public function test_material_store_duplicate_name_unit_returns_422(): void
    {
        MaterialCatalog::factory()->create(['name' => 'Arena', 'unit' => 'm3']);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/materials/config', [
                'name' => 'Arena',
                'unit' => 'm3',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Ya existe un material con el nombre "Arena" y unidad "m3".']);
    }

    public function test_material_show(): void
    {
        $material = MaterialCatalog::factory()->create();

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/materials/config/{$material->id}");

        $response->assertStatus(200);
        $response->assertJson(['id' => $material->id]);
    }

    public function test_material_update(): void
    {
        $material = MaterialCatalog::factory()->create();

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/materials/config/{$material->id}", [
                'estimatedUnitPrice' => 99.99,
                'isActive'           => false,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'estimatedUnitPrice' => 99.99,
            'isActive'           => false,
        ]);
        $response->assertJsonPath('auditLog.action', 'Modificacion de material');
    }

    public function test_material_toggle_status(): void
    {
        $material = MaterialCatalog::factory()->create(['is_active' => true]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/materials/config/{$material->id}/toggle-status");

        $response->assertStatus(200);
        $response->assertJson(['isActive' => false]);
        $response->assertJsonPath('auditLog.action', 'Activacion/desactivacion de material');

        // Toggle back
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/materials/config/{$material->id}/toggle-status");

        $response->assertJson(['isActive' => true]);
    }

    public function test_active_materials_only_in_public_endpoint(): void
    {
        MaterialCatalog::factory()->count(2)->create(['is_active' => true]);
        MaterialCatalog::factory()->create(['is_active' => false]);

        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/materials');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }
}
