<?php

namespace App\Services\FileIngestion;

/**
 * Tipo REAL de un archivo (por su contenido, no por lo que declara el
 * cliente) más sus valores normalizados: mime y extensión canónicos que son
 * los que se guardan, sin importar cómo vino nombrado (.jpeg → .jpg).
 */
final class DetectedFile
{
    public const IMAGE_KINDS = ['png', 'jpeg', 'webp', 'tiff'];

    public function __construct(
        public readonly string $kind,
        public readonly string $mime,
        public readonly string $extension,
        public readonly string $path,
        public readonly string $originalName,
    ) {
    }

    public function isKind(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }

    public function isImage(): bool
    {
        return in_array($this->kind, self::IMAGE_KINDS, true);
    }
}
