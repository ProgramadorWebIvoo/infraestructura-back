<?php

namespace App\Services;

use App\Support\ImageSegments;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Optimiza imágenes rasterizadas (PNG/JPEG/WEBP) sin pérdida visible, en
 * este orden de preferencia:
 *
 * 1. Quitar metadatos (EXIF/GPS, texto, miniaturas) sin recomprimir: el
 *    resultado es idéntico bit a bit en píxeles.
 * 2. Solo si la imagen excede el máximo configurado, o trae una rotación EXIF
 *    que perderíamos al quitar el EXIF, se decodifica, se orienta/reescala y
 *    se recodifica (calidad alta, JPEG progresivo).
 * 3. Fotos grandes: se prueba recodificar y solo se usa el resultado si baja
 *    el peso de forma apreciable; nunca se entrega algo más pesado.
 *
 * Las imágenes animadas y los JPEG CMYK solo pasan por el paso 1 porque GD
 * los degradaría al recodificar. SVG tiene su propio sanitizador y los PDF/
 * hojas de cálculo sus propios procesadores.
 */
class ImageOptimizerService
{
    private const OPTIMIZABLE_KINDS = ['png', 'jpeg', 'webp'];

    /** Debajo de este peso no vale la pena probar una recompresión. */
    private const RECOMPRESS_MIN_BYTES = 200 * 1024;

    /** Mejora mínima (fracción) para preferir la recompresión sobre la versión solo sin metadatos. */
    private const RECOMPRESS_MIN_GAIN = 0.08;

    public function __construct(
        private readonly ImageManager $manager = new ImageManager(new Driver()),
    ) {
    }

    public function isOptimizable(string $kind): bool
    {
        return in_array($kind, self::OPTIMIZABLE_KINDS, true);
    }

    /**
     * @return string Contenido binario optimizado, mismo formato de entrada.
     */
    public function optimize(string $contents, string $kind): string
    {
        $stripped = ImageSegments::stripLossless($kind, $contents);
        $safeFallback = $stripped ?? $contents;

        if ($this->mustNotReencode($kind, $contents)) {
            return $safeFallback;
        }

        $info = getimagesizefromstring($contents);
        $exceedsMax = $info !== false && ($info[0] > $this->maxWidth() || $info[1] > $this->maxHeight());
        $needsRotation = $kind === 'jpeg' && $this->exifOrientation($contents) > 1;

        if ($exceedsMax || $needsRotation || $stripped === null) {
            $reencoded = $this->reencode($contents, $kind);

            // Reescalar un PNG de pocos colores puede pesar más que el original: nunca se entrega algo más pesado.
            return $needsRotation || $stripped === null || strlen($reencoded) < strlen($stripped) ? $reencoded : $stripped;
        }

        if ($kind !== 'png' && strlen($stripped) >= self::RECOMPRESS_MIN_BYTES) {
            $recompressed = $this->reencode($contents, $kind);
            if (strlen($recompressed) <= strlen($stripped) * (1 - self::RECOMPRESS_MIN_GAIN)) {
                return $recompressed;
            }
        }

        return $stripped;
    }

    private function reencode(string $contents, string $kind): string
    {
        $image = $this->manager->read($contents); // aplica la orientación EXIF al decodificar

        if ($image->width() > $this->maxWidth() || $image->height() > $this->maxHeight()) {
            $image->scaleDown($this->maxWidth(), $this->maxHeight());
        }

        $encoder = match ($kind) {
            'png' => new PngEncoder(),
            'webp' => new WebpEncoder(quality: $this->quality()),
            default => new JpegEncoder(quality: $this->quality(), progressive: true),
        };

        return (string) $image->encode($encoder);
    }

    private function mustNotReencode(string $kind, string $contents): bool
    {
        return match ($kind) {
            'png' => str_contains(substr($contents, 0, 4096), 'acTL'),
            'webp' => str_contains(substr($contents, 0, 4096), 'ANIM'),
            'jpeg' => (getimagesizefromstring($contents)['channels'] ?? 3) === 4,
            default => false,
        };
    }

    private function exifOrientation(string $jpeg): int
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $jpeg);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    private function maxWidth(): int
    {
        return (int) SettingsService::get('imagen_ancho_maximo_px', 2560);
    }

    private function maxHeight(): int
    {
        return (int) SettingsService::get('imagen_alto_maximo_px', 2560);
    }

    private function quality(): int
    {
        return (int) SettingsService::get('imagen_calidad_compresion', 85);
    }
}
