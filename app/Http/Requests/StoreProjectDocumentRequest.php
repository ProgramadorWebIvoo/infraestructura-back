<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectDocumentRequest extends FormRequest
{
    private const ALLOWED_CALC_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/vnd.ms-excel',                                           // xls
        'text/csv',
        'text/plain',
        'application/pdf',
        'application/vnd.oasis.opendocument.spreadsheet',                    // ods
    ];

    private const ALLOWED_PLANO_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/svg+xml',
        'image/tiff',
        'application/acad',           // dwg (generic)
        'application/octet-stream',   // dwg/dxf often sent as binary
    ];

    private const ALLOWED_CALC_EXTENSIONS = ['xlsx', 'xls', 'csv', 'pdf', 'ods'];
    private const ALLOWED_PLANO_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'svg', 'tiff', 'tif', 'dwg', 'dxf'];

    private const ALLOWED_FOTO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];
    private const ALLOWED_FOTO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Memoizados por instancia — rules()/messages() los leen dos veces cada
     *  uno; sin esto son 4 lecturas de SettingsService::get() por request. */
    private ?int $maxFileMb = null;
    private ?int $maxFileCount = null;

    public function authorize(): bool
    {
        return true; // already behind auth:sanctum
    }

    public function rules(): array
    {
        // Una "nueva versión" es siempre reemplazo puntual de un documento
        // específico — no tiene sentido subir un lote de N archivos como
        // versión de un solo documento lógico.
        $isNewVersion = $this->filled('new_version_of');

        return [
            'document_type'  => ['required', Rule::in(['CALC', 'PLANO', 'FOTO'])],
            'new_version_of' => ['nullable', 'integer', 'exists:project_documents,id'],
            'files'          => ['required', 'array', $isNewVersion ? 'size:1' : 'min:1', 'max:' . $this->maxFileCount()],
            'files.*'        => [
                'required',
                'file',
                'max:' . ($this->maxFileMb() * 1024),
                $this->validateFileMimeAndExtension(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.max' => "Cada archivo debe pesar máximo {$this->maxFileMb()} MB.",
            'files.max' => "Puede adjuntar como máximo {$this->maxFileCount()} archivos por carga.",
        ];
    }

    private function maxFileMb(): int
    {
        return $this->maxFileMb ??= (int) SettingsService::get('documento_tamano_maximo_mb', 25);
    }

    private function maxFileCount(): int
    {
        return $this->maxFileCount ??= (int) SettingsService::get('documento_cantidad_maxima_archivos', 10);
    }

    /**
     * Validates that each uploaded file matches:
     * 1. Server-side detected MIME type against the allowed list for document_type
     * 2. File extension against the allowed list for document_type
     *
     * Special handling: application/octet-stream is only accepted for .dwg/.dxf (PLANO).
     */
    private function validateFileMimeAndExtension(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $type = $this->input('document_type');
            $mime = $value->getMimeType(); // server-side detection via finfo
            $ext  = strtolower($value->getClientOriginalExtension());
            $name = $value->getClientOriginalName();

            $allowedMimes = match ($type) {
                'CALC' => self::ALLOWED_CALC_MIMES,
                'PLANO' => self::ALLOWED_PLANO_MIMES,
                'FOTO' => self::ALLOWED_FOTO_MIMES,
                default => [],
            };
            $allowedExts = match ($type) {
                'CALC' => self::ALLOWED_CALC_EXTENSIONS,
                'PLANO' => self::ALLOWED_PLANO_EXTENSIONS,
                'FOTO' => self::ALLOWED_FOTO_EXTENSIONS,
                default => [],
            };

            // Validate extension
            if (!in_array($ext, $allowedExts)) {
                $fail("La extensión «.{$ext}» del archivo «{$name}» no está permitida para documentos tipo {$type}.");
                return;
            }

            // Validate MIME type detected server-side
            if ($mime === 'application/octet-stream') {
                if (!in_array($ext, ['dwg', 'dxf'])) {
                    $fail("El archivo «{$name}» tiene un tipo de contenido no válido.");
                }
                return;
            }

            // finfo on some systems detects ZIP-based formats (xlsx, ods) as application/zip
            if ($mime === 'application/zip') {
                if (!in_array($ext, ['xlsx', 'ods'])) {
                    $fail("El archivo «{$name}» tiene un tipo de contenido no válido (ZIP no esperado).");
                }
                return;
            }

            if (!in_array($mime, $allowedMimes)) {
                $fail("El archivo «{$name}» tiene un tipo de contenido no válido para documentos tipo {$type}.");
            }
        };
    }
}
