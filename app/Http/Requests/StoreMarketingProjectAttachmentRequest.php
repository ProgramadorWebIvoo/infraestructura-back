<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingProjectAttachmentRequest extends FormRequest
{
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'pdf'];

    private ?int $maxFileMb = null;
    private ?int $maxFileCount = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:' . $this->maxFileCount()],
            'files.*' => [
                'required',
                'file',
                'max:' . ($this->maxFileMb() * 1024),
                'mimes:' . implode(',', self::ALLOWED_EXTENSIONS),
                'mimetypes:' . implode(',', self::ALLOWED_MIMES),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.max' => "Cada archivo debe pesar máximo {$this->maxFileMb()} MB.",
            'files.max' => "Puede adjuntar como máximo {$this->maxFileCount()} archivos por carga.",
            'files.*.mimes' => 'Solo se aceptan imágenes (PNG, JPG, WEBP) o PDF.',
            'files.*.mimetypes' => 'Solo se aceptan imágenes (PNG, JPG, WEBP) o PDF.',
        ];
    }

    /** Reutiliza el mismo setting que ProjectDocument — un único límite de tamaño de archivo para toda la app. */
    private function maxFileMb(): int
    {
        return $this->maxFileMb ??= (int) SettingsService::get('documento_tamano_maximo_mb', 25);
    }

    private function maxFileCount(): int
    {
        return $this->maxFileCount ??= (int) SettingsService::get('documento_cantidad_maxima_archivos', 10);
    }
}
