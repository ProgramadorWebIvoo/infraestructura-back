<?php

namespace App\Http\Requests;

use App\Support\ProjectDocumentFileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // already behind auth:sanctum
    }

    public function rules(): array
    {
        // Una "nueva versión" es siempre reemplazo puntual de un documento
        // específico — no tiene sentido subir un lote de N archivos como
        // versión de un solo documento lógico. Igual para comprobantes de
        // pago: un solo voucher por anticipo/finiquito, nunca un lote.
        $isNewVersion = $this->filled('new_version_of');
        $isComprobante = in_array($this->input('document_type'), ['COMPROBANTE_ANTICIPO', 'COMPROBANTE_FINIQUITO'], true);

        return [
            'document_type'  => ['required', Rule::in(ProjectDocumentFileRules::TYPES)],
            'new_version_of' => ['nullable', 'integer', 'exists:project_documents,id'],
            'files'          => ['required', 'array', ($isNewVersion || $isComprobante) ? 'size:1' : 'min:1', 'max:' . ProjectDocumentFileRules::maxFileCount()],
            'files.*'        => ProjectDocumentFileRules::fileRules($this->input('document_type')),
        ];
    }

    public function messages(): array
    {
        return ProjectDocumentFileRules::messages();
    }
}
