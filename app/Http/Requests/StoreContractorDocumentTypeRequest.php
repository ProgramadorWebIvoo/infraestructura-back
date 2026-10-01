<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (y, con `sometimes`, edición parcial) de un tipo de documento de proveedor. */
class StoreContractorDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $current = $this->route('contractorDocumentType');
        $required = $current ? 'sometimes' : 'required';

        return [
            'label' => [
                $required, 'string', 'max:150',
                Rule::unique('contractor_document_types', 'label')->ignore($current?->id),
            ],
            'isRequired' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }

    public function messages(): array
    {
        return ['label.unique' => 'Ya existe un tipo de documento con ese nombre.'];
    }
}
