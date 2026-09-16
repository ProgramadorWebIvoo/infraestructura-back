<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use Illuminate\Http\UploadedFile;

/**
 * Pared de seguridad genérica para CUALQUIER archivo que entra al sistema,
 * independiente del contexto (project-document, supplier-image, marketing).
 * La validación de mime/extensión PERMITIDA por tipo de documento sigue
 * viviendo en cada FormRequest (StoreProjectDocumentRequest, etc.) — este
 * scanner no sabe de negocio, solo detecta contenido malicioso o
 * inconsistente sin importar qué tipo se declaró como permitido:
 *
 * 1. El mime real (finfo, ya resuelto por UploadedFile::getMimeType()) debe
 *    ser consistente con la extensión declarada — evita ejecutables
 *    disfrazados de imagen/documento (double extension, mime spoofing).
 * 2. Polyglot: ninguna imagen puede contener una etiqueta `<?php` o
 *    `<script` embebida en sus primeros bytes (GIF/JPEG+PHP, SVG con XSS).
 * 3. SVG se sanitiza aparte (SvgSanitizer) porque es XML ejecutable en
 *    navegador, no basta con rechazar — hay que limpiar y servir seguro.
 */
class FileSecurityScanner
{
    /** Extensiones consideradas ejecutables/script — nunca permitidas, sin
     *  importar qué declare el FormRequest del contexto. */
    private const DENYLISTED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'ps1', 'com', 'msi',
        'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh',
        'jar', 'dll', 'so',
        'htaccess', 'htpasswd', 'ini', 'config',
    ];

    /** mime real => extensiones consistentes con ese mime. Si el mime
     *  detectado no está acá, no se valida cruce (evita falsos positivos
     *  con mimes legítimos poco comunes que ya filtra el FormRequest). */
    private const MIME_EXTENSION_MAP = [
        'image/png'  => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/gif'  => ['gif'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
    ];

    public function scan(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType() ?? $file->getClientMimeType();

        $this->assertNotDenylistedExtension($originalName, $ext);
        $this->assertNoDoubleExtension($originalName);
        $this->assertMimeMatchesExtension($mime, $ext);
        $this->assertNoEmbeddedScriptSignature($file->getRealPath(), $mime);
    }

    private function assertNotDenylistedExtension(string $name, string $ext): void
    {
        if (in_array($ext, self::DENYLISTED_EXTENSIONS, true)) {
            throw new FileRejectedException("El archivo «{$name}» tiene un tipo no permitido por seguridad.");
        }
    }

    /**
     * Rechaza nombres tipo "factura.pdf.php" — una extensión "inocente"
     * seguida de una peligrosa, técnica clásica para burlar validadores que
     * solo miran la última extensión.
     */
    private function assertNoDoubleExtension(string $name): void
    {
        $parts = explode('.', strtolower($name));
        if (count($parts) < 3) {
            return;
        }

        $innerParts = array_slice($parts, 1, -1);
        foreach ($innerParts as $part) {
            if (in_array($part, self::DENYLISTED_EXTENSIONS, true)) {
                throw new FileRejectedException("El archivo «{$name}» tiene un nombre no permitido por seguridad.");
            }
        }
    }

    private function assertMimeMatchesExtension(string $mime, string $ext): void
    {
        if (!isset(self::MIME_EXTENSION_MAP[$mime])) {
            return;
        }

        if (!in_array($ext, self::MIME_EXTENSION_MAP[$mime], true)) {
            throw new FileRejectedException('El contenido del archivo no coincide con su extensión declarada.');
        }
    }

    /**
     * Detecta el patrón clásico de polyglot: un archivo "imagen" que en
     * realidad contiene código PHP o JS embebido para ejecutarse si el
     * servidor llega a interpretarlo (ej. GIF89a...<?php system($_GET[x]);).
     * Se lee solo el archivo completo en modo binario — los adjuntos de
     * este sistema están acotados a decenas de MB (ver
     * documento_tamano_maximo_mb), no hay riesgo de memoria.
     */
    private function assertNoEmbeddedScriptSignature(string|false $realPath, string $mime): void
    {
        if (!$realPath || !str_starts_with($mime, 'image/')) {
            return;
        }

        $contents = @file_get_contents($realPath);
        if ($contents === false) {
            return;
        }

        if (preg_match('/<\?php|<%|<script\b/i', $contents) === 1) {
            throw new FileRejectedException('El archivo contiene contenido no permitido embebido.');
        }
    }
}
