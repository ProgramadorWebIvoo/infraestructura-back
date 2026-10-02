<?php

namespace App\Services\FileIngestion\Processors;

/**
 * Motor de compresión de PDF. Devuelve el PDF reescrito o null si no puede
 * (o no ayuda) con ese archivo; PdfProcessor valida y compara el resultado.
 */
interface PdfOptimizer
{
    public function optimize(string $pdf): ?string;
}
