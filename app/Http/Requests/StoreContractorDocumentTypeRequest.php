<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Alta (y, con `sometimes`, edición parcial) de un tipo de documento de proveedor. */
class StoreContractorDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->route('contractorDocumentType') ? 'sometimes' : 'required';

        return [
            'label' => [$required, 'string', 'max:150'],
            'isRequired' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
