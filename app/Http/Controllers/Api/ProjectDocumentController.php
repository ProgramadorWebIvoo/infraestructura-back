<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    private const ALLOWED_CALC_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/vnd.ms-excel',                                           // xls
        'text/csv',
        'text/plain',
        'application/pdf',
        'application/vnd.oasis.opendocument.spreadsheet',                    // ods
    ];

    private const ALLOWED_PLANO_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/svg+xml',
        'image/tiff',
        'application/acad',           // dwg (generic)
        'application/octet-stream',   // dwg/dxf often sent as binary
    ];

    public function index(Project $project)
    {
        $documents = $project->documents()->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json([
            'data' => $documents->map(fn ($d) => $this->formatDocument($d)),
        ]);
    }

    public function upload(Request $request, Project $project)
    {
        $request->validate([
            'document_type' => ['required', Rule::in(['CALC', 'PLANO'])],
            'files'         => ['required', 'array', 'min:1', 'max:10'],
            'files.*'       => ['required', 'file', 'max:51200'], // 50 MB per file
        ]);

        $type  = $request->input('document_type');
        $saved = [];

        foreach ($request->file('files') as $file) {
            $originalName = $file->getClientOriginalName();
            $mime         = $file->getMimeType() ?? $file->getClientMimeType();

            // Store under project-documents/{project_id}/{type}/
            $directory = "project-documents/{$project->id}/{$type}";
            $storedPath = $file->storeAs($directory, $originalName, 'local');

            $doc = $project->documents()->create([
                'document_type' => $type,
                'original_name' => $originalName,
                'stored_path'   => $storedPath,
                'mime_type'     => $mime,
                'size_bytes'    => $file->getSize(),
                'uploaded_by'   => auth()->id(),
            ]);

            $saved[] = $this->formatDocument($doc);
        }

        $label = $type === 'CALC' ? 'hojas de calculo/cubicaciones' : 'planos de ingenieria';
        $this->log($project, "Carga de {$label}", count($saved) . " archivo(s) adjuntados: " . implode(', ', array_column($saved, 'originalName')));

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
        $this->log($project, 'Eliminacion de documento adjunto', "Archivo eliminado: {$label}");

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

    private function formatDocument(ProjectDocument $doc): array
    {
        return [
            'id'           => $doc->id,
            'documentType' => $doc->document_type,
            'originalName' => $doc->original_name,
            'mimeType'     => $doc->mime_type,
            'sizeBytes'    => $doc->size_bytes,
            'uploadedBy'   => $doc->uploaded_by,
            'uploadedAt'   => $doc->created_at?->toIso8601String(),
        ];
    }

    private function log(Project $project, string $action, ?string $details): void
    {
        $user = auth()->user();
        AuditLog::create([
            'id'                     => 'LOG-' . now()->format('YmdHisv'),
            'project_id'             => $project->id,
            'project_title_snapshot' => $project->title,
            'role'                   => 'CIERRE_DE_OBRA',
            'user_id'                => $user?->id,
            'user_name_snapshot'     => $user?->name,
            'action'                 => $action,
            'logged_at'              => now(),
            'details'                => $details,
        ]);
    }
}
