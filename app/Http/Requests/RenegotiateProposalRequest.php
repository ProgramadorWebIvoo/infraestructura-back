<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use App\Support\ValidatesImmutableMaterialQuantities;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renegociar una propuesta existente: el "precio anterior" NUNCA se recibe
 * del cliente — se toma del total_cost de la propuesta que se reemplaza
 * (ver ProjectController::renegotiateProposal). `motivo` (por qué se
 * renegoció) siempre es obligatorio — a diferencia de una carga normal, toda
 * renegociación es una excepción que debe quedar justificada. Es un campo
 * DISTINTO de `motivoAnticipoExcedido` (por qué el anticipo negociado supera
 * el máximo de CONFIG APP): ambas condiciones pueden darse a la vez en la
 * misma renegociación y cada una necesita su propia justificación auditable.
 */
class RenegotiateProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'materialCost' => ['required', 'numeric', 'min:0'],
            'materialItems' => ['nullable', 'array'],
            'materialItems.*.materialName' => ['required_with:materialItems', 'string'],
            'materialItems.*.quantity' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.unit' => ['nullable', 'string'],
            'materialItems.*.unitPrice' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.totalPrice' => ['required_with:materialItems', 'numeric', 'min:0'],
            'materialItems.*.notes' => ['nullable', 'string'],
            'laborCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['required', 'numeric', 'min:0'],
            'deliveryWeeks' => ['required', 'integer', 'min:0'],
            'durationValue' => ['nullable', 'integer', 'min:0'],
            'durationUnit' => ['nullable', 'string', 'in:dias,semanas,meses'],
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
            // La renegociación se registra el mismo día o después de la
            // oferta original que reemplaza — nunca antes (no puede
            // "renegociarse" algo hacia el pasado) ni en el futuro.
            'fechaOferta' => ['required', 'date', 'after_or_equal:' . $this->route('proposal')?->fecha_oferta?->toDateString(), 'before_or_equal:today'],
            'motivo' => ['required', 'string'],
            'motivoAnticipoExcedido' => ['nullable', 'string'],
        ];
    }

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
