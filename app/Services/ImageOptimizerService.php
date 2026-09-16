<?php

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Optimiza imágenes rasterizadas (PNG/JPEG/WEBP) antes de guardarlas:
 * reescala si exceden el máximo configurado y recomprime. El propio
 * re-encode ya elimina EXIF/metadata (Intervention no copia el bloque EXIF
 * al re-codificar), que es el vector de fuga de datos más común en fotos
 * subidas desde celular (GPS, modelo de dispositivo).
 *
 * No toca SVG (vectorial, ver SvgSanitizer) ni PDF/DWG/otros documentos.
 */
class ImageOptimizerService
{
    private const OPTIMIZABLE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly ImageManager $manager = new ImageManager(new Driver()),
    ) {
    }

    public function isOptimizable(string $mime): bool
    {
        return in_array($mime, self::OPTIMIZABLE_MIMES, true);
    }

    /**
     * @return string Contenido binario ya optimizado, mismo formato de entrada.
     */
    public function optimize(string $binaryContents, string $mime): string
    {
        $maxWidth = (int) SettingsService::get('imagen_ancho_maximo_px', 2560);
        $maxHeight = (int) SettingsService::get('imagen_alto_maximo_px', 2560);
        $quality = (int) SettingsService::get('imagen_calidad_compresion', 82);

        $image = $this->manager->read($binaryContents);

        if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
            $image->scaleDown($maxWidth, $maxHeight);
        }

        $encoder = match ($mime) {
            'image/png' => new PngEncoder(),
            'image/webp' => new WebpEncoder(quality: $quality),
            default => new JpegEncoder(quality: $quality),
        };

        return (string) $image->encode($encoder);
    }
}
