<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use App\Support\ValidatesImmutableMaterialQuantities;
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
            // Moneda en la que se digitaron los montos (ausente = moneda base).
            // El backend convierte a la base y guarda el original y la tasa
            // (ver ProposalCurrencyConverter).
            'quoteCurrency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3,10}$/', 'exists:currencies,code'],
            'materialCost' => ['required', 'numeric', 'min:0'],
            'materialItems' => ['nullable', 'array'],
            'materialItems.*.materialName' => ['required_with:materialItems', 'string'],
            'materialItems.*.quantity' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.unit' => ['nullable', 'string'],
            'materialItems.*.unitPrice' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.totalPrice' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.notes' => ['nullable', 'string'],
            // Explícitamente 0 permitido: hay ofertas donde el contratista no
            // cobra mano de obra por separado (ya viene incluida en materiales
            // o es autoinstalación del cliente).
            'laborCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['required', 'numeric', 'min:0'],
            'deliveryWeeks' => ['required', 'integer', 'min:0'],
            'durationValue' => ['nullable', 'integer', 'min:0'],
            'durationUnit' => ['nullable', 'string', 'in:dias,semanas,meses'],
            // No se valida contra el máximo configurado en CONFIG APP: Analistas
            // puede registrar anticipos negociados por encima de la política
            // interna (renegociación telefónica/directa con el proveedor). El
            // máximo configurado solo dispara una alerta visual en el frontend,
            // nunca bloquea el registro. Tope de sanidad fijo 100%. Cuando se
            // excede, el motivo pasa a ser obligatorio (ver withValidator()).
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
            // RENEGOCIACION no se elige acá: una renegociación reemplaza una
            // propuesta ya cargada (ver RenegotiateProposalRequest/
            // ProjectController::renegotiateProposal) — el precio anterior se
            // toma del registro existente, no se tipea a mano.
            'origen' => ['required', 'string', 'in:MANUAL,PORTAL-PROV,SEED-INSERT'],
            'fechaOferta' => ['required', 'date'],
            'motivoAnticipoExcedido' => ['nullable', 'string'],
        ];
    }

    /**
     * `nullable` hace que Laravel omita cualquier regla siguiente (incluida
     * una closure) cuando el campo está ausente o es null — por eso esta
     * validación condicional vive en withValidator() en vez de en rules(),
     * donde sí se ejecuta siempre, esté `motivoAnticipoExcedido` presente o
     * no en el payload.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $advanceMax = SettingsService::get('anticipo_maximo_porcentaje', 100);
            $exceedsAdvance = (float) $this->input('negotiatedAdvancePercent', 0) > (float) $advanceMax;
            $motivoAnticipoExcedido = trim((string) $this->input('motivoAnticipoExcedido', ''));

            if ($exceedsAdvance && $motivoAnticipoExcedido === '') {
                $validator->errors()->add('motivoAnticipoExcedido', 'El motivo es obligatorio cuando se excede el anticipo máximo configurado.');
            }

            ValidatesImmutableMaterialQuantities::validate($validator, $this->route('project'), $this->input('materialItems', []));
        });
    }
}
