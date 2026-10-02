<?php

namespace App\Services\FileIngestion\Processors;

use App\Services\FileIngestion\DetectedFile;
use App\Services\ImageOptimizerService;

class ImageProcessor implements FileProcessor
{
    public function __construct(private readonly ImageOptimizerService $optimizer)
    {
    }

    public function supports(DetectedFile $file): bool
    {
        return $this->optimizer->isOptimizable($file->kind);
    }

    public function process(DetectedFile $file, string $contents): string
    {
        return $this->optimizer->optimize($contents, $file->kind);
    }
}
