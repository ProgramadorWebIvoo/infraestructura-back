<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'string', 'max:180'],
            'unit'               => ['sometimes', 'string', 'max:80'],
            'estimatedUnitPrice' => ['sometimes', 'numeric', 'min:0'],
            'isActive'           => ['sometimes', 'boolean'],
        ];
    }
}
