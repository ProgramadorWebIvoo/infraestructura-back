<?php

namespace App\Support;

use App\Exceptions\FileRejectedException;

/**
 * Separa un PDF en las partes que pueden contener acciones activas y deja
 * fuera los datos binarios:
 *
 * - La "estructura": el archivo sin el cuerpo de sus streams. Los streams
 *   (imágenes, fuentes, contenido de página) son datos comprimidos
 *   pseudoaleatorios; buscar nombres cortos como `/JS` en ellos produciría
 *   falsos positivos en PDFs grandes.
 * - Los object streams (`/Type /ObjStm`) descomprimidos: los lectores
 *   interpretan los objetos que hay dentro, así que son una forma clásica de
 *   esconder `/JavaScript` o `/Launch` de una búsqueda ingenua.
 *
 * Solo se revierten filtros seguros (Flate, ASCIIHex, ASCII85). Un object
 * stream con otro filtro no se puede verificar y se rechaza.
 */
final class PdfStreams
{
    private const MAX_DECODED_BYTES = 64 * 1024 * 1024;

    /**
     * @return array<int, string> [estructura, ...object streams descomprimidos]
     * @throws FileRejectedException
     */
    public static function inspectable(string $pdf): array
    {
        $parts = [];
        $structure = '';
        $cursor = 0;
        $offset = 0;
        $budget = self::MAX_DECODED_BYTES;

        while (($keyword = strpos($pdf, 'stream', $offset)) !== false) {
            $offset = $keyword + 6;

            if ($keyword >= 3 && substr($pdf, $keyword - 3, 3) === 'end') {
                continue;
            }

            $dataStart = $offset;
            if (substr($pdf, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (($pdf[$dataStart] ?? '') === "\n" || ($pdf[$dataStart] ?? '') === "\r") {
                $dataStart += 1;
            }

            $dataEnd = strpos($pdf, 'endstream', $dataStart);
            if ($dataEnd === false) {
                break;
            }

            $structure .= substr($pdf, $cursor, $dataStart - $cursor);
            $cursor = $dataEnd;
            $offset = $dataEnd + 9;

            $dictionary = self::normalizeNames(self::dictionaryBefore($pdf, $keyword));
            if (preg_match('/\/Type\s*\/ObjStm(?![A-Za-z0-9])/', $dictionary) === 1) {
                $data = self::decode(substr($pdf, $dataStart, $dataEnd - $dataStart), self::filters($dictionary), $budget);
                $budget -= strlen($data);
                $parts[] = $data;
            }
        }

        $structure .= substr($pdf, $cursor);

        return [$structure, ...$parts];
    }

    /** Resuelve los escapes `#xx` de los nombres PDF (`/J#61vaScript` → `/JavaScript`). */
    public static function normalizeNames(string $text): string
    {
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m) => chr(hexdec($m[1])), $text) ?? $text;
    }

    private static function dictionaryBefore(string $pdf, int $streamKeyword): string
    {
        $from = max(0, $streamKeyword - 4096);
        $chunk = substr($pdf, $from, $streamKeyword - $from);
        $objPos = strrpos($chunk, ' obj');

        return $objPos === false ? $chunk : substr($chunk, $objPos);
    }

    /** @return array<int, string> filtros en orden de aplicación */
    private static function filters(string $dictionary): array
    {
        if (preg_match('/\/Filter\s*(\[[^\]]*\]|\/[A-Za-z0-9]+)/', $dictionary, $m) !== 1) {
            return [];
        }

        preg_match_all('/\/([A-Za-z0-9]+)/', $m[1], $names);

        return $names[1];
    }

    private static function decode(string $data, array $filters, int $budget): string
    {
        foreach ($filters as $filter) {
            $data = match ($filter) {
                'FlateDecode', 'Fl' => (string) @gzuncompress($data, max(1, $budget)),
                'ASCIIHexDecode', 'AHx' => (string) @hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', rtrim($data, '>')) ?? ''),
                'ASCII85Decode', 'A85' => self::ascii85($data),
                default => throw new FileRejectedException('El PDF usa una codificación que no se puede verificar y no está permitido.'),
            };
        }

        return $data;
    }

    private static function ascii85(string $data): string
    {
        $data = preg_replace('/\s+/', '', $data) ?? '';
        $data = preg_replace('/^<~|~>.*$/s', '', $data) ?? '';
        $data = str_replace('z', '!!!!!', $data);
        $out = '';

        foreach (str_split($data, 5) as $group) {
            $padding = 5 - strlen($group);
            $value = 0;
            foreach (str_split(str_pad($group, 5, 'u')) as $char) {
                $value = $value * 85 + (ord($char) - 33);
            }
            $out .= substr(pack('N', $value & 0xFFFFFFFF), 0, 4 - $padding);
        }

        return $out;
    }
}
