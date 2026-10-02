<?php

namespace App\Services\FileIngestion\Processors;

use App\Services\FileIngestion\DetectedFile;

/**
 * CSV/TXT: normaliza a UTF-8 (desde UTF-16 o Windows-1252), quita bytes NUL y
 * unifica los saltos de línea a "\n". Un CSV que no era UTF-8 se guarda con
 * BOM para que Excel lo abra con los acentos bien.
 */
class TextProcessor implements FileProcessor
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('csv', 'txt');
    }

    public function process(DetectedFile $file, string $contents): string
    {
        $hadBom = str_starts_with($contents, self::UTF8_BOM);
        $converted = false;

        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'UTF-16');
            $converted = true;
        } elseif (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
            $converted = true;
        }

        $contents = str_replace("\0", '', $contents);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        return ($hadBom || ($converted && $file->isKind('csv')) ? self::UTF8_BOM : '') . $contents;
    }
}
