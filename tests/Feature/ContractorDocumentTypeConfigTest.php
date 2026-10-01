<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\ContractorDocumentType;
use App\Models\User;
use App\Services\ContractorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractorDocumentTypeConfigTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $role = 'ADMIN'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_admin_creates_a_type_with_a_generated_unique_key(): void
    {
        $headers = $this->headers();

        $this->postJson('/api/contractor-document-types/config', ['label' => 'Solvencia fiscal'], $headers)
            ->assertCreated()
            ->assertJsonPath('key', 'solvencia_fiscal')
            ->assertJsonPath('isRequired', true);

        $this->postJson('/api/contractor-document-types/config', ['label' => 'Solvencia fiscal'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');

        // Etiqueta distinta que produce el mismo slug: la clave se desambigua.
        $this->postJson('/api/contractor-document-types/config', ['label' => 'Solvencia fiscal!', 'isRequired' => false], $headers)
            ->assertCreated()
            ->assertJsonPath('key', 'solvencia_fiscal_2');

        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'contractor_document_type', 'action' => 'Alta de tipo de documento de proveedor']);
    }

    public function test_only_admin_roles_can_manage_types(): void
    {
        $this->getJson('/api/contractor-document-types/config', $this->headers('PROCURA'))->assertForbidden();
        $this->postJson('/api/contractor-document-types/config', ['label' => 'X'], $this->headers('FINANZAS'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/contractor-document-types/config')->assertUnauthorized();
    }

    public function test_update_and_toggle_invalidate_the_public_catalog_cache(): void
    {
        $headers = $this->headers();
        $type = ContractorDocumentType::where('key', 'rif')->firstOrFail();

        $this->getJson('/api/public/contractor-document-types')->assertJsonCount(5, 'data');
        $this->assertTrue(Cache::has(ContractorDocumentType::CATALOG_CACHE_KEY));

        $this->postJson("/api/contractor-document-types/config/{$type->id}/toggle-status", [], $headers)
            ->assertOk()->assertJsonPath('isActive', false);

        $this->getJson('/api/public/contractor-document-types')->assertJsonCount(4, 'data');

        $this->patchJson("/api/contractor-document-types/config/{$type->id}", ['label' => 'RIF vigente', 'isRequired' => false, 'isActive' => true], $headers)
            ->assertOk()->assertJsonPath('label', 'RIF vigente')->assertJsonPath('isRequired', false);
    }

    public function test_type_with_documents_cannot_be_deleted_but_unused_can(): void
    {
        Storage::fake('local');
        $headers = $this->headers();
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $used = ContractorDocumentType::where('key', 'rif')->firstOrFail();
        app(ContractorDocumentService::class)->replace(
            $contractor,
            $used->id,
            UploadedFile::fake()->createWithContent('rif.pdf', "%PDF-1.4\n%%EOF"),
            'INTERNAL'
        );
        $unused = ContractorDocumentType::where('key', 'acta_accionistas')->firstOrFail();

        $this->deleteJson("/api/contractor-document-types/config/{$used->id}", [], $headers)->assertStatus(422);
        $this->deleteJson("/api/contractor-document-types/config/{$unused->id}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('contractor_document_types', ['id' => $unused->id]);
    }

    public function test_index_includes_inactive_types_with_document_counts(): void
    {
        ContractorDocumentType::where('key', 'rif')->update(['is_active' => false]);

        $this->getJson('/api/contractor-document-types/config', $this->headers())
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.documentsCount', 0);
    }
}
