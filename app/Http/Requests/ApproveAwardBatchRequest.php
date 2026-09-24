<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAwardBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectIds' => ['required', 'array', 'min:1', 'max:100'],
            'projectIds.*' => ['required', 'distinct', 'exists:projects,id'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
