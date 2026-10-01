<?php

namespace App\Http\Requests;

use App\Models\MarketingProject;
use App\Http\Requests\Concerns\ChecksCompositeUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMarketingProjectRequest extends FormRequest
{
    use ChecksCompositeUniqueness;

    public function authorize(): bool
    {
        return true; // already behind auth:sanctum + role middleware
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:220'],
            'type' => ['required', Rule::in(MarketingProject::TYPES)],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'max:180'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'estimatedCost' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['nullable', Rule::in(['BAJA', 'MEDIA', 'ALTA'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->failIfCombinationExists(
                $v,
                'marketing_projects',
                [
                    'title' => $this->input('title'),
                    'type' => $this->input('type'),
                    'location' => $this->input('location'),
                ],
                'title',
                'Ya existe una propuesta de marketing con el mismo título, tipo y ubicación.',
            );
        });
    }
}
