<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DocumentStorageService
{
    /**
     * Sanitize filename to prevent path traversal and remove dangerous characters.
     *
     * - Strips directory components (basename only)
     * - Removes null bytes
     * - Keeps only alphanumeric, dash, underscore, dot, space
     * - Collapses repeated separators
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove path traversal
        $filename = basename($filename);

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Normalize UTF-8 (NFD -> NFC) to avoid composed/decomposed issues
        if (class_exists('Normalizer')) {
            $filename = normalizer_normalize($filename, \Normalizer::NFC);
        }

        // Replace any character that is not alphanumeric, dot, dash, underscore, or space
        $filename = preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', $filename);

        // Collapse multiple underscores/spaces into single underscore
        $filename = preg_replace('/[ _]+/', '_', $filename);

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
    public function uniqueFilename(string $directory, string $filename): string
    {
        $disk = Storage::disk('local');

        if (!$disk->exists($directory . '/' . $filename)) {
            return $filename;
        }

        $info = pathinfo($filename);
        $base = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        return $base . '_' . now()->format('YmdHisv') . $ext;
    }
}
