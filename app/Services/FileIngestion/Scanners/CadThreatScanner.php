<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;

/**
 * DXF de texto: debe parecer realmente un DXF (secciones SECTION/ENDSEC),
 * no un texto cualquiera con extensión .dxf. DWG ya se valida por su firma
 * `AC10xx` en FileTypeDetector y su contenido binario no es interpretable.
 */
class CadThreatScanner implements FileThreatScanner
{
    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('dxf');
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        if (str_starts_with($contents, 'AutoCAD Binary DXF')) {
            return;
        }

        if (stripos(substr($contents, 0, 65536), 'SECTION') === false) {
            throw new FileRejectedException("El contenido de «{$file->originalName}» no corresponde a un archivo DXF válido.");
        }
    }
}
