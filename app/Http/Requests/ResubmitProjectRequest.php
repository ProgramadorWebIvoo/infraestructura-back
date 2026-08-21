<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:220'],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'max:180'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.id' => ['nullable', 'string', 'max:40'],
            'materials.*.materialCatalogId' => ['nullable', 'integer', 'exists:material_catalog,id'],
            'materials.*.name' => ['required', 'string', 'max:180'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0'],
            'materials.*.unit' => ['required', 'string', 'max:80'],
            'materials.*.estimatedUnitPrice' => ['required', 'numeric', 'min:0'],
            'materials.*.condition' => ['required', Rule::in(['NUEVO', 'USADO', 'AMBAS'])],
            'materials.*.warrantyValue' => ['nullable', 'integer', 'min:0', 'required_with:materials.*.warrantyUnit'],
            'materials.*.warrantyUnit' => ['nullable', Rule::in(['DIAS', 'MESES', 'ANOS']), 'required_with:materials.*.warrantyValue'],
            'materials.*.brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'materials.*.model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'materials.*.specifications' => ['sometimes', 'nullable', 'string'],
            'materials.*.observations' => ['sometimes', 'nullable', 'string'],
            'estimatedTotal' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
