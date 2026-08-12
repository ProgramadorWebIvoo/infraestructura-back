<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectDocumentRequest;
use App\Http\Resources\ProjectDocumentResource;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    public function index(Project $project)
    {
        $documents = $project->documents()->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json([
            'data' => ProjectDocumentResource::collection($documents),
        ]);
    }

    public function upload(StoreProjectDocumentRequest $request, Project $project, DocumentStorageService $storage)
    {
        $type  = $request->input('document_type');
        $directory = "project-documents/{$project->id}/{$type}";
        $saved = [];

        foreach ($request->file('files') as $file) {
            $mime = $file->getMimeType() ?? $file->getClientMimeType();

            // Sanitize filename to prevent path traversal
            $safeName = $storage->sanitizeFilename($file->getClientOriginalName());
            $uniqueName = $storage->uniqueFilename($directory, $safeName);

            $storedPath = $file->storeAs($directory, $uniqueName, 'local');

            $doc = $project->documents()->create([
                'document_type' => $type,
                'original_name' => $uniqueName,
                'stored_path'   => $storedPath,
                'mime_type'     => $mime,
                'size_bytes'    => $file->getSize(),
                'uploaded_by'   => auth()->id(),
            ]);

            $saved[] = (new ProjectDocumentResource($doc))->resolve();
        }

        $label = $type === 'CALC' ? 'hojas de calculo/cubicaciones' : 'planos de ingenieria';
        AuditLog::record($project, 'CIERRE_DE_OBRA', "Carga de {$label}", count($saved) . " archivo(s) adjuntados: " . implode(', ', array_column($saved, 'originalName')));

        // Sync counts back to project for backward compatibility
        $this->syncProjectCounts($project);

        return response()->json(['data' => $saved], 201);
    }

    public function destroy(Project $project, ProjectDocument $document)
    {
        abort_unless($document->project_id === $project->id, 404);

        if (Storage::disk('local')->exists($document->stored_path)) {
            Storage::disk('local')->delete($document->stored_path);
        }

        $label = $document->original_name;
        $document->delete();

        $this->syncProjectCounts($project);
        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Eliminacion de documento adjunto', "Archivo eliminado: {$label}");

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    public function download(Project $project, ProjectDocument $document): StreamedResponse
    {
        abort_unless($document->project_id === $project->id, 404);
        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return Storage::disk('local')->download(
            $document->stored_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type ?? 'application/octet-stream']
        );
    }

    private function syncProjectCounts(Project $project): void
    {
        $project->update([
            'calculations_added' => $project->documents()->where('document_type', 'CALC')->exists(),
            'blueprints_count'   => $project->documents()->where('document_type', 'PLANO')->count(),
        ]);
    }
}
