<?php

namespace App\Services;

use App\Models\ProjectClosurePhoto;
use App\Models\ProjectClosureReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fotos de evidencia del informe de cierre (contratista y residente), con la misma pared de seguridad que el resto de subidas. */
class ClosurePhotoService
{
    public function __construct(private FileIngestionPipeline $pipeline)
    {
    }

    public function store(ProjectClosureReport $report, UploadedFile $file, string $byType, ?int $userId, ?int $itemId): ProjectClosurePhoto
    {
        abort_if($itemId !== null && !$report->items()->whereKey($itemId)->exists(), 422, 'La partida no pertenece al informe.');

        $ingested = $this->pipeline->ingest($file, "closure-photos/{$report->id}", 'closure_photo', $report->id);

        return $report->photos()->create([
            'item_id' => $itemId,
            'uploaded_by_type' => $byType,
            'uploaded_by_user_id' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $ingested->storedPath,
            'mime_type' => $ingested->mimeType,
            'size_bytes' => $ingested->sizeBytes,
        ]);
    }

    public function delete(ProjectClosurePhoto $photo): void
    {
        Storage::disk('local')->delete($photo->stored_path);
        $photo->delete();
    }

    public function stream(ProjectClosurePhoto $photo): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($photo->stored_path), 404);

        return Storage::disk('local')->response($photo->stored_path, $photo->original_name, ['Content-Type' => $photo->mime_type]);
    }
}
