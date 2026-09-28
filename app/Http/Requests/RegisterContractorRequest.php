<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesContractorDocuments;
use App\Models\Contractor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autoregistro público de proveedores (multipart): datos + un archivo por
 * cada tipo de documento activo (los obligatorios no pueden faltar).
 */
class RegisterContractorRequest extends FormRequest
{
    use ValidatesContractorDocuments;

    public function authorize(): bool
    {
        return true; // endpoint público, protegido por throttle:public-api
    }

    /** Normaliza el RIF antes de `unique` (ver Contractor::normalizeRif). */
    protected function prepareForValidation(): void
    {
        if ($this->has('rif')) {
            $this->merge(['rif' => Contractor::normalizeRif($this->input('rif'))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', 'unique:contractors,code'],
            'name' => ['required', 'string', 'max:180'],
            'rif' => ['required', 'string', 'max:15', 'regex:' . Contractor::RIF_REGEX, 'unique:contractors,rif'],
            'specialty' => ['required', 'string', 'max:180'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            ...$this->documentRules(),
        ];
    }

    public function attributes(): array
    {
        return $this->documentAttributes();
    }
}
