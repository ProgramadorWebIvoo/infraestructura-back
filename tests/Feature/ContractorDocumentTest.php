<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\ContractorDocumentType;
use App\Models\User;
use App\Services\ContractorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractorDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function headers(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function rifType(): ContractorDocumentType
    {
        return ContractorDocumentType::where('key', 'rif')->firstOrFail();
    }

    public function test_admin_uploads_a_document_and_lists_it(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $type = $this->rifType();

        $this->postJson("/api/contractors/CON-1/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf('rif.pdf'),
        ], $this->headers('ADMIN'))->assertCreated()->assertJsonPath('data.versionNumber', 1);

        $this->getJson('/api/contractors/CON-1/documents', $this->headers('PROCURA'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.documentTypeKey', 'rif');

        $this->assertCount(1, Storage::disk('local')->allFiles('contractor-documents/CON-1'));
    }

    public function test_replacing_keeps_history_and_lists_only_latest_by_default(): void
    {
        Contractor::factory()->create(['code' => 'CON-1']);
        $type = $this->rifType();
        $headers = $this->headers('ADMIN');

        $this->postJson('/api/contractors/CON-1/documents', ['document_type_id' => $type->id, 'file' => $this->pdf('v1.pdf')], $headers)->assertCreated();
        $this->postJson('/api/contractors/CON-1/documents', ['document_type_id' => $type->id, 'file' => $this->pdf('v2.pdf')], $headers)
            ->assertCreated()->assertJsonPath('data.versionNumber', 2);

        $this->getJson('/api/contractors/CON-1/documents', $headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.originalName', 'v2.pdf');
        $this->getJson('/api/contractors/CON-1/documents?all_versions=1', $headers)->assertJsonCount(2, 'data');
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        Contractor::factory()->create(['code' => 'CON-1']);

        $this->postJson('/api/contractors/CON-1/documents', [
            'document_type_id' => $this->rifType()->id,
            'file' => UploadedFile::fake()->create('macro.exe', 10),
        ], $this->headers('ADMIN'))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_inactive_or_unknown_document_type_is_rejected(): void
    {
        Contractor::factory()->create(['code' => 'CON-1']);
        $type = $this->rifType();
        $type->update(['is_active' => false]);

        $this->postJson('/api/contractors/CON-1/documents', ['document_type_id' => $type->id, 'file' => $this->pdf()], $this->headers('ADMIN'))
            ->assertStatus(422)->assertJsonValidationErrors('document_type_id');
    }

    public function test_download_requires_authentication_and_role(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $document = app(ContractorDocumentService::class)->replace($contractor, $this->rifType()->id, $this->pdf(), 'INTERNAL');
        $url = "/api/contractors/CON-1/documents/{$document->id}/download";

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, $this->headers('INFRAESTRUCTURA'))->assertForbidden();
        $this->get($url, $this->headers('FINANZAS'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_roles_without_permission_cannot_upload_or_delete(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $document = app(ContractorDocumentService::class)->replace($contractor, $this->rifType()->id, $this->pdf(), 'INTERNAL');
        $payload = ['document_type_id' => $this->rifType()->id, 'file' => $this->pdf()];

        $this->postJson('/api/contractors/CON-1/documents', $payload, $this->headers('FINANZAS'))->assertForbidden();
        $this->deleteJson("/api/contractors/CON-1/documents/{$document->id}", [], $this->headers('PROCURA'))->assertForbidden();
    }

    public function test_document_of_another_contractor_returns_404(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        Contractor::factory()->create(['code' => 'CON-2']);
        $document = app(ContractorDocumentService::class)->replace($contractor, $this->rifType()->id, $this->pdf(), 'INTERNAL');

        $this->get("/api/contractors/CON-2/documents/{$document->id}/download", $this->headers('ADMIN'))->assertNotFound();
    }

    public function test_delete_soft_deletes_the_whole_group(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $service = app(ContractorDocumentService::class);
        $service->replace($contractor, $this->rifType()->id, $this->pdf('v1.pdf'), 'INTERNAL');
        $latest = $service->replace($contractor, $this->rifType()->id, $this->pdf('v2.pdf'), 'INTERNAL');

        $this->deleteJson("/api/contractors/CON-1/documents/{$latest->id}", [], $this->headers('ADMIN'))->assertOk();

        $this->assertSame(0, ContractorDocument::count());
        $this->assertSame(2, ContractorDocument::withTrashed()->count());
    }

    public function test_legacy_contractor_without_documents_reports_incomplete_without_breaking(): void
    {
        Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->getJson('/api/contractors/CON-1/documents', $this->headers('ADMIN'))->assertOk();

        $response->assertJsonCount(0, 'data')->assertJsonPath('completeness.complete', false);
        $this->assertCount(5, $response->json('completeness.missing'));
    }

    public function test_completeness_is_complete_when_all_required_types_are_present(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);
        $service = app(ContractorDocumentService::class);

        foreach (ContractorDocumentType::required()->get() as $type) {
            $service->replace($contractor, $type->id, $this->pdf("{$type->key}.pdf"), 'INTERNAL');
        }

        $this->assertTrue($service->completeness($contractor)['complete']);
    }

    public function test_public_types_endpoint_lists_active_types_without_auth(): void
    {
        ContractorDocumentType::where('key', 'rif_representante')->update(['is_active' => false]);

        $this->getJson('/api/public/contractor-document-types')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.key', 'rif');
    }
}
