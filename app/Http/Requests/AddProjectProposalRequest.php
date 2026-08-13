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
            // No se valida contra el máximo configurado en CONFIG APP: Analistas
            // puede registrar anticipos negociados por encima de la política
            // interna (renegociación telefónica/directa con el proveedor). El
            // máximo configurado solo dispara una alerta visual en el frontend,
            // nunca bloquea el registro. Tope de sanidad fijo 100%.
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
        ];
    }
}
