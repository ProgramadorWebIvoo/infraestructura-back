<?php

namespace App\Support;

/**
 * Vocabulario único de carpetas de almacenamiento. La raíz de cada proyecto
 * es `{titulo}_{fecha}_{codigo}` (ver StorageFolderService) y dentro cuelga
 * una carpeta por tipo de archivo; lo que no pertenece a un proyecto tiene
 * su propia raíz de primer nivel.
 */
final class StoragePaths
{
    public const CLOSURE = 'cierre_de_obra';
    public const SUPPLIER_ATTACHMENTS = 'adjuntos_proveedor';
    public const CONTRACTORS_ROOT = 'contratistas';
    public const MARKETING_ROOT = 'marketing';

    /** Tipo de ProjectDocument → carpeta dentro de la raíz del proyecto. */
    private const DOCUMENT_FOLDERS = [
        'CALC' => 'calculos',
        'PLANO' => 'planos',
        'FOTO' => 'fotos_del_sitio',
        'CORRECCION' => 'rechazos',
        'REEVALUACION' => 'reevaluaciones',
        'COMPROBANTE_ANTICIPO' => 'comprobantes',
        'COMPROBANTE_FINIQUITO' => 'comprobantes',
    ];

    /**
     * Disco donde viven TODOS los archivos: el configurado como default
     * (`local` o `s3`, ver SystemKeyConfigService). Se resuelve en cada
     * llamada porque el ajuste de almacenamiento se aplica en caliente.
     */
    public static function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    public static function documentFolder(string $documentType): string
    {
        return self::DOCUMENT_FOLDERS[$documentType] ?? 'otros';
    }
}
