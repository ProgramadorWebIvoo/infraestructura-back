<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksCompositeUniqueness;
use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentSignatureStepRequest extends FormRequest
{
    use ChecksCompositeUniqueness;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->route('paymentSignatureStep') ? 'sometimes' : 'required';

        return [
            'paymentType' => [$required, Rule::in(['ADVANCE', 'FINAL'])],
            'stepOrder' => [$required, 'integer', 'min:1', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(Roles::valid())],
            'userId' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'label' => [$required, 'string', 'max:150'],
            'isActive' => ['sometimes', 'boolean'],
            'isRequired' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $hasRole = $this->filled('role');
            $hasUser = $this->filled('userId');

            if ($hasRole && $hasUser) {
                $v->errors()->add('role', 'Un paso firma por rol o por usuario específico, no ambos.');
            }
            if (!$hasRole && !$hasUser && !$this->route('paymentSignatureStep')) {
                $v->errors()->add('role', 'Indique un rol o un usuario específico para este paso.');
            }

            $current = $this->route('paymentSignatureStep');
            $this->failIfCombinationExists(
                $v,
                'payment_signature_steps',
                [
                    'payment_type' => $this->input('paymentType', $current?->payment_type),
                    'step_order' => $this->input('stepOrder', $current?->step_order),
                ],
                'stepOrder',
                'Ya existe un paso con ese orden para este tipo de pago.',
                $current?->id,
            );
        });
    }
}
