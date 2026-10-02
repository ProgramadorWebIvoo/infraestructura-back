<?php

namespace App\Services\FileIngestion\Processors;

use App\Services\FileIngestion\DetectedFile;
use App\Services\SvgSanitizer;

class SvgProcessor implements FileProcessor
{
    public function __construct(private readonly SvgSanitizer $sanitizer)
    {
    }

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('svg');
    }

    public function process(DetectedFile $file, string $contents): string
    {
        return $this->sanitizer->sanitize($contents);
    }
}
