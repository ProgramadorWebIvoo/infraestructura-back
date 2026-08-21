<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectDocumentRequest;
use App\Http\Resources\ProjectDocumentResource;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $query = $project->documents();

        if (!$request->boolean('all_versions')) {
            $query->latestVersionOnly();
        }

        $documents = $query->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json([
            'data' => ProjectDocumentResource::collection($documents),
        ]);
    }

    /** Historial completo (todas las versiones) del grupo al que pertenece $document. */
    public function history(Project $project, ProjectDocument $document)
    {
        abort_unless($document->project_id === $project->id, 404);

        $versions = ProjectDocument::where('document_group_id', $document->document_group_id)
            ->orderBy('version_number')
            ->get();

        return response()->json([
            'data' => ProjectDocumentResource::collection($versions),
        ]);
    }

    public function upload(StoreProjectDocumentRequest $request, Project $project, DocumentStorageService $storage)
    {
        $type = $request->input('document_type');
        $newVersionOfId = $request->input('new_version_of');
        $saved = [];

        DB::transaction(function () use ($request, $project, $storage, $type, $newVersionOfId, &$saved) {
            $groupId = null;
            $nextVersion = 1;

            if ($newVersionOfId !== null) {
                $original = ProjectDocument::where('id', $newVersionOfId)
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $groupId = $original->document_group_id;
                $type = $original->document_type; // la versión no puede cambiar el tipo del documento
                $nextVersion = ProjectDocument::where('document_group_id', $groupId)
                    ->lockForUpdate()
                    ->max('version_number') + 1;
            }

            foreach ($request->file('files') as $file) {
                $mime = $file->getMimeType() ?? $file->getClientMimeType();

                $directory = $groupId !== null
                    ? "project-documents/{$project->id}/{$type}/{$groupId}"
                    : "project-documents/{$project->id}/{$type}";

                $safeName = $storage->sanitizeFilename($file->getClientOriginalName());
                $uniqueName = $storage->uniqueFilename($directory, $safeName);
                $storedPath = $file->storeAs($directory, $uniqueName, 'local');

                $isNewGroup = $groupId === null;

                $doc = $project->documents()->create([
                    'document_group_id' => $groupId,
                    'version_number' => $nextVersion,
                    'document_type' => $type,
                    'original_name' => $uniqueName,
                    'stored_path' => $storedPath,
                    'mime_type' => $mime,
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => auth()->id(),
                ]);

                if ($isNewGroup) {
                    $doc->update(['document_group_id' => $doc->id]);
                    // Reflejar en la carpeta física el group id recién asignado.
                    $finalDirectory = "project-documents/{$project->id}/{$type}/{$doc->id}";
                    $finalPath = $finalDirectory . '/' . basename($storedPath);
                    Storage::disk('local')->move($storedPath, $finalPath);
                    $doc->update(['stored_path' => $finalPath]);
                }

                $saved[] = (new ProjectDocumentResource($doc->fresh()))->resolve();
            }

            $label = match ($type) {
                'CALC' => 'hojas de calculo/cubicaciones',
                'PLANO' => 'planos de ingenieria',
                'FOTO' => 'fotografias del sitio de obra',
                'CORRECCION' => 'correcciones de peticion rechazada',
                default => 'documentos',
            };

            $names = implode(', ', array_column($saved, 'originalName'));
            $action = $newVersionOfId !== null
                ? "Nueva version (V{$nextVersion}) de documento"
                : "Carga de {$label}";

            AuditLog::record($project, 'CIERRE_DE_OBRA', $action, "{$names}");

            $this->syncProjectCounts($project);
        });

        return response()->json(['data' => $saved], 201);
    }

    /** Elimina el grupo completo (todas las versiones), no una versión suelta. */
    public function destroy(Project $project, ProjectDocument $document)
    {
        abort_unless($document->project_id === $project->id, 404);

        $versions = ProjectDocument::where('document_group_id', $document->document_group_id)->get();

        foreach ($versions as $version) {
            if (Storage::disk('local')->exists($version->stored_path)) {
                Storage::disk('local')->delete($version->stored_path);
            }
        }

        $label = $versions->first()?->original_name ?? $document->original_name;
        $count = $versions->count();

        ProjectDocument::where('document_group_id', $document->document_group_id)->delete();

        $this->syncProjectCounts($project);
        AuditLog::record(
            $project,
            'CIERRE_DE_OBRA',
            'Eliminacion de documento adjunto (todas las versiones)',
            "Grupo eliminado: {$label} ({$count} version(es))"
        );

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

    /** Igual que download(), pero sin forzar descarga — para el previsualizador. */
    public function preview(Project $project, ProjectDocument $document): StreamedResponse
    {
        abort_unless($document->project_id === $project->id, 404);
        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return new StreamedResponse(function () use ($document) {
            echo Storage::disk('local')->get($document->stored_path);
        }, 200, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
        ]);
    }

    private function syncProjectCounts(Project $project): void
    {
        $latestIds = $project->documents()->latestVersionOnly()->pluck('id');

        $project->update([
            'calculations_added' => ProjectDocument::whereIn('id', $latestIds)->where('document_type', 'CALC')->exists(),
            'blueprints_count'   => ProjectDocument::whereIn('id', $latestIds)->where('document_type', 'PLANO')->count(),
        ]);
    }
}
