<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.auditQuantity' => ['required', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
