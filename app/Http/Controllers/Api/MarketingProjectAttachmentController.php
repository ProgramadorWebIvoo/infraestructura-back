<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketingProjectAttachmentRequest;
use App\Http\Resources\MarketingProjectAttachmentResource;
use App\Models\ConfigAuditLog;
use App\Models\MarketingProject;
use App\Models\MarketingProjectAttachment;
use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imagenes/artes adjuntos de una pieza de Marketing. Simplificado respecto
 * a ProjectDocumentController: sin versionado por grupo (acá cada adjunto
 * es una referencia/mockup independiente, no una revisión de otro).
 */
class MarketingProjectAttachmentController extends Controller
{
    public function index(MarketingProject $marketingProject)
    {
        return MarketingProjectAttachmentResource::collection(
            $marketingProject->attachments()->orderBy('created_at')->get()
        );
    }

    public function upload(StoreMarketingProjectAttachmentRequest $request, MarketingProject $marketingProject, DocumentStorageService $storage)
    {
        $user = auth()->user();
        $isAdmin = in_array($user->role, ['ADMIN', 'SUPERADMIN'], true);
        abort_unless($isAdmin || $marketingProject->requested_by === $user->id, 403, 'No tiene permiso sobre esta propuesta.');
        abort_unless(
            in_array($marketingProject->status, [MarketingProject::STATUSES['BORRADOR'], MarketingProject::STATUSES['RECHAZADO']], true),
            403,
            'Solo se pueden adjuntar archivos mientras la propuesta esta en borrador o rechazada.'
        );

        $directory = "marketing-projects/{$marketingProject->id}";
        $saved = [];

        foreach ($request->file('files') as $file) {
            $safeName = $storage->sanitizeFilename($file->getClientOriginalName());
            $uniqueName = $storage->uniqueFilename($directory, $safeName);
            $storedPath = $file->storeAs($directory, $uniqueName, 'local');

            $attachment = $marketingProject->attachments()->create([
                'original_name' => $uniqueName,
                'stored_path' => $storedPath,
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $user->id,
            ]);

            $saved[] = (new MarketingProjectAttachmentResource($attachment))->resolve();
        }

        $names = implode(', ', array_column($saved, 'originalName'));
        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Carga de adjunto de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id}): {$names}"
        );

        return response()->json(['data' => $saved], 201);
    }

    public function destroy(MarketingProject $marketingProject, MarketingProjectAttachment $attachment)
    {
        abort_unless($attachment->marketing_project_id === $marketingProject->id, 404);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['ADMIN', 'SUPERADMIN'], true);
        abort_unless($isAdmin || $marketingProject->requested_by === $user->id, 403, 'No tiene permiso sobre esta propuesta.');

        if (Storage::disk('local')->exists($attachment->stored_path)) {
            Storage::disk('local')->delete($attachment->stored_path);
        }

        $name = $attachment->original_name;
        $attachment->delete();

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Eliminacion de adjunto de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id}): {$name}"
        );

        return response()->json(['message' => 'Adjunto eliminado correctamente.']);
    }

    public function download(MarketingProject $marketingProject, MarketingProjectAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->marketing_project_id === $marketingProject->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return Storage::disk('local')->download(
            $attachment->stored_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream']
        );
    }

    /** Igual que download(), pero sin forzar descarga — para el previsualizador. */
    public function preview(MarketingProject $marketingProject, MarketingProjectAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->marketing_project_id === $marketingProject->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404, 'El archivo ya no existe en el servidor.');

        return new StreamedResponse(function () use ($attachment) {
            echo Storage::disk('local')->get($attachment->stored_path);
        }, 200, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $attachment->original_name . '"',
        ]);
    }
}
