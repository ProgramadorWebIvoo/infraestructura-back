<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Única forma de servir un archivo guardado (descarga o vista previa): siempre
 * en streaming (nunca cargado entero en memoria), con Content-Length, nombre
 * saneado en Content-Disposition, `nosniff` y 404 con mensaje claro si el
 * archivo ya no está en el disco. Evita que cada controlador arme su propia
 * respuesta con cabeceras distintas.
 */
final class StoredFileResponse
{
    public const MISSING_MESSAGE = 'El archivo ya no existe en el servidor.';

    /** Fuerza la descarga (Content-Disposition: attachment). */
    public static function attachment(string $path, string $name, ?string $mime = null): StreamedResponse
    {
        return self::disk()->download($path, $name, self::headers($path, $mime, false));
    }

    /** Sirve el archivo sin forzar descarga — para el previsualizador. */
    public static function inline(string $path, string $name, ?string $mime = null): StreamedResponse
    {
        return self::disk()->response($path, $name, self::headers($path, $mime, true), 'inline');
    }

    /** ¿El archivo sigue en el disco? Los controladores abortan con 404 antes de servirlo. */
    public static function assertExists(string $path): void
    {
        abort_unless(self::disk()->exists($path), 404, self::MISSING_MESSAGE);
    }

    private static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk(StoragePaths::disk());
    }

    /** @return array<string, string> */
    private static function headers(string $path, ?string $mime, bool $inline): array
    {
        self::assertExists($path);

        $headers = [
            'Content-Type' => $mime ?: (self::disk()->mimeType($path) ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        // Una vista previa nunca debe poder ejecutar scripts (SVG/HTML subido por error).
        if ($inline) {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox";
        }

        return $headers;
    }
}
