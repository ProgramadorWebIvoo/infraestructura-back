<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;

/**
 * Un inspector de amenazas para un tipo de archivo. FileSecurityScanner los
 * ejecuta todos los que declaren soportar el archivo: agregar protección para
 * un tipo nuevo es crear una clase y registrarla, sin tocar las existentes.
 */
interface FileThreatScanner
{
    public function supports(DetectedFile $file): bool;

    /**
     * @throws FileRejectedException con un mensaje seguro para el usuario final
     */
    public function assertSafe(DetectedFile $file, string $contents): void;
}
