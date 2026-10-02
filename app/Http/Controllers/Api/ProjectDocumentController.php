<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectDocumentRequest;
use App\Http\Resources\ProjectDocumentResource;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\ProjectDocumentService;
use Illuminate\Http\Request;
use App\Support\StoragePaths;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $query = $project->documents();

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        if (!$request->boolean('all_versions')) {
            $query->latestVersionOnly();
        }

        $documents = $query->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json([
            'data' => ProjectDocumentResource::collection($documents),
        ]);
    }

    /**
     * Historial completo (todas las versiones, incluidas soft-deleted) del
     * grupo al que pertenece el documento $documentId. Resuelve manualmente
     * en vez de route-model-binding automático: Laravel 9 no soporta
     * `Route::withTrashed()` (eso llegó en 10.14+), y el binding por defecto
     * excluye soft-deleted — así que un documento cuyo grupo entero fue
     * eliminado nunca resolvería como {document} en la ruta.
     */
    public function history(Project $project, int $documentId)
    {
        $document = ProjectDocument::withTrashed()->findOrFail($documentId);
        abort_unless($document->project_id === $project->id, 404);

        $versions = ProjectDocument::withTrashed()
            ->where('document_group_id', $document->document_group_id)
            ->orderBy('version_number')
            ->get();

        return response()->json([
            'data' => ProjectDocumentResource::collection($versions),
        ]);
    }

    public function upload(StoreProjectDocumentRequest $request, Project $project, ProjectDocumentService $documents)
    {
        $type = $request->input('document_type');
        $role = auth()->user()->role;

        // Los comprobantes de pago son evidencia bancaria exclusiva de
        // Finanzas — el resto de los tipos son documentación técnica del
        // ciclo de vida de la obra, ajena a Finanzas.
        $isComprobante = in_array($type, ['COMPROBANTE_ANTICIPO', 'COMPROBANTE_FINIQUITO'], true);
        if ($isComprobante) {
            abort_unless(in_array($role, ['FINANZAS', 'ADMIN', 'SUPERADMIN'], true), 403, 'Solo Finanzas puede adjuntar comprobantes de pago.');
        } else {
            abort_if($role === 'FINANZAS', 403, 'Finanzas solo puede adjuntar comprobantes de pago.');
        }

        $group = ['type' => $type, 'files' => $request->file('files'), 'newVersionOf' => $request->input('new_version_of')];

        // Pared de seguridad sobre todos los archivos antes de tocar BD/disco.
        $documents->preflight([$group], $project->id);

        $saved = array_map(fn (array $s) => [
            ...(new ProjectDocumentResource($s['document']->fresh()))->resolve(),
            'optimized' => $s['optimized'],
        ], $documents->attachGroups($project, [$group]));

        return response()->json(['data' => $saved], 201);
    }

    /**
     * Elimina el grupo completo (todas las versiones), no una versión suelta.
     * Infraestructura solo puede borrar mientras el proyecto está
     * RECHAZADO_AUDITORIA (editando/reenviando su propia petición tras un
     * rechazo) — fuera de ese estado, borrar adjuntos queda reservado a
     * Auditoría (dueño natural de la documentación técnica, ver
     * RevisedDocumentsSection.tsx). Tampoco puede borrar CORRECCION: son
     * las correcciones que Auditoría adjuntó al rechazar, quedan como
     * histórico/evidencia, no un adjunto propio de Infraestructura.
     */
    public function destroy(Project $project, ProjectDocument $document, ProjectDocumentService $documents)
    {
        abort_unless($document->project_id === $project->id, 404);

        $role = auth()->user()->role;
        if ($role === 'INFRAESTRUCTURA') {
            abort_unless($project->status === 'RECHAZADO_AUDITORIA', 403, 'Solo puede eliminar adjuntos mientras corrige una petición rechazada.');
            abort_if($document->document_type === 'CORRECCION', 403, 'Las correcciones de Auditoría no pueden eliminarse.');
        }

        $versions = ProjectDocument::where('document_group_id', $document->document_group_id)->get();

        foreach ($versions as $version) {
            if (Storage::disk(StoragePaths::disk())->exists($version->stored_path)) {
                Storage::disk(StoragePaths::disk())->delete($version->stored_path);
            }
        }

        $label = $versions->first()?->original_name ?? $document->original_name;
        $count = $versions->count();

        ProjectDocument::where('document_group_id', $document->document_group_id)->delete();

        $documents->syncCounts($project);
        AuditLog::record(
            $project,
            $role,
            'Eliminacion de documento adjunto',
            "Grupo eliminado: {$label} ({$count} version(es))"
        );

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    public function download(Project $project, ProjectDocument $document): StreamedResponse
    {
        abort_unless($document->project_id === $project->id, 404);
        abort_unless(Storage::disk(StoragePaths::disk())->exists($document->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return Storage::disk(StoragePaths::disk())->download(
            $document->stored_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type ?? 'application/octet-stream']
        );
    }

    /** Igual que download(), pero sin forzar descarga — para el previsualizador. */
    public function preview(Project $project, ProjectDocument $document): StreamedResponse
    {
        abort_unless($document->project_id === $project->id, 404);
        abort_unless(Storage::disk(StoragePaths::disk())->exists($document->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return new StreamedResponse(function () use ($document) {
            echo Storage::disk(StoragePaths::disk())->get($document->stored_path);
        }, 200, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
        ]);
    }
}
