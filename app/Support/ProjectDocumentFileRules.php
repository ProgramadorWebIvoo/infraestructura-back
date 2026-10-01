<?php

namespace App\Support;

use App\Services\SettingsService;
use Closure;

/**
 * Fuente única de qué mimes/extensiones admite cada tipo de documento de un
 * proyecto. La comparten el endpoint de documentos y los endpoints de
 * proceso que adjuntan archivos en la misma petición (crear, reenviar,
 * rechazar, reevaluar) para que ambos validen exactamente igual.
 */
class ProjectDocumentFileRules
{
    public const TYPES = ['CALC', 'PLANO', 'FOTO', 'CORRECCION', 'REEVALUACION', 'COMPROBANTE_ANTICIPO', 'COMPROBANTE_FINIQUITO'];

    private const CALC_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/vnd.ms-excel',                                           // xls
        'text/csv',
        'text/plain',
        'application/pdf',
        'application/vnd.oasis.opendocument.spreadsheet',                    // ods
    ];
    private const CALC_EXTENSIONS = ['xlsx', 'xls', 'csv', 'pdf', 'ods'];

    private const PLANO_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/svg+xml',
        'image/tiff',
        'application/acad',           // dwg (generic)
        'application/octet-stream',   // dwg/dxf often sent as binary
    ];
    private const PLANO_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'svg', 'tiff', 'tif', 'dwg', 'dxf'];

    private const FOTO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];
    private const FOTO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * CORRECCION (adjuntos de Auditoría al rechazar) y REEVALUACION (evidencia
     * de Procura): aceptan la unión de tipos de PLANO y CALC, ya que puede ser
     * cualquier documento técnico que sustente el motivo.
     */
    private const SUPPORTING_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/svg+xml',
        'image/tiff',
        'application/acad',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/csv',
        'text/plain',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];
    private const SUPPORTING_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'svg', 'tiff', 'tif', 'dwg', 'dxf', 'xlsx', 'xls', 'csv', 'ods'];

    /** Comprobante bancario: foto/escaneo del voucher o PDF, nunca hojas de cálculo o planos. */
    private const COMPROBANTE_MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
    private const COMPROBANTE_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    public static function maxFileMb(): int
    {
        return (int) SettingsService::get('documento_tamano_maximo_mb', 25);
    }

    public static function maxFileCount(): int
    {
        return (int) SettingsService::get('documento_cantidad_maxima_archivos', 10);
    }

    /** Reglas de un archivo individual de un documento del tipo dado. */
    public static function fileRules(?string $type): array
    {
        return ['required', 'file', 'max:' . (self::maxFileMb() * 1024), self::mimeAndExtensionRule($type)];
    }

    public static function messages(string $filesKey = 'files'): array
    {
        return [
            "{$filesKey}.*.max" => 'Cada archivo debe pesar máximo ' . self::maxFileMb() . ' MB.',
            "{$filesKey}.max" => 'Puede adjuntar como máximo ' . self::maxFileCount() . ' archivos por carga.',
        ];
    }

    /**
     * Valida que cada archivo cumpla:
     * 1. MIME detectado en el servidor (finfo) contra la lista del tipo
     * 2. Extensión contra la lista del tipo
     *
     * application/octet-stream solo se acepta para .dwg/.dxf (PLANO).
     */
    private static function mimeAndExtensionRule(?string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            $mime = $value->getMimeType(); // server-side detection via finfo
            $ext = strtolower($value->getClientOriginalExtension());
            $name = $value->getClientOriginalName();

            [$allowedMimes, $allowedExts] = match ($type) {
                'CALC' => [self::CALC_MIMES, self::CALC_EXTENSIONS],
                'PLANO' => [self::PLANO_MIMES, self::PLANO_EXTENSIONS],
                'FOTO' => [self::FOTO_MIMES, self::FOTO_EXTENSIONS],
                'CORRECCION', 'REEVALUACION' => [self::SUPPORTING_MIMES, self::SUPPORTING_EXTENSIONS],
                'COMPROBANTE_ANTICIPO', 'COMPROBANTE_FINIQUITO' => [self::COMPROBANTE_MIMES, self::COMPROBANTE_EXTENSIONS],
                default => [[], []],
            };

            if (!in_array($ext, $allowedExts)) {
                $fail("La extensión «.{$ext}» del archivo «{$name}» no está permitida para documentos tipo {$type}.");
                return;
            }

            if ($mime === 'application/octet-stream') {
                if (!in_array($ext, ['dwg', 'dxf'])) {
                    $fail("El archivo «{$name}» tiene un tipo de contenido no válido.");
                }
                return;
            }

            // finfo on some systems detects ZIP-based formats (xlsx, ods) as application/zip
            if ($mime === 'application/zip') {
                if (!in_array($ext, ['xlsx', 'ods'])) {
                    $fail("El archivo «{$name}» tiene un tipo de contenido no válido (ZIP no esperado).");
                }
                return;
            }

            if (!in_array($mime, $allowedMimes)) {
                $fail("El archivo «{$name}» tiene un tipo de contenido no válido para documentos tipo {$type}.");
            }
        };
    }
}
