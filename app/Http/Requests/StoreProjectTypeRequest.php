<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key'       => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', 'unique:project_types,key'],
            'label'     => ['required', 'string', 'max:120'],
            'isActive'  => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
