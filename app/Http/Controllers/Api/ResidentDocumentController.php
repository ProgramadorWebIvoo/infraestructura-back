<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectDocumentResource;
use App\Models\Project;
use App\Models\ProjectDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos técnicos de solo lectura para el residente (F2-R R3): únicamente
 * planos, cálculos y fotos; nunca comprobantes de pago ni documentos internos.
 */
class ResidentDocumentController extends Controller
{
    private const ALLOWED_TYPES = ['PLANO', 'CALC', 'FOTO'];

    public function __construct(private ProjectDocumentController $documents)
    {
    }

    public function index(Project $project)
    {
        $documents = $project->documents()->latestVersionOnly()
            ->whereIn('document_type', self::ALLOWED_TYPES)
            ->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json(['data' => ProjectDocumentResource::collection($documents)]);
    }

    public function download(Project $project, ProjectDocument $document): StreamedResponse
    {
        $this->assertAllowed($document);

        return $this->documents->download($project, $document);
    }

    public function preview(Project $project, ProjectDocument $document): StreamedResponse
    {
        $this->assertAllowed($document);

        return $this->documents->preview($project, $document);
    }

    private function assertAllowed(ProjectDocument $document): void
    {
        abort_unless(in_array($document->document_type, self::ALLOWED_TYPES, true), 404);
    }
}
