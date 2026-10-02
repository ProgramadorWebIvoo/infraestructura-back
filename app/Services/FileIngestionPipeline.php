<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use App\Models\FileSecurityEvent;
use App\Services\FileIngestion\DetectedFile;
use App\Services\FileIngestion\Processors\FileProcessor;
use App\Support\IngestedFile;
use App\Support\StoragePaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Puente único entre "el front mandó un archivo" y "el archivo quedó en
 * disco" — todo upload de este sistema pasa por acá en vez de llamar
 * $file->storeAs() directo, sin excepción de tipo ni de contexto:
 *
 * 1. FileSecurityScanner → tipo real por contenido + inspectores de amenazas
 *                          (rechaza antes de tocar disco)
 * 2. FileProcessor       → sanitiza, normaliza y comprime según el tipo
 *                          (imagen, SVG, PDF, hoja de cálculo, texto)
 * 3. DocumentStorageService → nombre y extensión canónicos, sin colisiones
 * 4. FileSecurityEvent   → auditoría (aceptado/optimizado/rechazado)
 *
 * La validación de qué tipos/mimes están PERMITIDOS para cada tipo de
 * documento sigue en los FormRequest de cada contexto — este pipeline no
 * decide reglas de negocio, solo garantiza que lo que entra es seguro,
 * está normalizado y pesa lo mínimo sin perder calidad.
 */
class FileIngestionPipeline
{
    /** @param iterable<FileProcessor> $processors */
    public function __construct(
        private readonly FileSecurityScanner $scanner,
        private readonly DocumentStorageService $storage,
        private readonly iterable $processors,
    ) {
    }

    /**
     * Solo la pared de seguridad (sin tocar disco) y deja constancia del
     * rechazo. Existe aparte de ingest() para que un proceso con adjuntos
     * pueda rechazar TODOS los archivos antes de abrir su transacción — un
     * evento de rechazo registrado dentro de una transacción se perdería
     * con el rollback.
     *
     * @throws FileRejectedException si el archivo no pasa la pared de seguridad
     */
    public function scan(UploadedFile $file, string $context, ?string $contextId = null): DetectedFile
    {
        try {
            return $this->scanner->scan($file);
        } catch (FileRejectedException $e) {
            $this->logRejection($file, $context, $contextId, $e);
            throw $e;
        }
    }

    /**
     * @throws FileRejectedException si el archivo no pasa la pared de seguridad
     */
    public function ingest(
        UploadedFile $file,
        string $directory,
        string $context,
        ?string $contextId = null,
        ?string $disk = null,
    ): IngestedFile {
        $disk ??= StoragePaths::disk();
        $detected = $this->scan($file, $context, $contextId);
        $original = (string) file_get_contents($file->getRealPath());

        try {
            $contents = $this->process($detected, $original);
        } catch (FileRejectedException $e) {
            $this->logRejection($file, $context, $contextId, $e);
            throw $e;
        }

        $name = $this->storage->normalizedFilename($file->getClientOriginalName(), $detected->extension);
        $uniqueName = $this->storage->uniqueFilename($directory, $name, $disk);
        $storedPath = $directory . '/' . $uniqueName;

        Storage::disk($disk)->put($storedPath, $contents);

        $changed = $contents !== $original;
        $sha256 = hash('sha256', $contents);

        $this->logEvent(
            $changed ? 'optimized' : 'accepted',
            $context,
            $contextId,
            $uniqueName,
            $detected->mime,
            $detected->extension,
            strlen($contents),
            $sha256,
            $changed ? $this->describeChange(strlen($original), strlen($contents)) : null,
        );

        return new IngestedFile($uniqueName, $storedPath, $detected->mime, strlen($contents), $sha256, $changed, strlen($original));
    }

    /**
     * Aplica el primer procesador del tipo. Una falla inesperada de un
     * procesador de COMPRESIÓN no bloquea la subida (el archivo ya pasó la
     * pared de seguridad): se guarda el original y se reporta; en cambio la
     * sanitización de SVG es obligatoria porque sin ella el archivo no es
     * seguro de servir.
     */
    private function process(DetectedFile $detected, string $original): string
    {
        foreach ($this->processors as $processor) {
            if (!$processor->supports($detected)) {
                continue;
            }

            try {
                return $processor->process($detected, $original);
            } catch (FileRejectedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);

                if ($detected->isKind('svg')) {
                    throw new FileRejectedException("No se pudo procesar el archivo «{$detected->originalName}» de forma segura.");
                }

                return $original;
            }
        }

        return $original;
    }

    private function describeChange(int $before, int $after): string
    {
        $percent = $before > 0 ? round((1 - $after / $before) * 100) : 0;

        return "{$before} → {$after} bytes ({$percent}%)";
    }

    private function logRejection(UploadedFile $file, string $context, ?string $contextId, FileRejectedException $e): void
    {
        $this->logEvent(
            'rejected',
            $context,
            $contextId,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            strtolower($file->getClientOriginalExtension()),
            $file->getSize(),
            null,
            $e->getMessage(),
        );
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
            'original_name' => mb_substr($name, 0, 255),
            'detected_mime' => $mime,
            'extension' => $ext,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'status' => $status,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'uploaded_by' => auth()->id(),
            'ip_address' => request()?->ip(),
        ]);
    }
}
