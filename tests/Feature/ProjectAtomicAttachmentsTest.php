<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FileSecurityEvent;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Los procesos que llevan adjuntos (crear, reenviar, rechazar, reevaluar) los
 * reciben en la MISMA petición: un archivo rechazado (código embebido) debe
 * tumbar el proceso completo — nada de "proceso registrado sin su archivo".
 */
class ProjectAtomicAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $infra;
    private User $auditoria;
    private User $procura;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->auditoria = User::factory()->create(['role' => 'AUDITORIA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
    }

    /** JPEG válido por firma pero con un script PHP embebido: lo que la pared de seguridad debe rechazar. */
    private function polyglotJpeg(string $name = 'foto.jpg'): UploadedFile
    {
        // Archivo real en disco: createWithContent() usa tmpfile(), que en Windows
        // no se puede releer por ruta (el escáner lo saltaría como ilegible).
        // Payload inocuo a propósito: con una firma de webshell real (system($_GET))
        // el antivirus de Windows bloquea la apertura del archivo.
        $path = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00<?php echo 1; ?>");

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function cleanPdf(string $name = 'correccion.pdf'): UploadedFile
    {
        return FakeFiles::pdf($name, 10);
    }

    private function projectPayload(): array
    {
        return [
            'title' => 'Obra con adjuntos',
            'type' => 'INFRAESTRUCTURA',
            'description' => 'Descripción de la obra de prueba',
            'location' => 'Ciudad de Prueba',
            'materials' => [
                ['name' => 'Cemento', 'quantity' => 10, 'unit' => 'Saco', 'estimatedUnitPrice' => 12.5, 'condition' => 'NUEVO'],
            ],
        ];
    }

    private function postMultipart(User $user, string $url, array $data)
    {
        return $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post($url, $data);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, ProjectDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_store_with_embedded_code_creates_no_project(): void
    {
        $response = $this->postMultipart($this->infra, '/api/projects', [
            'payload' => json_encode($this->projectPayload()),
            'photos' => [$this->polyglotJpeg()],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Project::count());
        $this->assertNothingStored();
        // La pared de seguridad deja constancia aunque el proceso se aborte.
        $this->assertDatabaseHas('file_security_events', ['status' => 'rejected', 'context' => 'project_document']);
    }

    public function test_store_with_clean_files_creates_project_and_documents_together(): void
    {
        $response = $this->postMultipart($this->infra, '/api/projects', [
            'payload' => json_encode($this->projectPayload()),
            'documents' => [$this->cleanPdf('cubicacion.pdf')],
            'plans' => [$this->cleanPdf('plano.pdf')],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Obra con adjuntos');
        $response->assertJsonPath('data.optimizedCount', 0);

        $project = Project::firstOrFail();
        $this->assertEqualsCanonicalizing(['CALC', 'PLANO'], $project->documents()->pluck('document_type')->all());
        $this->assertTrue((bool) $project->fresh()->calculations_added);
        $this->assertSame(1, $project->fresh()->blueprints_count);
    }

    public function test_store_still_accepts_plain_json_without_files(): void
    {
        $this->actingAs($this->infra)->postJson('/api/projects', $this->projectPayload())->assertStatus(201);
    }

    public function test_reject_with_embedded_code_leaves_the_project_untouched(): void
    {
        $project = Project::factory()->create(['requested_by_user_id' => $this->infra->id, 'status' => 'CREADO']);

        $response = $this->postMultipart($this->auditoria, "/api/projects/{$project->id}/reject-project", [
            'payload' => json_encode(['reason' => 'Falta detalle en la cubicación.']),
            'files' => [$this->polyglotJpeg('correccion.jpg')],
        ]);

        $response->assertStatus(422);
        $this->assertSame('CREADO', $project->fresh()->status);
        $this->assertSame(0, AuditLog::where('project_id', $project->id)->where('action', 'Rechazo de petición de obra')->count());
        $this->assertNothingStored();
    }

    public function test_reject_with_clean_correction_rejects_and_attaches_together(): void
    {
        $project = Project::factory()->create(['requested_by_user_id' => $this->infra->id, 'status' => 'CREADO']);

        $response = $this->postMultipart($this->auditoria, "/api/projects/{$project->id}/reject-project", [
            'payload' => json_encode(['reason' => 'Falta detalle en la cubicación.']),
            'files' => [$this->cleanPdf()],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'RECHAZADO_AUDITORIA');
        $this->assertSame(1, $project->documents()->where('document_type', 'CORRECCION')->count());
    }

    public function test_send_to_reevaluation_with_embedded_code_does_not_change_state(): void
    {
        $project = Project::factory()->create(['requested_by_user_id' => $this->infra->id, 'status' => 'REVISADO_AUDITORIA']);

        $response = $this->postMultipart($this->procura, "/api/projects/{$project->id}/send-to-reevaluation", [
            'payload' => json_encode(['reason' => 'Falta sustento técnico.']),
            'files' => [$this->polyglotJpeg('evidencia.jpg')],
        ]);

        $response->assertStatus(422);
        $this->assertSame('REVISADO_AUDITORIA', $project->fresh()->status);
        $this->assertNothingStored();
    }

    public function test_resubmit_with_embedded_code_keeps_the_project_rejected_and_unchanged(): void
    {
        $project = Project::factory()->create([
            'requested_by_user_id' => $this->infra->id,
            'status' => 'RECHAZADO_AUDITORIA',
            'title' => 'Título original',
        ]);

        $payload = [...$this->projectPayload(), 'title' => 'Título corregido'];
        unset($payload['type']);

        $response = $this->postMultipart($this->infra, "/api/projects/{$project->id}/resubmit", [
            'payload' => json_encode($payload),
            'photos' => [$this->polyglotJpeg()],
        ]);

        $response->assertStatus(422);
        $project->refresh();
        $this->assertSame('RECHAZADO_AUDITORIA', $project->status);
        $this->assertSame('Título original', $project->title);
        $this->assertNothingStored();
    }

    public function test_resubmit_with_replacement_creates_a_new_version_in_the_same_request(): void
    {
        $project = Project::factory()->create(['requested_by_user_id' => $this->infra->id, 'status' => 'RECHAZADO_AUDITORIA']);
        $doc = $project->documents()->create([
            'document_type' => 'PLANO',
            'original_name' => 'plano.pdf',
            'stored_path' => "project-documents/{$project->id}/PLANO/plano.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);
        $doc->update(['document_group_id' => $doc->id]);

        $payload = $this->projectPayload();
        unset($payload['type']);

        $response = $this->postMultipart($this->infra, "/api/projects/{$project->id}/resubmit", [
            'payload' => json_encode($payload),
            'replacements' => [['documentId' => $doc->id, 'file' => $this->cleanPdf('plano-v2.pdf')]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CREADO');
        $this->assertDatabaseHas('project_documents', [
            'project_id' => $project->id,
            'document_group_id' => $doc->id,
            'version_number' => 2,
            'original_name' => 'plano-v2.pdf',
        ]);
    }

    public function test_a_rejected_file_in_the_documents_endpoint_is_still_logged_as_security_event(): void
    {
        $project = Project::factory()->create();

        $this->postMultipart($this->auditoria, "/api/projects/{$project->id}/documents", [
            'document_type' => 'FOTO',
            'files' => [$this->polyglotJpeg()],
        ])->assertStatus(422);

        $this->assertSame(1, FileSecurityEvent::where('status', 'rejected')->count());
        $this->assertNothingStored();
    }
}
