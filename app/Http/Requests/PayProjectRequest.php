<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paymentType' => ['required', Rule::in(['ADVANCE', 'FINAL'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'paidDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
