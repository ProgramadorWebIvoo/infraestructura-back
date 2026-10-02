<?php

namespace App\Services\FileIngestion\Processors;

use Symfony\Component\Process\Process;

/**
 * Reescribe el PDF con Ghostscript (perfil /printer: imágenes a 300 ppp,
 * sin diferencia visible), que además descarta acciones y metadatos. Solo
 * actúa si GHOSTSCRIPT_BINARY está configurado.
 */
class GhostscriptPdfOptimizer implements PdfOptimizer
{
    public function optimize(string $pdf): ?string
    {
        $binary = config('files.ghostscript_binary');
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $input = tempnam(sys_get_temp_dir(), 'ivoo-pdf-in');
        $output = tempnam(sys_get_temp_dir(), 'ivoo-pdf-out');

        try {
            file_put_contents($input, $pdf);

            $process = new Process([
                $binary, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/printer', '-dDetectDuplicateImages=true',
                '-dCompressFonts=true', '-sOutputFile=' . $output, $input,
            ], null, null, null, (float) config('files.ghostscript_timeout', 60));
            $process->run();

            $result = $process->isSuccessful() ? (string) file_get_contents($output) : '';

            return str_starts_with($result, '%PDF-') ? $result : null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
