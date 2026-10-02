<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;

/**
 * CSV/TXT: además del código embebido (cubierto por ExecutableSignatureScanner),
 * rechaza inyección de fórmulas DDE (`=cmd|'/c calc'!A0`), que Excel ejecuta
 * al abrir el CSV. Una fórmula normal (`=SUM(A1:A3)`, un negativo `-5`) pasa.
 */
class TextThreatScanner implements FileThreatScanner
{
    private const DDE_FORMULA = '/(^|[,;\t"])\s*[=+\-@]\s*[A-Za-z0-9_\/\\.]+\s*\|\s*[\'"][^\r\n]*!/m';

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('csv', 'txt');
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        if (preg_match(self::DDE_FORMULA, $contents) === 1) {
            throw new FileRejectedException('El archivo contiene fórmulas que ejecutan comandos y no está permitido.');
        }
    }
}
