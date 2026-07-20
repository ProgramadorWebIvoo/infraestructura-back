<?php

namespace App\Http\Requests;

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

    public function authorize(): bool
    {
        return true; // already behind auth:sanctum
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['CALC', 'PLANO'])],
            'files'         => ['required', 'array', 'min:1', 'max:10'],
            'files.*'       => [
                'required',
                'file',
                'max:51200', // 50 MB per file
                $this->validateFileMimeAndExtension(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.max' => 'Cada archivo debe pesar máximo 50 MB.',
        ];
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

            $allowedMimes = $type === 'CALC' ? self::ALLOWED_CALC_MIMES : self::ALLOWED_PLANO_MIMES;
            $allowedExts  = $type === 'CALC' ? self::ALLOWED_CALC_EXTENSIONS : self::ALLOWED_PLANO_EXTENSIONS;

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
