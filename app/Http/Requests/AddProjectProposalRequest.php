<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddProjectProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contractorCode' => ['required', 'exists:contractors,code'],
            'materialCost' => ['required', 'numeric', 'min:0'],
            'laborCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['required', 'numeric', 'min:0'],
            'deliveryWeeks' => ['required', 'integer', 'min:0'],
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
        ];
    }
}
