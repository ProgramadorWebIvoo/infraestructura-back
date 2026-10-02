<?php

namespace App\Services\FileIngestion\Processors;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;

/**
 * Sanitiza, normaliza y comprime un tipo de archivo ya aprobado por la pared
 * de seguridad. FileIngestionPipeline usa el primer procesador que lo
 * soporte; un tipo nuevo se agrega con una clase y su registro, sin tocar las
 * existentes. El procesador devuelve el contenido final (idéntico al de
 * entrada si no hubo nada que mejorar) y nunca uno más pesado que el original
 * cuando la única intención era comprimir.
 */
interface FileProcessor
{
    public function supports(DetectedFile $file): bool;

    /**
     * @throws FileRejectedException si al procesar se descubre contenido no permitido
     */
    public function process(DetectedFile $file, string $contents): string;
}
