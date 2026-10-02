<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;
use App\Services\FileIngestion\FileTypeDetector;
use App\Services\FileIngestion\Scanners\FileThreatScanner;
use Illuminate\Http\UploadedFile;

/**
 * Pared de seguridad para CUALQUIER archivo que entra al sistema,
 * independiente del contexto. La validación de qué tipos están PERMITIDOS por
 * documento sigue en los FormRequest; esta clase no sabe de negocio, solo
 * garantiza que el archivo es lo que dice ser y no trae contenido activo:
 *
 * 1. Extensión no ejecutable y sin doble extensión (`factura.pdf.php`).
 * 2. Tipo REAL por contenido (FileTypeDetector), no el Content-Type del
 *    cliente: debe coincidir con la extensión declarada.
 * 3. Todos los inspectores que soporten ese tipo (firmas ejecutables, código
 *    en metadatos de imágenes, JavaScript en PDF, macros en hojas de cálculo,
 *    scripts en SVG, fórmulas DDE en CSV...). Ver Scanners/.
 *
 * Devuelve el tipo detectado para que el pipeline guarde mime y extensión
 * canónicos en vez de los declarados.
 */
class FileSecurityScanner
{
    /** Extensiones ejecutables/script — nunca permitidas, aunque un FormRequest las admitiera. */
    private const DENYLISTED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'ps1', 'com', 'msi',
        'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh',
        'jar', 'dll', 'so',
        'htaccess', 'htpasswd', 'ini', 'config',
    ];

    /** @param iterable<FileThreatScanner> $scanners */
    public function __construct(
        private readonly FileTypeDetector $detector,
        private readonly iterable $scanners,
    ) {
    }

    /**
     * @throws FileRejectedException
     */
    public function scan(UploadedFile $file): DetectedFile
    {
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name, strtolower($file->getClientOriginalExtension()));

        $path = $file->getRealPath();
        $contents = $path ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new FileRejectedException("No se pudo leer el archivo «{$name}».");
        }

        $detected = $this->detector->detect($path, $name, $file->getClientOriginalExtension());

        foreach ($this->scanners as $scanner) {
            if ($scanner->supports($detected)) {
                $scanner->assertSafe($detected, $contents);
            }
        }

        return $detected;
    }

    /**
     * Rechaza extensiones ejecutables y nombres tipo "factura.pdf.php": una
     * extensión "inocente" seguida de una peligrosa, técnica clásica para
     * burlar validadores que solo miran la última extensión.
     */
    private function assertSafeName(string $name, string $extension): void
    {
        $parts = explode('.', strtolower($name));
        $denied = array_filter(
            [$extension, ...array_slice($parts, 1, -1)],
            fn (string $part) => in_array($part, self::DENYLISTED_EXTENSIONS, true),
        );

        if ($denied !== []) {
            throw new FileRejectedException("El archivo «{$name}» tiene un tipo o nombre no permitido por seguridad.");
        }
    }
}
