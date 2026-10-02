<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;
use App\Support\PdfStreams;

/**
 * Un PDF puede ejecutar JavaScript, lanzar programas, abrir destinos remotos
 * o llevar adjuntos ocultos. Se buscan esas acciones en la estructura del
 * documento y dentro de sus object streams descomprimidos, normalizando los
 * nombres con escapes `#xx` (`/J#61vaScript`) que los lectores interpretan
 * igual que el original (ver PdfStreams).
 */
class PdfThreatScanner implements FileThreatScanner
{
    private const DANGEROUS_ACTIONS = '/\/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|SubmitForm|ImportData|GoToR|GoToE|XFA|Movie|Sound|Rendition|3D)(?![A-Za-z0-9])/';

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('pdf');
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        foreach (PdfStreams::inspectable($contents) as $text) {
            $normalized = PdfStreams::normalizeNames($text);

            if (preg_match('/\/Encrypt(?![A-Za-z0-9])/', $normalized) === 1) {
                throw new FileRejectedException('El PDF está cifrado y no se puede verificar. Súbalo sin contraseña.');
            }

            if (preg_match(self::DANGEROUS_ACTIONS, $normalized) === 1) {
                throw new FileRejectedException('El PDF contiene código o acciones activas (JavaScript, adjuntos o ejecución) y no está permitido.');
            }
        }
    }
}
