<?php

namespace App\Services\FileIngestion\Scanners;

use App\Services\FileIngestion\DetectedFile;
use App\Services\SvgSanitizer;

/**
 * El SVG es XML que el navegador ejecuta. La detección y la limpieza viven
 * en SvgSanitizer: aquí solo se corre en seco para que un SVG con script se
 * rechace en la pared de seguridad, antes de abrir ninguna transacción.
 */
class SvgThreatScanner implements FileThreatScanner
{
    public function __construct(private readonly SvgSanitizer $sanitizer)
    {
    }

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('svg');
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        $this->sanitizer->sanitize($contents);
    }
}
