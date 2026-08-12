<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contractorCode' => ['required', 'exists:contractors,code'],
            'proposalId' => ['required', 'exists:project_proposals,id'],
        ];
    }
}
