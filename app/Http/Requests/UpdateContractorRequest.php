<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\ContractorController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:180'],
            'specialty' => ['sometimes', 'string', 'max:180'],
            'contact'   => ['sometimes', 'string', 'max:180'],
            'rating'    => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status'    => ['sometimes', Rule::in(ContractorController::CONTRACTOR_STATUSES)],
        ];
    }
}
