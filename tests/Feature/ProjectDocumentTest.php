<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
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

    public function test_index_lists_documents_for_project(): void
    {
        $project = Project::factory()->create();
        $project->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => "project-documents/{$project->id}/PLANO/plano1.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 1234,
        ]);

        $response = $this->withHeaders($this->headers())->getJson("/api/projects/{$project->id}/documents");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_destroy_removes_file_and_record(): void
    {
        $project = Project::factory()->create();
        $path = "project-documents/{$project->id}/PLANO/plano1.pdf";
        Storage::disk('local')->put($path, 'contenido');

        $doc = $project->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('project_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_destroy_rejects_document_belonging_to_another_project(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();

        $doc = $otherProject->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => "project-documents/{$otherProject->id}/PLANO/plano1.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(404);
    }

    public function test_download_streams_existing_file(): void
    {
        $project = Project::factory()->create();
        $path = "project-documents/{$project->id}/PLANO/plano1.pdf";
        Storage::disk('local')->put($path, 'contenido-del-plano');

        $doc = $project->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
        ]);

        $response = $this->withHeaders($this->headers())
            ->get("/api/projects/{$project->id}/documents/{$doc->id}/download");

        $response->assertStatus(200);
    }

    public function test_download_returns_404_when_file_missing_from_disk(): void
    {
        $project = Project::factory()->create();
        $doc = $project->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano1.pdf',
            'stored_path' => "project-documents/{$project->id}/PLANO/no-existe.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);

        $response = $this->withHeaders($this->headers())
            ->get("/api/projects/{$project->id}/documents/{$doc->id}/download");

        $response->assertStatus(404);
    }
}
