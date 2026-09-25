<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResidentApprovalRequest;
use App\Http\Requests\ClosureReasonRequest;
use App\Http\Requests\StoreSupplierProposalImageRequest;
use App\Http\Resources\ResidentClosureResource;
use App\Models\Project;
use App\Models\ProjectClosurePhoto;
use App\Services\ClosurePhotoService;
use App\Services\ProjectClosureService;
use App\Services\ProjectStateMachine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo "Mis obras" del RESIDENTE (F2-R R3). El acceso por obra lo garantiza
 * `EnsureProjectVisible` (404 si no es su residente efectivo); ADMIN/SUPERADMIN
 * pueden actuar como residente (S2).
 */
class ResidentClosureController extends Controller
{
    public function __construct(private ProjectClosureService $service)
    {
    }

    public function index()
    {
        $user = auth()->user();

        $projects = Project::query()
            ->has('closureReport')
            ->when($user->role === 'RESIDENTE', fn ($q) => $q->whereEffectiveResident($user->id))
            ->with(['closureReport.items', 'closureReport.photos'])
            ->latest('updated_at')
            ->get();

        return ResidentClosureResource::collection($projects);
    }

    public function show(Project $project)
    {
        return new ResidentClosureResource($this->withReport($project));
    }

    public function uploadPhoto(StoreSupplierProposalImageRequest $request, Project $project, ClosurePhotoService $photos)
    {
        ProjectStateMachine::assertStatus($project, 'INFORME_ENVIADO', 'Las fotos de verificación se adjuntan mientras el informe está en revisión del residente.');
        $this->service->assertCanActAsResident($project, auth()->user());

        $photo = $photos->store($project->closureReport, $request->file('image'), ProjectClosurePhoto::BY_RESIDENT, auth()->id(), $request->integer('itemId') ?: null);

        return response()->json(['id' => $photo->id], 201);
    }

    public function photo(Project $project, ProjectClosurePhoto $photo, ClosurePhotoService $photos): StreamedResponse
    {
        return $photos->streamForProject($project, $photo);
    }

    public function approve(ResidentApprovalRequest $request, Project $project)
    {
        $data = $request->validated();
        $this->service->approveByResident($project, auth()->user(), $data['notes'] ?? null, $data['items']);

        return new ResidentClosureResource($this->withReport($project));
    }

    public function reject(ClosureReasonRequest $request, Project $project)
    {
        $this->service->reject($project, auth()->user(), $request->validated('reason'));

        return new ResidentClosureResource($this->withReport($project));
    }

    private function withReport(Project $project): Project
    {
        abort_unless($project->closureReport, 404, 'La obra aún no tiene informe de cierre.');

        return $project->refresh()->load(['closureReport.items', 'closureReport.photos']);
    }
}
