<?php

namespace App\Support;

use App\Services\SettingsService;

/**
 * Límites reales de subida: los del servidor PHP (php.ini) combinados con los
 * ajustes de la app. El frontend los consulta (`GET /api/public/upload-limits`)
 * para avisar ANTES de enviar en vez de descubrir el 413 al terminar la subida.
 */
final class UploadLimits
{
    /** Convierte la notación abreviada de php.ini ("8M", "2G", "512K") a bytes; 0 o negativo = sin límite. */
    public static function parseShorthand(string $value): int
    {
        if (!preg_match('/^\s*(-?\d+)\s*([kmg])?/i', $value, $match) || (int) $match[1] <= 0) {
            return PHP_INT_MAX;
        }

        return (int) $match[1] * match (strtolower($match[2] ?? '')) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    public static function postMaxBytes(): int
    {
        return self::parseShorthand((string) ini_get('post_max_size'));
    }

    public static function uploadMaxBytes(): int
    {
        return self::parseShorthand((string) ini_get('upload_max_filesize'));
    }

    public static function maxFileUploads(): int
    {
        return max(1, (int) ini_get('max_file_uploads'));
    }

    /** Peso máximo por archivo: el ajuste de la app, acotado por lo que el servidor físicamente admite. */
    public static function maxFileBytes(): int
    {
        return min(ProjectDocumentFileRules::maxFileMb() * 1024 * 1024, self::uploadMaxBytes(), self::postMaxBytes());
    }

    /** Cantidad máxima de archivos por carga: el ajuste de la app, acotado por `max_file_uploads`. */
    public static function maxFileCount(): int
    {
        return min(ProjectDocumentFileRules::maxFileCount(), self::maxFileUploads());
    }

    /** "40 MB", "512 KB" — para mensajes al usuario final. */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= PHP_INT_MAX) {
            return 'sin límite';
        }
        if ($bytes >= 1024 ** 2) {
            return rtrim(rtrim(number_format($bytes / 1024 ** 2, 1, '.', ''), '0'), '.') . ' MB';
        }

        return max(1, (int) ceil($bytes / 1024)) . ' KB';
    }

    /** @return array{postMaxBytes: int, uploadMaxBytes: int, maxFileUploads: int, maxFileBytes: int, maxFileCount: int} */
    public static function toArray(): array
    {
        // PHP_INT_MAX (sin límite) viaja como null para no desbordar el JSON del navegador.
        $nullable = fn (int $bytes): ?int => $bytes >= PHP_INT_MAX ? null : $bytes;

        return [
            'postMaxBytes' => $nullable(self::postMaxBytes()),
            'uploadMaxBytes' => $nullable(self::uploadMaxBytes()),
            'maxFileUploads' => self::maxFileUploads(),
            'maxFileBytes' => self::maxFileBytes(),
            'maxFileCount' => self::maxFileCount(),
        ];
    }
}
