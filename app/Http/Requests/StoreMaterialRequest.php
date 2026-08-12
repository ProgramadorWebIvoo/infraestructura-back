<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:180'],
            'unit'               => ['required', 'string', 'max:80'],
            'estimatedUnitPrice' => ['nullable', 'numeric', 'min:0'],
            'isActive'           => ['sometimes', 'boolean'],
        ];
    }
}
