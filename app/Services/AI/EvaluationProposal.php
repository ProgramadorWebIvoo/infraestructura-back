<?php

namespace App\Services\AI;

class EvaluationProposal
{
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
    ) {
    }

    public function toArray(): array
    {
        $arr = [
            'id'                      => $this->id,
            'contractorCode'          => $this->contractorCode,
            'contractorName'          => $this->contractorName,
            'contractorRating'        => $this->contractorRating,
            'materialCost'            => $this->materialCost,
            'laborCost'               => $this->laborCost,
            'totalCost'               => $this->totalCost,
            'deliveryWeeks'           => $this->deliveryWeeks,
            'negotiatedAdvancePercent' => $this->negotiatedAdvancePercent,
            'description'             => $this->description,
        ];

        if ($this->observations !== null) {
            $arr['observations'] = $this->observations;
        }

        return $arr;
    }
}
