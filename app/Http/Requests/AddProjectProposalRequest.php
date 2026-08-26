<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Contracts\Validation\Validator;
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
            // No se valida contra el máximo configurado en CONFIG APP: Analistas
            // puede registrar anticipos negociados por encima de la política
            // interna (renegociación telefónica/directa con el proveedor). El
            // máximo configurado solo dispara una alerta visual en el frontend,
            // nunca bloquea el registro. Tope de sanidad fijo 100%. Cuando se
            // excede, el motivo pasa a ser obligatorio (ver withValidator()).
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
            'origen' => ['required', 'string', 'in:MANUAL,RENEGOCIACION,PORTAL-PROV,SEED-INSERT'],
            'fechaOferta' => ['required', 'date'],
            'precioAnterior' => ['nullable', 'numeric', 'min:0', 'required_if:origen,RENEGOCIACION'],
            'precioNuevo' => ['nullable', 'numeric', 'min:0', 'required_if:origen,RENEGOCIACION'],
            'motivo' => ['nullable', 'string'],
        ];
    }

    /**
     * `nullable` hace que Laravel omita cualquier regla siguiente (incluida
     * una closure) cuando el campo está ausente o es null — por eso esta
     * validación condicional vive en withValidator() en vez de en rules(),
     * donde sí se ejecuta siempre, esté `motivo` presente o no en el payload.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $advanceMax = SettingsService::get('anticipo_maximo_porcentaje', 100);
            $exceedsAdvance = (float) $this->input('negotiatedAdvancePercent', 0) > (float) $advanceMax;
            $isRenegotiation = $this->input('origen') === 'RENEGOCIACION';
            $motivo = trim((string) $this->input('motivo', ''));

            if (($exceedsAdvance || $isRenegotiation) && $motivo === '') {
                $validator->errors()->add('motivo', 'El motivo es obligatorio cuando se excede el anticipo máximo configurado o el origen es Renegociación.');
            }
        });
    }
}
