<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;
use App\Support\ImageSegments;

/**
 * Valida que la imagen sea realmente decodificable y busca código oculto en
 * los lugares donde un atacante lo esconde: metadatos (EXIF/XMP/comentarios)
 * y bytes añadidos después del final de la imagen. Los píxeles NO se
 * escanean por firmas — son datos comprimidos pseudoaleatorios y darían
 * falsos positivos; además el re-encode posterior los reconstruye desde cero.
 */
class ImageThreatScanner implements FileThreatScanner
{
    private const MAX_PIXELS = 100_000_000;

    private const CODE_PATTERN = '/<\?php|<\?=|<script\b|\b(?:eval|assert|system|passthru|shell_exec|exec|popen|proc_open)\s*\(\s*(?:\$|base64_decode|gzinflate|str_rot13)/i';

    private const PAYLOAD_PATTERN = '/<\?php|<script\b|PK\x03\x04|%PDF-|\x7FELF|MZ\x90\x00/';

    public function supports(DetectedFile $file): bool
    {
        return $file->isImage();
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        $this->assertDecodable($file, $contents);

        [$metadata, $trailing] = ImageSegments::inspectable($file->kind, $contents);

        foreach ($metadata as $segment) {
            if (preg_match(self::CODE_PATTERN, $segment) === 1) {
                throw new FileRejectedException('El archivo contiene código embebido en los metadatos de la imagen.');
            }
        }

        if ($trailing !== '' && preg_match(self::PAYLOAD_PATTERN, $trailing) === 1) {
            throw new FileRejectedException('El archivo contiene datos ocultos después del final de la imagen.');
        }
    }

    private function assertDecodable(DetectedFile $file, string $contents): void
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            // TIFF no siempre lo soporta getimagesize en builds mínimas de GD; la cabecera ya se validó por magic bytes.
            if ($file->isKind('tiff')) {
                return;
            }
            throw new FileRejectedException("La imagen «{$file->originalName}» está dañada o no es válida.");
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new FileRejectedException("La imagen «{$file->originalName}» tiene dimensiones excesivas.");
        }
    }
}
