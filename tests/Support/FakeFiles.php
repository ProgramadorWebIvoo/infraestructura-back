<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Archivos de prueba con CONTENIDO real del tipo que declaran. El pipeline de
 * subida verifica el tipo por los bytes (no por el Content-Type del cliente),
 * así que un `UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')`,
 * que es solo ceros, ya no es un PDF válido. `$kb` es el peso que se reporta
 * al validador de tamaño; el contenido real es mínimo.
 */
class FakeFiles
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    public static function pdf(string $name = 'documento.pdf', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::MINIMAL_PDF)->size($kb);
    }

    public static function jpeg(string $name = 'foto.jpg', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40)->size($kb);
    }

    public static function png(string $name = 'imagen.png', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40)->size($kb);
    }

    /** DWG: solo se valida la firma de versión `AC10xx` del encabezado. */
    public static function dwg(string $name = 'plano.dwg', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'AC1015' . str_repeat("\0", 64))->size($kb);
    }
}
