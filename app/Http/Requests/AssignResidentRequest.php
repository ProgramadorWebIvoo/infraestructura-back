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
            'residentUserId' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'RESIDENTE')->where('status', 'Active')],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
