<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'     => ['sometimes', 'string', 'max:120'],
            'isActive'  => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
