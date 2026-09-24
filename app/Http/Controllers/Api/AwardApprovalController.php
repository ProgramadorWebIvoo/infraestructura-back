<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveAwardBatchRequest;
use App\Http\Requests\ApproveAwardRequest;
use App\Http\Requests\RejectAwardRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\AwardApprovalService;

class AwardApprovalController extends Controller
{
    public function __construct(private AwardApprovalService $service)
    {
    }

    public function approve(ApproveAwardRequest $request, Project $project)
    {
        $this->service->approve($project, $request->validated('observations'));

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function approveBatch(ApproveAwardBatchRequest $request)
    {
        $data = $request->validated();
        $projects = Project::whereIn('id', $data['projectIds'])->get();

        $this->service->approveBatch($projects, $data['observations'] ?? null);

        return ProjectResource::collection($projects->fresh());
    }

    public function reject(RejectAwardRequest $request, Project $project)
    {
        $project = $this->service->reject($project, $request->validated());

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function sendToFinance(Project $project)
    {
        $this->service->sendToFinance($project);

        return new ProjectResource($project->load(Project::detailRelations()));
    }
}
