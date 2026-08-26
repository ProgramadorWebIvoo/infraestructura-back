<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        Storage::fake('local');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /** Crea un ProjectDocument fixture, autoasignando document_group_id como V1 (mismo patrón que el controller). */
    private function createDocument(Project $project, array $overrides = []): ProjectDocument
    {
        $doc = $project->documents()->create(array_merge([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => "project-documents/{$project->id}/PLANO/plano1.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ], $overrides));
        $doc->update(['document_group_id' => $doc->id]);

        return $doc;
    }

    public function test_upload_stores_file_and_creates_document_record(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('cubicacion.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'CALC',
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.0.documentType', 'CALC');

        $this->assertDatabaseHas('project_documents', [
            'project_id' => $project->id,
            'document_type' => 'CALC',
        ]);

        $doc = ProjectDocument::first();
        Storage::disk('local')->assertExists($doc->stored_path);
    }

    public function test_upload_accepts_foto_document_type_with_valid_image(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('sitio.jpg', 100, 'image/jpeg');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'FOTO',
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.0.documentType', 'FOTO');

        $this->assertDatabaseHas('project_documents', [
            'project_id' => $project->id,
            'document_type' => 'FOTO',
        ]);
    }

    public function test_upload_rejects_plano_extension_for_foto_document_type(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('plano.dwg', 100, 'application/acad');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'FOTO',
                'files' => [$file],
            ]);

        $response->assertStatus(422);
    }

    public function test_upload_rejects_octet_stream_mime_for_foto_document_type(): void
    {
        // application/octet-stream solo es válido para dwg/dxf de PLANO —
        // confirma que ese caso especial no se fuga hacia FOTO.
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('foto.jpg', 100, 'application/octet-stream');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'FOTO',
                'files' => [$file],
            ]);

        $response->assertStatus(422);
    }

    public function test_upload_rejects_disallowed_extension_for_document_type(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('imagen.png', 10, 'image/png');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'CALC',
                'files' => [$file],
            ]);

        $response->assertStatus(422);
    }

    public function test_upload_sanitizes_path_traversal_in_filename(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('../../etc/passwd.pdf', 10, 'application/pdf');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'CALC',
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $doc = ProjectDocument::first();
        $this->assertStringNotContainsString('..', $doc->stored_path);
        $this->assertStringNotContainsString('/etc/', $doc->stored_path);
    }

    public function test_upload_rejects_file_above_the_configured_max_size(): void
    {
        AppSetting::where('key', 'documento_tamano_maximo_mb')->update(['value' => '1']);
        SettingsService::forget();

        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('cubicacion.pdf', 2048, 'application/pdf'); // 2 MB

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'CALC',
                'files' => [$file],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['Cada archivo debe pesar máximo 1 MB.']);
    }

    public function test_upload_rejects_more_files_than_the_configured_max_count(): void
    {
        AppSetting::where('key', 'documento_cantidad_maxima_archivos')->update(['value' => '1']);
        SettingsService::forget();

        $project = Project::factory()->create();

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'CALC',
                'files' => [
                    UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_index_lists_documents_for_project(): void
    {
        $project = Project::factory()->create();
        $this->createDocument($project, ['original_name' => 'plano1.pdf', 'size_bytes' => 1234]);

        $response = $this->withHeaders($this->headers())->getJson("/api/projects/{$project->id}/documents");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_index_excludes_deleted_documents_by_default(): void
    {
        $project = Project::factory()->create();
        $doc = $this->createDocument($project, ['original_name' => 'plano1.pdf']);
        $doc->delete();

        $response = $this->withHeaders($this->headers())->getJson("/api/projects/{$project->id}/documents");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_index_with_include_deleted_returns_soft_deleted_documents(): void
    {
        $project = Project::factory()->create();
        $doc = $this->createDocument($project, ['original_name' => 'plano1.pdf']);
        $doc->delete();

        $response = $this->withHeaders($this->headers())->getJson("/api/projects/{$project->id}/documents?include_deleted=true");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $doc->id);
        $this->assertNotNull($response->json('data.0.deletedAt'));
    }

    public function test_index_with_all_versions_and_include_deleted_returns_full_history(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project, ['original_name' => 'plano-v1.pdf']);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('plano-v2.pdf', 10, 'application/pdf')],
        ]);

        $orphanGroup = $this->createDocument($project, ['original_name' => 'plano-eliminado.pdf']);
        $orphanGroup->delete();

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/projects/{$project->id}/documents?all_versions=true&include_deleted=true");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');

        $names = collect($response->json('data'))->pluck('originalName');
        $this->assertTrue($names->contains('plano-v1.pdf'));
        $this->assertTrue($names->contains('plano-v2.pdf'));
        $this->assertTrue($names->contains('plano-eliminado.pdf'));
    }

    public function test_destroy_removes_file_and_record(): void
    {
        $project = Project::factory()->create();
        $path = "project-documents/{$project->id}/PLANO/plano1.pdf";
        Storage::disk('local')->put($path, 'contenido');

        $doc = $this->createDocument($project, ['stored_path' => $path]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('project_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_destroy_rejects_document_belonging_to_another_project(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();

        $doc = $this->createDocument($otherProject);

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(404);
    }

    public function test_download_streams_existing_file(): void
    {
        $project = Project::factory()->create();
        $path = "project-documents/{$project->id}/PLANO/plano1.pdf";
        Storage::disk('local')->put($path, 'contenido-del-plano');

        $doc = $this->createDocument($project, ['stored_path' => $path, 'size_bytes' => 20]);

        $response = $this->withHeaders($this->headers())
            ->get("/api/projects/{$project->id}/documents/{$doc->id}/download");

        $response->assertStatus(200);
    }

    public function test_download_returns_404_when_file_missing_from_disk(): void
    {
        $project = Project::factory()->create();
        $doc = $this->createDocument($project, ['stored_path' => "project-documents/{$project->id}/PLANO/no-existe.pdf"]);

        $response = $this->withHeaders($this->headers())
            ->get("/api/projects/{$project->id}/documents/{$doc->id}/download");

        $response->assertStatus(404);
    }

    // ── Versionado ──────────────────────────────────────────────────────

    public function test_upload_without_new_version_of_creates_its_own_group_as_v1(): void
    {
        $project = Project::factory()->create();
        $file = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'PLANO',
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.0.versionNumber', 1);

        $doc = ProjectDocument::first();
        $this->assertEquals($doc->id, $doc->document_group_id);
    }

    public function test_upload_with_new_version_of_creates_v2_in_the_same_group(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $file = UploadedFile::fake()->create('plano-corregido.pdf', 100, 'application/pdf');
        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'PLANO',
                'new_version_of' => $v1->id,
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.0.versionNumber', 2);
        $response->assertJsonPath('data.0.documentGroupId', $v1->document_group_id);

        // V1 sigue existiendo sin cambios
        $this->assertDatabaseHas('project_documents', ['id' => $v1->id, 'version_number' => 1]);
    }

    public function test_upload_with_new_version_of_rejects_more_than_one_file(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $response = $this->withHeaders($this->headers())
            ->post("/api/projects/{$project->id}/documents", [
                'document_type' => 'PLANO',
                'new_version_of' => $v1->id,
                'files' => [
                    UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_index_returns_only_latest_version_by_default(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);

        $response = $this->withHeaders($this->headers())->getJson("/api/projects/{$project->id}/documents");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.versionNumber', 2);
    }

    public function test_index_with_all_versions_returns_every_version(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/projects/{$project->id}/documents?all_versions=1");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_history_returns_all_versions_ascending(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/projects/{$project->id}/documents/{$v1->id}/history");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.versionNumber', 1);
        $response->assertJsonPath('data.1.versionNumber', 2);
    }

    public function test_history_works_for_a_fully_soft_deleted_group(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);
        $v2 = ProjectDocument::where('document_group_id', $v1->document_group_id)->where('version_number', 2)->first();

        $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$v2->id}")
            ->assertStatus(200);

        // El grupo entero quedó soft-deleted — el historial debe seguir
        // siendo consultable a partir de cualquier id del grupo, aunque ese
        // documento puntual ya no esté "vivo".
        $response = $this->withHeaders($this->headers())
            ->getJson("/api/projects/{$project->id}/documents/{$v1->id}/history");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.versionNumber', 1);
        $response->assertJsonPath('data.1.versionNumber', 2);
    }

    public function test_destroy_removes_the_entire_group_not_a_single_version(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);

        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);
        $v2 = ProjectDocument::where('document_group_id', $v1->document_group_id)->where('version_number', 2)->first();

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$v1->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('project_documents', ['id' => $v1->id]);
        $this->assertSoftDeleted('project_documents', ['id' => $v2->id]);
    }

    public function test_sync_project_counts_counts_groups_not_rows(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->createDocument($project);
        $this->withHeaders($this->headers())->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'new_version_of' => $v1->id,
            'files' => [UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf')],
        ]);

        $project->refresh();
        $this->assertEquals(1, $project->blueprints_count);
    }

    // ── Permisos ────────────────────────────────────────────────────────

    public function test_upload_denied_for_role_without_access(): void
    {
        $procura = User::factory()->create(['role' => 'PROCURA']);
        $project = Project::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $procura->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ])->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'files' => [UploadedFile::fake()->create('plano.pdf', 10, 'application/pdf')],
        ]);

        $response->assertStatus(403);
    }

    public function test_destroy_denied_for_role_without_access(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $project = Project::factory()->create();
        $doc = $this->createDocument($project);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $infra->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ])->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(403);
    }

    public function test_upload_allowed_for_infraestructura(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $project = Project::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $infra->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ])->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'FOTO',
            'files' => [UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg')],
        ]);

        $response->assertStatus(201);
    }
}
