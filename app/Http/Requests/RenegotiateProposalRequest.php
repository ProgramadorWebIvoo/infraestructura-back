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
        return self::baseRules($this->route('proposal')?->fecha_oferta?->toDateString());
    }

    /**
     * Reglas compartidas con el flujo público de renegociación
     * (RenegotiationInvitationController::submit), que no tiene FormRequest
     * propio porque no hay route-model-binding de {proposal} en una ruta
     * pública por token — evita duplicar el set completo de reglas.
     */
    public static function baseRules(?string $minFechaOferta): array
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
            'fechaOferta' => ['required', 'date', 'after_or_equal:' . $minFechaOferta, 'before_or_equal:today'],
            'motivo' => ['required', 'string'],
            'motivoAnticipoExcedido' => ['nullable', 'string'],
        ];
    }

    /** Validación "after" compartida — ver baseRules(). */
    public static function applyAfterValidation(Validator $validator, ?\App\Models\Project $project, array $input): void
    {
        $advanceMax = SettingsService::get('anticipo_maximo_porcentaje', 100);
        $exceedsAdvance = (float) ($input['negotiatedAdvancePercent'] ?? 0) > (float) $advanceMax;
        $motivoAnticipoExcedido = trim((string) ($input['motivoAnticipoExcedido'] ?? ''));

        if ($exceedsAdvance && $motivoAnticipoExcedido === '') {
            $validator->errors()->add('motivoAnticipoExcedido', 'El motivo es obligatorio cuando se excede el anticipo máximo configurado.');
        }

        ValidatesImmutableMaterialQuantities::validate($validator, $project, $input['materialItems'] ?? []);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            self::applyAfterValidation($validator, $this->route('project'), $this->all());
        });
    }
}
