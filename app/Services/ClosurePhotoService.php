<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectClosurePhoto;
use App\Models\ProjectClosureReport;
use Illuminate\Http\UploadedFile;
use App\Support\StoragePaths;
use App\Support\StoredFileResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fotos de evidencia del informe de cierre (contratista y residente), con la misma pared de seguridad que el resto de subidas. */
class ClosurePhotoService
{
    public function __construct(
        private FileIngestionPipeline $pipeline,
        private StorageFolderService $folders,
    ) {
    }

    public function store(ProjectClosureReport $report, UploadedFile $file, string $byType, ?int $userId, ?int $itemId): ProjectClosurePhoto
    {
        abort_if($itemId !== null && !$report->items()->whereKey($itemId)->exists(), 422, 'La partida no pertenece al informe.');

        $uploader = $byType === ProjectClosurePhoto::BY_RESIDENT ? 'residente' : 'contratista';
        $directory = $this->folders->projectFolder($report->project) . '/' . StoragePaths::CLOSURE . '/' . $uploader;
        $ingested = $this->pipeline->ingest($file, $directory, 'closure_photo', $report->id);

        return $report->photos()->create([
            'item_id' => $itemId,
            'uploaded_by_type' => $byType,
            'uploaded_by_user_id' => $userId,
            'original_name' => $ingested->originalName,
            'stored_path' => $ingested->storedPath,
            'mime_type' => $ingested->mimeType,
            'size_bytes' => $ingested->sizeBytes,
        ]);
    }

    public function delete(ProjectClosurePhoto $photo): void
    {
        Storage::disk(StoragePaths::disk())->delete($photo->stored_path);
        $photo->delete();
    }

    /** Sirve la foto solo si pertenece al informe de la obra indicada. */
    public function streamForProject(Project $project, ProjectClosurePhoto $photo): StreamedResponse
    {
        abort_unless($project->closureReport && $photo->report_id === $project->closureReport->id, 404);

        return $this->stream($photo);
    }

    public function stream(ProjectClosurePhoto $photo): StreamedResponse
    {
        return StoredFileResponse::inline($photo->stored_path, $photo->original_name, $photo->mime_type);
    }
}
