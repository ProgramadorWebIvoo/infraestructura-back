<?php

namespace App\Services\FileIngestion\Processors;

use App\Services\FileIngestion\DetectedFile;
use App\Services\ImageOptimizerService;
use ZipArchive;

/**
 * XLSX/ODS son ZIP: se reempaquetan con compresión máxima, se descartan
 * basura de sistema (__MACOSX, .DS_Store) y se optimizan las imágenes
 * incrustadas. Se conserva el orden de las entradas y, en ODS, el
 * `mimetype` sin comprimir como exige el formato. Solo se entrega el
 * resultado si pesa menos que el original.
 */
class SpreadsheetProcessor implements FileProcessor
{
    private const MIN_GAIN = 0.03;
    private const JUNK = '#(^|/)(__MACOSX/|\.DS_Store$|Thumbs\.db$)#i';
    private const IMAGE_ENTRY = '#^(xl/media/|Pictures/).+\.(png|jpe?g|webp)$#i';

    public function __construct(private readonly ImageOptimizerService $images)
    {
    }

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('xlsx', 'ods');
    }

    public function process(DetectedFile $file, string $contents): string
    {
        $source = new ZipArchive();
        $target = tempnam(sys_get_temp_dir(), 'ivoo-zip');
        $repacked = new ZipArchive();

        try {
            if ($source->open($file->path, ZipArchive::RDONLY) !== true || $repacked->open($target, ZipArchive::OVERWRITE) !== true) {
                return $contents;
            }

            $this->copyEntries($source, $repacked);
            $repacked->close();
            $result = (string) file_get_contents($target);

            return $result !== '' && strlen($result) <= strlen($contents) * (1 - self::MIN_GAIN) ? $result : $contents;
        } finally {
            $source->close();
            @unlink($target);
        }
    }

    private function copyEntries(ZipArchive $source, ZipArchive $target): void
    {
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = (string) $source->getNameIndex($i);

            if (str_ends_with($name, '/') || preg_match(self::JUNK, $name) === 1) {
                continue;
            }

            $data = (string) $source->getFromIndex($i);

            if (preg_match(self::IMAGE_ENTRY, $name) === 1) {
                $data = $this->optimizeImage($name, $data);
            }

            $target->addFromString($name, $data);
            $target->setCompressionName($name, $name === 'mimetype' ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, 9);
        }
    }

    private function optimizeImage(string $name, string $data): string
    {
        $kind = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpeg',
        };

        try {
            return $this->images->optimize($data, $kind);
        } catch (\Throwable) {
            return $data;
        }
    }
}
