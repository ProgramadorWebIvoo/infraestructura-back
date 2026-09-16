<?php

namespace App\Support;

/**
 * Resultado de FileIngestionPipeline::ingest() — ya escrito a disco,
 * listo para persistir en la tabla del caller (project_documents,
 * marketing_project_attachments, etc.).
 */
final class IngestedFile
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $storedPath,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly bool $optimized,
    ) {
    }
}
