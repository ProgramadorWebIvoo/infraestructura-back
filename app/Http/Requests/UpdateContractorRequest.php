<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\ContractorController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'email'     => ['sometimes', 'nullable', 'email', 'max:180'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:40'],
            'rating'    => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status'    => ['sometimes', Rule::in(ContractorController::CONTRACTOR_STATUSES)],
        ];
    }

    /**
     * Si el request toca email o phone, al menos uno debe quedar con valor —
     * se valida contra el contratista existente para no exigir ambos cuando
     * solo se está actualizando uno de los dos junto con otros campos.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->has('email') && !$this->has('phone')) {
                return;
            }

            $contractor = $this->route('contractor');
            $email = $this->has('email') ? $this->input('email') : $contractor?->email;
            $phone = $this->has('phone') ? $this->input('phone') : $contractor?->phone;

            if (empty($email) && empty($phone)) {
                $validator->errors()->add('email', 'Debe indicar al menos un email o teléfono de contacto.');
            }
        });
    }
}
