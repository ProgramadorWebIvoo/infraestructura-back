<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectDocumentRequest;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    public function index(Project $project)
    {
        $documents = $project->documents()->orderBy('document_type')->orderBy('created_at')->get();

        return response()->json([
            'data' => $documents->map(fn ($d) => $this->formatDocument($d)),
        ]);
    }

    public function upload(StoreProjectDocumentRequest $request, Project $project)
    {
        $type  = $request->input('document_type');
        $directory = "project-documents/{$project->id}/{$type}";
        $saved = [];

        foreach ($request->file('files') as $file) {
            $mime = $file->getMimeType() ?? $file->getClientMimeType();

            // Sanitize filename to prevent path traversal
            $safeName = $this->sanitizeFilename($file->getClientOriginalName());
            $uniqueName = $this->uniqueFilename($directory, $safeName);

            $storedPath = $file->storeAs($directory, $uniqueName, 'local');

            $doc = $project->documents()->create([
                'document_type' => $type,
                'original_name' => $uniqueName,
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

    /**
     * Sanitize filename to prevent path traversal and remove dangerous characters.
     *
     * - Strips directory components (basename only)
     * - Removes null bytes
     * - Keeps only alphanumeric, dash, underscore, dot, space
     * - Collapses repeated separators
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove path traversal
        $filename = basename($filename);

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Normalize UTF-8 (NFD -> NFC) to avoid composed/decomposed issues
        if (class_exists('Normalizer')) {
            $filename = normalizer_normalize($filename, \Normalizer::NFC);
        }

        // Replace any character that is not alphanumeric, dot, dash, underscore, or space
        $filename = preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', $filename);

        // Collapse multiple underscores/spaces into single underscore
        $filename = preg_replace('/[ _]+/', '_', $filename);

        // Trim dots, spaces, underscores from edges
        $filename = trim($filename, ' ._');

        // Fallback if name is empty after sanitization
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file_' . now()->format('YmdHisv');
        }

        return $filename;
    }

    /**
     * Ensure the filename is unique in the target directory to prevent overwrites.
     * Appends a timestamp suffix if a file with the same name already exists.
     */
    private function uniqueFilename(string $directory, string $filename): string
    {
        $disk = Storage::disk('local');

        if (!$disk->exists($directory . '/' . $filename)) {
            return $filename;
        }

        $info = pathinfo($filename);
        $base = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        return $base . '_' . now()->format('YmdHisv') . $ext;
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
            'id'                     => 'LOG-' . now()->format('YmdHisv') . '-' . Str::random(4),
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
