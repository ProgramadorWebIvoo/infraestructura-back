<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;

/**
 * Aplica a TODO archivo, sin importar su tipo: ejecutables y scripts
 * disfrazados. Las firmas de pocos bytes (`<?=`, `<%`) solo se buscan en
 * tipos de texto; en binarios comprimidos aparecerían por azar y
 * rechazarían archivos legítimos.
 */
class ExecutableSignatureScanner implements FileThreatScanner
{
    private const HEADER_SIGNATURES = ["MZ", "\x7FELF", "\xCF\xFA\xED\xFE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCA\xFE\xBA\xBE", '#!'];

    public function supports(DetectedFile $file): bool
    {
        return true;
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        // En texto un encabezado como "MZ-001,..." es un código de material legítimo; un ejecutable real
        // trae bytes NUL y ya lo descarta FileTypeDetector al no parecer texto.
        $signatures = $file->isKind('csv', 'txt', 'dxf') ? ['#!'] : self::HEADER_SIGNATURES;

        foreach ($signatures as $signature) {
            if (str_starts_with($contents, $signature)) {
                throw new FileRejectedException('El archivo contiene código ejecutable y no está permitido.');
            }
        }

        if (stripos($contents, '<?php') !== false) {
            throw new FileRejectedException('El archivo contiene contenido no permitido embebido.');
        }

        if ($file->isKind('csv', 'txt', 'dxf') && preg_match('/<\?=|<%|<script\b/i', $contents) === 1) {
            throw new FileRejectedException('El archivo contiene contenido no permitido embebido.');
        }
    }
}
