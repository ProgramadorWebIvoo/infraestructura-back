<?php

namespace App\Services\AI;

class EvaluationProject
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $projectTitle,
        public readonly string $projectDescription,
        public readonly string $projectLocation,
        public readonly string $projectType,
        public readonly float $approvedInvestmentAmount,
    ) {
    }
}
