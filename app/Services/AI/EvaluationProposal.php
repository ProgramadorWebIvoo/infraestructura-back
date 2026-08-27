<?php

namespace App\Services\AI;

class EvaluationProposal
{
    /** @param array<int, array{materialName: string, quantity: float, unit: string, unitPrice: float, totalPrice: float, notes?: string}>|null $materialItems */
    public function __construct(
        public readonly string $id,
        public readonly string $contractorCode,
        public readonly string $contractorName,
        public readonly float $contractorRating,
        public readonly float $materialCost,
        public readonly float $laborCost,
        public readonly float $totalCost,
        public readonly int $deliveryWeeks,
        public readonly float $negotiatedAdvancePercent,
        public readonly string $description,
        public readonly ?string $observations = null,
        public readonly ?array $materialItems = null,
        public readonly ?int $durationValue = null,
        public readonly ?string $durationUnit = null,
        public readonly ?string $origen = null,
        public readonly ?float $precioAnterior = null,
        public readonly ?float $precioNuevo = null,
        public readonly ?float $diferencia = null,
        public readonly ?string $motivo = null,
        public readonly ?string $motivoAnticipoExcedido = null,
        public readonly ?string $fechaOferta = null,
    ) {
    }

    public function toArray(): array
    {
        $arr = [
            'id'                       => $this->id,
            'contractorCode'           => $this->contractorCode,
            'contractorName'           => $this->contractorName,
            'contractorRating'         => $this->contractorRating,
            'materialCost'             => $this->materialCost,
            'laborCost'                => $this->laborCost,
            'totalCost'                => $this->totalCost,
            'deliveryWeeks'            => $this->deliveryWeeks,
            'negotiatedAdvancePercent' => $this->negotiatedAdvancePercent,
            'description'              => $this->description,
        ];

        if ($this->observations !== null) {
            $arr['observations'] = $this->observations;
        }
        if ($this->materialItems !== null) {
            $arr['materialItems'] = $this->materialItems;
        }
        if ($this->durationValue !== null) {
            $arr['durationValue'] = $this->durationValue;
        }
        if ($this->durationUnit !== null) {
            $arr['durationUnit'] = $this->durationUnit;
        }
        if ($this->origen !== null) {
            $arr['origen'] = $this->origen;
        }
        if ($this->precioAnterior !== null) {
            $arr['precioAnterior'] = $this->precioAnterior;
        }
        if ($this->precioNuevo !== null) {
            $arr['precioNuevo'] = $this->precioNuevo;
        }
        if ($this->diferencia !== null) {
            $arr['diferencia'] = $this->diferencia;
        }
        if ($this->motivo !== null) {
            $arr['motivo'] = $this->motivo;
        }
        if ($this->motivoAnticipoExcedido !== null) {
            $arr['motivoAnticipoExcedido'] = $this->motivoAnticipoExcedido;
        }
        if ($this->fechaOferta !== null) {
            $arr['fechaOferta'] = $this->fechaOferta;
        }

        return $arr;
    }
}
