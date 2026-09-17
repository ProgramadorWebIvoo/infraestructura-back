<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'     => ['sometimes', 'nullable', 'string', 'max:180'],
            'group'     => ['sometimes', 'string', 'max:40'],
            'scope'     => ['sometimes', Rule::in(['project', 'global'])],
            'critical'  => ['sometimes', 'boolean'],
            'isActive'  => ['sometimes', 'boolean'],
        ];
    }
}
