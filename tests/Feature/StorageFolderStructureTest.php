<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\StorageFolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/** Estructura `{titulo}_{fecha}_{codigo}/{tipo}/archivo` para todo lo que se guarda en disco. */
class StorageFolderStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'AUDITORIA']);
        Storage::fake('local');
    }

    private function upload(Project $project, string $type, $file)
    {
        // Los comprobantes de pago solo los adjunta Finanzas/Admin.
        $user = str_starts_with($type, 'COMPROBANTE') ? User::factory()->create(['role' => 'FINANZAS']) : $this->user;

        return $this->actingAs($user)->post("/api/projects/{$project->id}/documents", [
            'document_type' => $type,
            'files' => [$file],
        ])->assertCreated();
    }

    public function test_project_folder_is_slug_date_and_code_and_is_persisted(): void
    {
        $project = Project::factory()->create(['title' => 'Reparación Techo Sede Norte', 'created_date' => '2026-10-02']);

        $folder = app(StorageFolderService::class)->projectFolder($project);

        $this->assertSame("reparacion-techo-sede-norte_2026-10-02_{$project->id}", $folder);
        $this->assertSame($folder, $project->fresh()->storage_folder);

        // Renombrar el proyecto no cambia la carpeta ya asignada.
        $project->update(['title' => 'Otro nombre']);
        $this->assertSame($folder, app(StorageFolderService::class)->projectFolder($project->fresh()));
    }

    public function test_documents_land_in_one_folder_per_type_inside_the_project_folder(): void
    {
        $project = Project::factory()->create(['title' => 'Obra Uno', 'created_date' => '2026-10-02']);
        $folder = app(StorageFolderService::class)->projectFolder($project);

        $this->upload($project, 'PLANO', FakeFiles::pdf('plano.pdf', 100));
        $this->upload($project, 'FOTO', FakeFiles::jpeg('sitio.jpg', 100));
        $this->upload($project, 'COMPROBANTE_ANTICIPO', FakeFiles::pdf('pago.pdf', 100));

        $paths = ProjectDocument::pluck('stored_path')->all();
        $this->assertContains("{$folder}/planos/plano.pdf", $paths);
        $this->assertContains("{$folder}/fotos_del_sitio/sitio.jpg", $paths);
        $this->assertContains("{$folder}/comprobantes/pago.pdf", $paths);

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_new_version_stays_in_the_same_type_folder_without_overwriting(): void
    {
        $project = Project::factory()->create();
        $folder = app(StorageFolderService::class)->projectFolder($project);

        $this->upload($project, 'PLANO', FakeFiles::pdf('plano.pdf', 100));
        $first = ProjectDocument::first();

        $this->actingAs($this->user)->post("/api/projects/{$project->id}/documents", [
            'document_type' => 'PLANO',
            'files' => [FakeFiles::pdf('plano.pdf', 120)],
            'new_version_of' => $first->id,
        ])->assertCreated();

        $paths = ProjectDocument::orderBy('id')->pluck('stored_path')->all();
        $this->assertCount(2, array_unique($paths));
        foreach ($paths as $path) {
            $this->assertStringStartsWith("{$folder}/planos/", $path);
            Storage::disk('local')->assertExists($path);
        }
    }
}
