<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            // Solo obras de ubicación personalizada (D14); la regla de fondo vive en ResidentAssignmentService.
            'residentUserId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'RESIDENTE')->where('status', 'Active')],
        ];
    }
}
