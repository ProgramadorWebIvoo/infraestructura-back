<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\ContractorController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:180'],
            'specialty' => ['required', 'string', 'max:180'],
            'contact'   => ['required', 'string', 'max:180'],
            'rating'    => ['nullable', 'numeric', 'min:0', 'max:5'],
            'status'    => ['sometimes', Rule::in(ContractorController::CONTRACTOR_STATUSES)],
        ];
    }
}
