<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use App\Models\FileSecurityEvent;
use App\Support\IngestedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Puente único entre "el front mandó un archivo" y "el archivo quedó en
 * disco" — todo upload de este sistema (ProjectDocumentController,
 * SupplierProposalController, MarketingProjectAttachmentController) pasa
 * por acá en vez de llamar $file->storeAs() directo. Compone:
 *
 * 1. FileSecurityScanner  → pared de seguridad (rechaza antes de tocar disco)
 * 2. SvgSanitizer         → limpia SVG (XSS embebido)
 * 3. ImageOptimizerService → resize/recompresión/strip-EXIF de imágenes
 * 4. DocumentStorageService → sanitiza y desduplica el nombre de archivo
 * 5. FileSecurityEvent    → auditoría (aceptado/optimizado/rechazado)
 *
 * La validación de qué tipos/mimes están PERMITIDOS para cada tipo de
 * documento sigue en los FormRequest de cada contexto — este pipeline no
 * decide reglas de negocio, solo garantiza que lo que entra es seguro y
 * está optimizado.
 */
class FileIngestionPipeline
{
    public function __construct(
        private readonly FileSecurityScanner $scanner,
        private readonly SvgSanitizer $svgSanitizer,
        private readonly ImageOptimizerService $optimizer,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /**
     * @throws FileRejectedException si el archivo no pasa la pared de seguridad
     */
    public function ingest(
        UploadedFile $file,
        string $directory,
        string $context,
        ?string $contextId = null,
        string $disk = 'local',
    ): IngestedFile {
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType() ?? $file->getClientMimeType();

        try {
            $this->scanner->scan($file);
        } catch (FileRejectedException $e) {
            $this->logEvent('rejected', $context, $contextId, $originalName, $mime, $ext, $file->getSize(), null, $e->getMessage());
            throw $e;
        }

        $contents = file_get_contents($file->getRealPath());
        $optimized = false;

        if ($mime === 'image/svg+xml') {
            $contents = $this->svgSanitizer->sanitize($contents);
        } elseif ($this->optimizer->isOptimizable($mime)) {
            // Si el re-encode falla (imagen corrupta que igual pasó finfo,
            // formato exótico que GD no soporta), se guarda el original sin
            // optimizar en vez de bloquear el upload completo — la pared de
            // seguridad ya corrió arriba, esto es best-effort de calidad.
            try {
                $contents = $this->optimizer->optimize($contents, $mime);
                $optimized = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $safeName = $this->storage->sanitizeFilename($originalName);
        $uniqueName = $this->storage->uniqueFilename($directory, $safeName);
        $storedPath = $directory . '/' . $uniqueName;

        Storage::disk($disk)->put($storedPath, $contents);

        $sizeBytes = strlen($contents);
        $sha256 = hash('sha256', $contents);

        $this->logEvent(
            $optimized ? 'optimized' : 'accepted',
            $context,
            $contextId,
            $uniqueName,
            $mime,
            $ext,
            $sizeBytes,
            $sha256,
            null,
        );

        return new IngestedFile($uniqueName, $storedPath, $mime, $sizeBytes, $sha256, $optimized);
    }

    private function logEvent(
        string $status,
        string $context,
        ?string $contextId,
        string $name,
        ?string $mime,
        ?string $ext,
        ?int $size,
        ?string $sha256,
        ?string $reason,
    ): void {
        FileSecurityEvent::create([
            'context' => $context,
            'context_id' => $contextId,
            'original_name' => $name,
            'detected_mime' => $mime,
            'extension' => $ext,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'status' => $status,
            'reason' => $reason,
            'uploaded_by' => auth()->id(),
            'ip_address' => request()?->ip(),
        ]);
    }
}
