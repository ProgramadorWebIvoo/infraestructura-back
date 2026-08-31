<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\ContractorController;
use App\Models\Contractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza el RIF a formato canónico ANTES de que corran las reglas —
     * la regla `unique` compara el string literal en BD, así que sin
     * normalizar antes, dos formatos de guiones distintos del mismo RIF no
     * chocan entre sí (ver Contractor::normalizeRif).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('rif')) {
            $this->merge(['rif' => Contractor::normalizeRif($this->input('rif'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:180'],
            'rif'       => ['required', 'string', 'max:15', 'regex:' . Contractor::RIF_REGEX, 'unique:contractors,rif'],
            'specialty' => ['required', 'string', 'max:180'],
            'email'     => ['required_without:phone', 'nullable', 'email', 'max:180'],
            'phone'     => ['required_without:email', 'nullable', 'string', 'max:40'],
            'rating'    => ['nullable', 'numeric', 'min:0', 'max:5'],
            'status'    => ['sometimes', Rule::in(ContractorController::CONTRACTOR_STATUSES)],
        ];
    }
}
