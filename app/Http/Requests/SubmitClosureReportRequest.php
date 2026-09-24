<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitClosureReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // validado por token en el controller
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.executedQuantity' => ['required', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
