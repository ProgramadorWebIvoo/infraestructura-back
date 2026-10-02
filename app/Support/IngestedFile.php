<?php

namespace App\Support;

/**
 * Resultado de FileIngestionPipeline::ingest() — ya escrito a disco,
 * listo para persistir en la tabla del caller (project_documents,
 * marketing_project_attachments, etc.). `mimeType` y el nombre guardado son
 * los canónicos (detectados por contenido), no los que declaró el cliente.
 * `optimized` indica que el contenido final difiere del recibido (comprimido,
 * sin metadatos, sanitizado o normalizado).
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
        public readonly int $originalSizeBytes = 0,
    ) {
    }
}
