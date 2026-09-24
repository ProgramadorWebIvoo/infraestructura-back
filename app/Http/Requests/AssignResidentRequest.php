<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'residentUserId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'INFRAESTRUCTURA')],
        ];
    }
}
