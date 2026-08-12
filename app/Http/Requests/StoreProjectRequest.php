<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:220'],
            'type' => ['required', Rule::in(['INFRAESTRUCTURA', 'MANTENIMIENTO'])],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'max:180'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.id' => ['nullable', 'string', 'max:40'],
            'materials.*.materialCatalogId' => ['nullable', 'integer', 'exists:material_catalog,id'],
            'materials.*.name' => ['required', 'string', 'max:180'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0'],
            'materials.*.unit' => ['required', 'string', 'max:80'],
            'materials.*.estimatedUnitPrice' => ['required', 'numeric', 'min:0'],
            'estimatedTotal' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
