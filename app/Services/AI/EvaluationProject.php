<?php

namespace App\Services\AI;

class EvaluationProject
{
    /** @param array<int, array{name: string, quantity: float, unit: string, estimatedUnitPrice: float, condition: string}>|null $materials */
    public function __construct(
        public readonly string $projectId,
        public readonly string $projectTitle,
        public readonly string $projectDescription,
        public readonly string $projectLocation,
        public readonly string $projectType,
        public readonly float $approvedInvestmentAmount,
        public readonly ?array $materials = null,
        public readonly ?float $estimatedTotal = null,
    ) {
    }
}
