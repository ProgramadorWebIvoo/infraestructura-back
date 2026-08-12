<?php

namespace App\Http\Requests;

use App\Rules\SsrfSafeUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider'   => ['required', 'string', Rule::in(['openai', 'anthropic', 'gemini'])],
            'model'      => ['required', 'string', 'max:100'],
            'apiKey'     => ['required', 'string', 'min:8'],
            'baseUrl'    => ['nullable', 'string', 'max:255', new SsrfSafeUrl()],
            'maxTokens'  => ['nullable', 'integer', 'min:1', 'max:100000'],
            'isActive'   => ['sometimes', 'boolean'],
            'isFallback' => ['sometimes', 'boolean'],
            'sortOrder'  => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
