<?php

namespace App\Services\AI;

class EvaluationPayload
{
    /**
     * @param EvaluationProject  $project
     * @param EvaluationProposal[] $proposals
     */
    public function __construct(
        public readonly EvaluationProject $project,
        public readonly array $proposals,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project'   => [
                'projectId'                => $this->project->projectId,
                'projectTitle'             => $this->project->projectTitle,
                'projectDescription'       => $this->project->projectDescription,
                'projectLocation'          => $this->project->projectLocation,
                'projectType'              => $this->project->projectType,
                'approvedInvestmentAmount' => $this->project->approvedInvestmentAmount,
            ],
            'proposals' => array_map(fn (EvaluationProposal $p) => $p->toArray(), $this->proposals),
        ];
    }
}
