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
        $project = [
            'projectId'                => $this->project->projectId,
            'projectTitle'             => $this->project->projectTitle,
            'projectDescription'       => $this->project->projectDescription,
            'projectLocation'          => $this->project->projectLocation,
            'projectType'              => $this->project->projectType,
            'approvedInvestmentAmount' => $this->project->approvedInvestmentAmount,
        ];

        if ($this->project->materials !== null) {
            $project['materials'] = $this->project->materials;
        }
        if ($this->project->estimatedTotal !== null) {
            $project['estimatedTotal'] = $this->project->estimatedTotal;
        }

        return [
            'project'   => $project,
            'proposals' => array_map(fn (EvaluationProposal $p) => $p->toArray(), $this->proposals),
        ];
    }
}
