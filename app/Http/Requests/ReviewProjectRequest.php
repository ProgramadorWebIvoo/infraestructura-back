<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string'],
            'blueprintsCount' => ['required', 'integer', 'min:0'],
            'calculationsAdded' => ['required', 'boolean'],
        ];
    }
}
