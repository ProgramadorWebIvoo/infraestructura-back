<?php

namespace App\Services\FileIngestion;

use App\Exceptions\FileRejectedException;

/**
 * Determina qué es REALMENTE un archivo mirando sus bytes y lo cruza con la
 * extensión declarada. Es la única fuente de mime/extensión canónicos del
 * pipeline: lo que se guarda y se sirve nunca depende de lo que dijo el
 * cliente (finfo + cabecera mágica, no el Content-Type del multipart).
 */
class FileTypeDetector
{
    /** extensión declarada => [kind, mime canónico, extensión canónica] */
    private const KINDS = [
        'png'  => ['png', 'image/png', 'png'],
        'jpg'  => ['jpeg', 'image/jpeg', 'jpg'],
        'jpeg' => ['jpeg', 'image/jpeg', 'jpg'],
        'webp' => ['webp', 'image/webp', 'webp'],
        'tif'  => ['tiff', 'image/tiff', 'tiff'],
        'tiff' => ['tiff', 'image/tiff', 'tiff'],
        'svg'  => ['svg', 'image/svg+xml', 'svg'],
        'pdf'  => ['pdf', 'application/pdf', 'pdf'],
        'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
        'ods'  => ['ods', 'application/vnd.oasis.opendocument.spreadsheet', 'ods'],
        'xls'  => ['xls', 'application/vnd.ms-excel', 'xls'],
        'csv'  => ['csv', 'text/csv', 'csv'],
        'txt'  => ['txt', 'text/plain', 'txt'],
        'dwg'  => ['dwg', 'application/acad', 'dwg'],
        'dxf'  => ['dxf', 'application/dxf', 'dxf'],
    ];

    /** kind declarado => firmas físicas aceptables */
    private const SNIFF_ACCEPTS = [
        'xlsx' => ['zip'],
        'ods'  => ['zip'],
        'xls'  => ['ole'],
        'csv'  => ['text'],
        'txt'  => ['text'],
        'dxf'  => ['text', 'dxfbin'],
    ];

    /**
     * @throws FileRejectedException si la extensión no es un tipo soportado o el contenido no la respalda
     */
    public function detect(string $path, string $originalName, string $declaredExtension): DetectedFile
    {
        $declaredExtension = strtolower($declaredExtension);

        if (!isset(self::KINDS[$declaredExtension])) {
            throw new FileRejectedException("El archivo «{$originalName}» tiene un tipo no permitido.");
        }

        [$kind, $mime, $extension] = self::KINDS[$declaredExtension];
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $sniffed = $this->sniff($head);
        $accepted = self::SNIFF_ACCEPTS[$kind] ?? [$kind];

        if (!in_array($sniffed, $accepted, true)) {
            throw new FileRejectedException("El contenido de «{$originalName}» no coincide con su extensión declarada.");
        }

        return new DetectedFile($kind, $mime, $extension, $path, $originalName);
    }

    private function sniff(string $head): string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($head, "II*\0"), str_starts_with($head, "MM\0*") => 'tiff',
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "PK\x03\x04") => 'zip',
            str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => 'ole',
            preg_match('/^AC10\d\d/', $head) === 1 => 'dwg',
            str_starts_with($head, 'AutoCAD Binary DXF') => 'dxfbin',
            $this->looksLikeSvg($head) => 'svg',
            $this->looksLikeText($head) => 'text',
            default => 'unknown',
        };
    }

    private function looksLikeSvg(string $head): bool
    {
        $trimmed = ltrim($this->stripBom($head));

        return (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<svg') || str_starts_with($trimmed, '<!'))
            && stripos($head, '<svg') !== false;
    }

    /** Texto plano: sin bytes NUL (salvo UTF-16 con BOM, que los trae por diseño). */
    private function looksLikeText(string $head): bool
    {
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            return true;
        }

        return !str_contains($head, "\0");
    }

    private function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
