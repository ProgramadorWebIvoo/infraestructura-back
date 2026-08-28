<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la imagen de un ítem del portal público — reglas fijas
 * (no las configurables de CONFIG APP como StoreProjectDocumentRequest):
 * es una superficie sin auth, más estricta a propósito. Solo imágenes,
 * 5MB, un archivo por request.
 */
class StoreSupplierProposalImageRequest extends FormRequest
{
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];
    private const MAX_KB = 5 * 1024;

    public function authorize(): bool
    {
        return true; // validado por token de invitación en el controller
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:' . self::MAX_KB, $this->validateMimeAndExtension()],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'La imagen debe pesar máximo 5 MB.',
        ];
    }

    private function validateMimeAndExtension(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $mime = $value->getMimeType();
            $ext = strtolower($value->getClientOriginalExtension());

            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $fail("La extensión «.{$ext}» no está permitida — solo JPG, PNG o WEBP.");
                return;
            }

            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                $fail('El archivo no es una imagen válida.');
            }
        };
    }
}
