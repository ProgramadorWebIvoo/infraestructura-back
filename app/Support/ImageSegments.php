<?php

namespace App\Support;

/**
 * Lectura estructural de PNG/JPEG/WEBP sin decodificar píxeles. Sirve para
 * dos cosas: (1) entregar al escáner solo lo que puede esconder código
 * (metadatos y bytes tras el final de la imagen) y (2) quitar esos metadatos
 * SIN recomprimir, de modo que la calidad queda bit a bit idéntica.
 */
final class ImageSegments
{
    /** Chunks PNG que afectan cómo se ve la imagen: se conservan. El resto (texto, EXIF, tIME, privados) se descarta. */
    private const PNG_KEEP = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'acTL', 'fcTL', 'fdAT'];

    /**
     * @return array{0: array<int, string>, 1: string} [segmentos de metadatos, bytes sobrantes tras el final]
     */
    public static function inspectable(string $kind, string $contents): array
    {
        return match ($kind) {
            'jpeg' => self::jpegInspectable($contents),
            'png' => self::pngInspectable($contents),
            'webp' => self::webpInspectable($contents),
            default => [[], ''],
        };
    }

    /**
     * Misma imagen sin metadatos ni bytes sobrantes, o null si la estructura
     * no es parseable (el llamador debe entonces recodificar).
     */
    public static function stripLossless(string $kind, string $contents): ?string
    {
        return match ($kind) {
            'jpeg' => self::jpegStrip($contents),
            'png' => self::pngStrip($contents),
            'webp' => self::webpStrip($contents),
            default => null,
        };
    }

    // ── JPEG ────────────────────────────────────────────────────────────

    /** @return array{0: array<int, array{0: int, 1: int, 2: int}>, 1: int} segmentos [marcador, inicio, fin] y offset tras EOI */
    private static function jpegLayout(string $c): array
    {
        $n = strlen($c);
        $segments = [];
        $i = 2;

        while ($i < $n - 1) {
            $next = strpos($c, "\xFF", $i);
            if ($next === false || $next >= $n - 1) {
                break;
            }
            $i = $next;
            $marker = ord($c[$i + 1]);

            if ($marker === 0xFF) {
                $i++;
            } elseif ($marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $i += 2;
            } elseif ($marker === 0xD9) {
                return [$segments, $i + 2];
            } else {
                if ($i + 3 >= $n) {
                    break;
                }
                $length = (ord($c[$i + 2]) << 8) | ord($c[$i + 3]);
                $segments[] = [$marker, $i, min($n, $i + 2 + $length)];
                $i += 2 + max($length, 2);
            }
        }

        return [$segments, $n];
    }

    private static function jpegInspectable(string $c): array
    {
        [$segments, $end] = self::jpegLayout($c);
        $metadata = [];

        foreach ($segments as [$marker, $start, $stop]) {
            if (($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE) {
                $metadata[] = substr($c, $start + 4, $stop - $start - 4);
            }
        }

        return [$metadata, (string) substr($c, $end)];
    }

    private static function jpegStrip(string $c): ?string
    {
        [$segments, $end] = self::jpegLayout($c);
        $firstScan = null;
        $out = "\xFF\xD8";

        foreach ($segments as [$marker, $start, $stop]) {
            if ($marker === 0xDA) {
                $firstScan = $start;
                break;
            }
            if (self::jpegKeepSegment($marker, substr($c, $start + 4, 12))) {
                $out .= substr($c, $start, $stop - $start);
            }
        }

        if ($firstScan === null) {
            return null;
        }

        return $out . substr($c, $firstScan, $end - $firstScan);
    }

    /** JFIF (APP0), Adobe (APP14, define el espacio de color) e ICC (APP2) se conservan. */
    private static function jpegKeepSegment(int $marker, string $head): bool
    {
        if ($marker >= 0xE1 && $marker <= 0xEF) {
            return $marker === 0xEE || ($marker === 0xE2 && str_starts_with($head, 'ICC_PROFILE'));
        }

        return $marker !== 0xFE;
    }

    // ── PNG ─────────────────────────────────────────────────────────────

    /** @return array<int, array{0: string, 1: int, 2: int}>|null chunks [tipo, inicio, fin] o null si la estructura es inválida */
    private static function pngChunks(string $c): ?array
    {
        $n = strlen($c);
        $chunks = [];
        $i = 8;

        while ($i + 12 <= $n) {
            $length = unpack('N', substr($c, $i, 4))[1];
            $type = substr($c, $i + 4, 4);
            $end = $i + 12 + $length;
            if ($end > $n) {
                return null;
            }
            $chunks[] = [$type, $i, $end];
            $i = $end;
            if ($type === 'IEND') {
                return $chunks;
            }
        }

        return null;
    }

    private static function pngInspectable(string $c): array
    {
        $chunks = self::pngChunks($c);
        if ($chunks === null) {
            return [[$c], ''];
        }

        $metadata = [];
        foreach ($chunks as [$type, $start, $end]) {
            if (!in_array($type, self::PNG_KEEP, true)) {
                $metadata[] = substr($c, $start + 8, $end - $start - 12);
            }
        }

        return [$metadata, (string) substr($c, end($chunks)[2])];
    }

    private static function pngStrip(string $c): ?string
    {
        $chunks = self::pngChunks($c);
        if ($chunks === null) {
            return null;
        }

        $out = substr($c, 0, 8);
        foreach ($chunks as [$type, $start, $end]) {
            if (in_array($type, self::PNG_KEEP, true)) {
                $out .= substr($c, $start, $end - $start);
            }
        }

        return $out;
    }

    // ── WEBP ────────────────────────────────────────────────────────────

    /** @return array<int, array{0: string, 1: int, 2: int}> chunks [tipo, inicio, fin] y el fin del contenedor RIFF */
    private static function webpChunks(string $c): array
    {
        $n = strlen($c);
        $riffEnd = min($n, 8 + unpack('V', substr($c, 4, 4))[1]);
        $chunks = [];
        $i = 12;

        while ($i + 8 <= $riffEnd) {
            $size = unpack('V', substr($c, $i + 4, 4))[1];
            $end = min($riffEnd, $i + 8 + $size + ($size & 1));
            $chunks[] = [substr($c, $i, 4), $i, $end];
            $i = $end;
        }

        return [$chunks, $riffEnd];
    }

    private static function webpInspectable(string $c): array
    {
        [$chunks, $riffEnd] = self::webpChunks($c);
        $metadata = [];

        foreach ($chunks as [$type, $start, $end]) {
            if ($type === 'EXIF' || $type === 'XMP ') {
                $metadata[] = substr($c, $start + 8, $end - $start - 8);
            }
        }

        return [$metadata, (string) substr($c, $riffEnd)];
    }

    private static function webpStrip(string $c): ?string
    {
        [$chunks] = self::webpChunks($c);
        $body = '';

        foreach ($chunks as [$type, $start, $end]) {
            if ($type === 'EXIF' || $type === 'XMP ') {
                continue;
            }
            $chunk = substr($c, $start, $end - $start);
            if ($type === 'VP8X') {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // apaga las banderas de EXIF y XMP
            }
            $body .= $chunk;
        }

        return $body === '' ? null : 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }
}
