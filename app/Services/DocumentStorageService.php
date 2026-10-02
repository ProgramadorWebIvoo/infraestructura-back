<?php

namespace App\Services;

use App\Support\StoragePaths;
use Illuminate\Support\Facades\Storage;

class DocumentStorageService
{
    private const MAX_BASENAME_LENGTH = 120;

    /** Nombres de dispositivo reservados en Windows: no se pueden usar como nombre de archivo. */
    private const RESERVED_NAMES = ['con', 'prn', 'aux', 'nul', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];

    /**
     * Nombre final de un archivo recién subido: base saneada + extensión
     * canónica (la detectada por contenido, en minúsculas: `FOTO.JPEG` →
     * `FOTO.jpg`). Los puntos internos del nombre se aplanan para que no
     * quede ninguna "doble extensión" en disco.
     */
    public function normalizedFilename(string $originalName, string $canonicalExtension): string
    {
        $base = pathinfo($this->sanitizeFilename($originalName), PATHINFO_FILENAME);
        $base = trim(str_replace('.', '_', $base), ' ._');
        $base = mb_substr($base, 0, self::MAX_BASENAME_LENGTH);

        if ($base === '' || in_array(strtolower($base), self::RESERVED_NAMES, true)) {
            $base = 'archivo_' . $base;
            $base = rtrim($base, '_') ?: 'archivo';
        }

        return $base . '.' . strtolower($canonicalExtension);
    }

    /**
     * Sanitize filename to prevent path traversal and remove dangerous characters.
     *
     * - Strips directory components (basename only, también separadores de Windows)
     * - Removes null bytes
     * - Keeps only letters, numbers, dash, underscore, dot, space (unicode letters ok)
     * - Collapses repeated separators
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove path traversal (basename() no entiende "\" en servidores Linux)
        $filename = basename(str_replace('\\', '/', $filename));

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Normalize UTF-8 (NFD -> NFC) to avoid composed/decomposed issues
        if (class_exists('Normalizer')) {
            $filename = normalizer_normalize($filename, \Normalizer::NFC) ?: $filename;
        }

        // Replace any character that is not alphanumeric, dot, dash, underscore, or space
        // (también neutraliza controles Unicode como el override RTL U+202E)
        $filename = preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', $filename) ?? '';

        // Collapse multiple underscores/spaces into single underscore
        $filename = preg_replace('/[ _]+/', '_', $filename) ?? '';

        // Trim dots, spaces, underscores from edges
        $filename = trim($filename, ' ._');

        // Fallback if name is empty after sanitization
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file_' . now()->format('YmdHisv');
        }

        return $filename;
    }

    /**
     * Ensure the filename is unique in the target directory to prevent overwrites.
     * Appends a timestamp suffix if a file with the same name already exists.
     */
    public function uniqueFilename(string $directory, string $filename, ?string $disk = null): string
    {
        $storage = Storage::disk($disk ?? StoragePaths::disk());

        if (!$storage->exists($directory . '/' . $filename)) {
            return $filename;
        }

        $info = pathinfo($filename);
        $base = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        return $base . '_' . now()->format('YmdHisv') . $ext;
    }
}
