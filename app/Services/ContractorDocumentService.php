<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\ContractorDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Biblioteca documental del proveedor (F4 Bloque A). Todo archivo pasa por
 * FileIngestionPipeline (escáner + auditoría de seguridad) y queda en el
 * disco privado; nunca se expone una URL pública.
 */
class ContractorDocumentService
{
    public function __construct(private readonly FileIngestionPipeline $pipeline)
    {
    }

    /**
     * Guarda un archivo por tipo de documento, cada uno como grupo nuevo.
     *
     * @param array<int, UploadedFile> $filesByTypeId document_type_id => archivo
     * @return array<int, ContractorDocument>
     */
    public function storeMany(Contractor $contractor, array $filesByTypeId, string $source): array
    {
        return DB::transaction(function () use ($contractor, $filesByTypeId, $source) {
            $saved = [];
            foreach ($filesByTypeId as $typeId => $file) {
                $saved[] = $this->storeVersion($contractor, (int) $typeId, $file, $source);
            }

            return $saved;
        });
    }

    /**
     * Sube un archivo del tipo dado: si el proveedor ya tiene documento de
     * ese tipo crea una versión nueva del mismo grupo (conserva la anterior);
     * si no, abre un grupo nuevo.
     */
    public function replace(Contractor $contractor, int $typeId, UploadedFile $file, string $source): ContractorDocument
    {
        return DB::transaction(fn () => $this->storeVersion($contractor, $typeId, $file, $source));
    }

    /** Elimina (soft delete) el grupo completo; los archivos se conservan en disco. */
    public function removeGroup(ContractorDocument $document): int
    {
        return ContractorDocument::where('document_group_id', $document->document_group_id)->delete();
    }

    /** @return array{complete: bool, missing: array<int, array{id: int, key: string, label: string}>} */
    public function completeness(Contractor $contractor): array
    {
        $providedTypeIds = $contractor->documents()->latestVersionOnly()->pluck('document_type_id')->all();

        $missing = ContractorDocumentType::active()->required()
            ->whereNotIn('id', $providedTypeIds)
            ->get(['id', 'key', 'label'])
            ->map(fn ($type) => ['id' => $type->id, 'key' => $type->key, 'label' => $type->label])
            ->all();

        return ['complete' => $missing === [], 'missing' => $missing];
    }

    /** IDs de los tipos activos obligatorios. */
    public function requiredTypeIds(): array
    {
        return ContractorDocumentType::active()->required()->pluck('id')->all();
    }

    public function exists(ContractorDocument $document): bool
    {
        return Storage::disk('local')->exists($document->stored_path);
    }

    private function storeVersion(Contractor $contractor, int $typeId, UploadedFile $file, string $source): ContractorDocument
    {
        $current = ContractorDocument::where('contractor_code', $contractor->code)
            ->where('document_type_id', $typeId)
            ->latestVersionOnly()
            ->lockForUpdate()
            ->first();

        $groupId = $current?->document_group_id;
        $nextVersion = $groupId !== null
            ? ContractorDocument::withTrashed()->where('document_group_id', $groupId)->max('version_number') + 1
            : 1;

        $directory = "contractor-documents/{$contractor->code}/{$typeId}";
        $ingested = $this->pipeline->ingest($file, $directory, 'contractor_document', $contractor->code);

        $document = ContractorDocument::create([
            'contractor_code' => $contractor->code,
            'document_type_id' => $typeId,
            'document_group_id' => $groupId,
            'version_number' => $nextVersion,
            'stored_path' => $ingested->storedPath,
            'original_name' => $ingested->originalName,
            'mime_type' => $ingested->mimeType,
            'size_bytes' => $ingested->sizeBytes,
            'sha256' => $ingested->sha256,
            'uploaded_by' => auth()->id(),
            'source' => $source,
        ]);

        if ($groupId === null) {
            $document->update(['document_group_id' => $document->id]);
        }

        return $document;
    }
}
