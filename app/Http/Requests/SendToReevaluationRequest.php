<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsProjectAttachments;
use Illuminate\Foundation\Http\FormRequest;

class SendToReevaluationRequest extends FormRequest
{
    use AcceptsProjectAttachments;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'observations' => ['nullable', 'string', 'max:1000'],
            ...$this->attachmentRules(['files' => 'REEVALUACION']),
        ];
    }

    public function messages(): array
    {
        return $this->attachmentMessages(['files']);
    }
}
