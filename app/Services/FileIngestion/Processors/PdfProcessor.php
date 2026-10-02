<?php

namespace App\Services\FileIngestion\Processors;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;
use App\Services\FileIngestion\Scanners\PdfThreatScanner;

/**
 * Comprime el PDF con el primer motor que logre reducirlo (Ghostscript si
 * está configurado, luego el optimizador PHP). Todo resultado se vuelve a
 * inspeccionar con PdfThreatScanner antes de aceptarlo, y se descarta si no
 * pesa claramente menos que el original. Un PDF con firma digital no se toca:
 * reescribirlo invalidaría la firma.
 */
class PdfProcessor implements FileProcessor
{
    private const MIN_GAIN = 0.03;

    /** @var array<int, PdfOptimizer> */
    private array $optimizers;

    public function __construct(
        GhostscriptPdfOptimizer $ghostscript,
        PhpPdfOptimizer $php,
        private readonly PdfThreatScanner $scanner,
    ) {
        $this->optimizers = [$ghostscript, $php];
    }

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('pdf');
    }

    public function process(DetectedFile $file, string $contents): string
    {
        if (str_contains($contents, '/ByteRange')) {
            return $contents;
        }

        $best = $contents;

        foreach ($this->optimizers as $optimizer) {
            $candidate = $optimizer->optimize($contents);

            if ($candidate !== null && strlen($candidate) < strlen($best) * (1 - self::MIN_GAIN) && $this->isAcceptable($file, $candidate)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isAcceptable(DetectedFile $file, string $candidate): bool
    {
        if (!str_starts_with($candidate, '%PDF-')) {
            return false;
        }

        try {
            $this->scanner->assertSafe($file, $candidate);
        } catch (FileRejectedException) {
            return false;
        }

        return true;
    }
}
