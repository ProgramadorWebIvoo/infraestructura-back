<?php

namespace App\Http\Requests;

use App\Models\MarketingProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketingProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Igual que store, pero todos los campos son opcionales (PATCH parcial). */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:220'],
            'type' => ['sometimes', 'required', Rule::in(MarketingProject::TYPES)],
            'description' => ['sometimes', 'required', 'string'],
            'location' => ['sometimes', 'required', 'string', 'max:180'],
            'startDate' => ['sometimes', 'nullable', 'date'],
            'endDate' => ['sometimes', 'nullable', 'date', 'after_or_equal:startDate'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'estimatedCost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priority' => ['sometimes', Rule::in(['BAJA', 'MEDIA', 'ALTA'])],
        ];
    }
}
