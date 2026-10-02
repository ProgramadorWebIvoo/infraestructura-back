<?php

namespace App\Services\FileIngestion\Processors;

use App\Support\PdfStreams;

/**
 * Optimizador PDF sin dependencias externas. Reescribe el archivo completo
 * (objetos + xref nueva) comprimiendo con Flate los streams que venían sin
 * comprimir y descartando el diccionario /Info del trailer (autor, programa,
 * fechas). Es sin pérdida: no toca imágenes ni fuentes.
 *
 * Solo soporta PDF clásicos (tabla xref, sin object streams, cifrado ni firma
 * digital); ante cualquier otra cosa o duda devuelve null y se guarda el
 * original. La ganancia real depende de que el PDF traiga streams sin
 * comprimir (exportaciones CAD, generadores antiguos); para recomprimir
 * imágenes dentro del PDF hace falta Ghostscript (GhostscriptPdfOptimizer).
 */
class PhpPdfOptimizer implements PdfOptimizer
{
    private const MIN_STREAM_BYTES = 512;
    private const UNSUPPORTED = '#/(ObjStm|XRef|Encrypt|ByteRange|Linearized)(?![A-Za-z0-9])#';

    public function optimize(string $pdf): ?string
    {
        $objects = $this->parseObjects($pdf);
        $trailer = $this->trailerEntries($pdf);

        if ($objects === null || !isset($trailer['Root'])) {
            return null;
        }

        $out = substr($pdf, 0, 8) . "\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $recompressed = false;

        $infoNumber = isset($trailer['Info']) ? (int) $trailer['Info'] : null;

        foreach ($objects as $number => [$generation, $dictionary, $stream, $body]) {
            if ($number === $infoNumber) {
                continue; // metadatos del documento (autor, programa, fechas): no se copian
            }

            if ($stream !== null) {
                [$dictionary, $stream, $changed] = $this->recompress($dictionary, $stream);
                $recompressed = $recompressed || $changed;
                $body = $dictionary . "\nstream\n" . $stream . "\nendstream";
            }
            $offsets[$number] = [strlen($out), $generation];
            $out .= "{$number} {$generation} obj\n{$body}\nendobj\n";
        }

        return $recompressed ? $out . $this->xref($offsets, $trailer, strlen($out)) : null;
    }

    /** @return array<int, array{0: int, 1: string, 2: ?string, 3: string}>|null número => [generación, diccionario, stream, cuerpo] */
    private function parseObjects(string $pdf): ?array
    {
        if (!str_starts_with($pdf, '%PDF-') || preg_match(self::UNSUPPORTED, implode('', array_slice(PdfStreams::inspectable($pdf), 0, 1))) === 1) {
            return null;
        }

        $objects = [];
        $pos = 0;

        while (preg_match('/(\d+)\s+(\d+)\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $bodyStart = $m[0][1] + strlen($m[0][0]);
            $end = strpos($pdf, 'endobj', $bodyStart);
            $keyword = strpos($pdf, 'stream', $bodyStart);
            $stream = null;
            $dictionary = '';

            if ($keyword !== false && ($end === false || $keyword < $end)) {
                $dictionary = trim(substr($pdf, $bodyStart, $keyword - $bodyStart));
                $dataStart = $keyword + 6 + (substr($pdf, $keyword + 6, 2) === "\r\n" ? 2 : 1);
                $dataEnd = strpos($pdf, 'endstream', $dataStart);
                if ($dataEnd === false) {
                    return null;
                }
                $stream = $this->withoutTrailingEol(substr($pdf, $dataStart, $dataEnd - $dataStart));
                $end = strpos($pdf, 'endobj', $dataEnd);
            }

            if ($end === false) {
                return null;
            }

            $objects[(int) $m[1][0]] = [(int) $m[2][0], $dictionary, $stream, trim(substr($pdf, $bodyStart, $end - $bodyStart))];
            $pos = $end + 6;
        }

        return $objects === [] ? null : $objects;
    }

    /** El EOL previo a `endstream` no es parte de los datos; el resto de saltos de línea sí. */
    private function withoutTrailingEol(string $data): string
    {
        if (str_ends_with($data, "
")) {
            return substr($data, 0, -2);
        }

        return str_ends_with($data, "
") || str_ends_with($data, "") ? substr($data, 0, -1) : $data;
    }

    /** @return array{0: string, 1: string, 2: bool} [diccionario, stream, ¿cambió?] */
    private function recompress(string $dictionary, string $stream): array
    {
        if (strlen($stream) < self::MIN_STREAM_BYTES || preg_match('#/(Filter|DecodeParms|Metadata)(?![A-Za-z0-9])#', $dictionary) === 1) {
            return [$dictionary, $stream, false];
        }

        $compressed = gzcompress($stream, 9);
        $hasLength = preg_match('#/Length\s+\d+(\s+\d+\s+R)?#', $dictionary) === 1;
        $closing = strrpos($dictionary, '>>');

        if ($compressed === false || !$hasLength || $closing === false || strlen($compressed) >= strlen($stream) * 0.95) {
            return [$dictionary, $stream, false];
        }

        $dictionary = (string) preg_replace('#/Length\s+\d+(\s+\d+\s+R)?#', '/Length ' . strlen($compressed), $dictionary, 1);
        $closing = strrpos($dictionary, '>>');
        $dictionary = substr($dictionary, 0, $closing) . ' /Filter /FlateDecode ' . substr($dictionary, $closing);

        return [$dictionary, $compressed, true];
    }

    /** @return array<string, string> entradas /Root e /ID del último trailer clásico */
    private function trailerEntries(string $pdf): array
    {
        $pos = strrpos($pdf, 'trailer');
        if ($pos === false) {
            return [];
        }

        $trailer = substr($pdf, $pos, 2048);
        $entries = [];

        if (preg_match('#/Root\s+(\d+\s+\d+\s+R)#', $trailer, $m) === 1) {
            $entries['Root'] = $m[1];
        }
        if (preg_match('#/Info\s+(\d+)\s+\d+\s+R#', $trailer, $m) === 1) {
            $entries['Info'] = $m[1];
        }
        if (preg_match('#/ID\s*(\[[^\]]*\])#', $trailer, $m) === 1) {
            $entries['ID'] = $m[1];
        }

        return $entries;
    }

    /** @param array<int, array{0: int, 1: int}> $offsets */
    private function xref(array $offsets, array $trailer, int $xrefOffset): string
    {
        $size = max(array_keys($offsets)) + 1;
        $table = "xref\n0 {$size}\n" . sprintf("%010d %05d f \n", 0, 65535);

        for ($i = 1; $i < $size; $i++) {
            $table .= isset($offsets[$i])
                ? sprintf("%010d %05d n \n", $offsets[$i][0], $offsets[$i][1])
                : sprintf("%010d %05d f \n", 0, 65535);
        }

        $dictionary = "<< /Size {$size} /Root {$trailer['Root']}" . (isset($trailer['ID']) ? " /ID {$trailer['ID']}" : '') . ' >>';

        return $table . "trailer\n{$dictionary}\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
