<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qualityVerified' => ['required', 'boolean'],
            'completionVerifiedDate' => ['nullable', 'date'],
            'details' => ['nullable', 'string'],
        ];
    }
}
