<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignResidentRequest;
use App\Http\Requests\AuditApprovalRequest;
use App\Http\Requests\ResidentApprovalRequest;
use App\Http\Requests\ClosureNotesRequest;
use App\Http\Requests\ClosureReasonRequest;
use App\Http\Requests\StoreSupplierProposalImageRequest;
use App\Http\Resources\ClosureReportResource;
use App\Http\Resources\ProjectResource;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectClosurePhoto;
use App\Models\User;
use App\Services\ClosurePhotoService;
use App\Services\ClosureReportLinkService;
use App\Services\ProjectClosureService;
use App\Services\ProjectStateMachine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Acciones internas del cierre: residente, Auditoría y Procura (el contratista usa PublicClosureReportController). */
class ClosureReportController extends Controller
{
    public function __construct(private ProjectClosureService $service)
    {
    }

    public function show(Project $project)
    {
        $report = $project->closureReport;
        abort_unless($report, 404, 'La obra aún no tiene informe de cierre.');

        return new ClosureReportResource($report->load(['items', 'photos']));
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
        abort_unless($project->closureReport && $photo->report_id === $project->closureReport->id, 404);

        return $photos->stream($photo);
    }

    public function residentApproval(ResidentApprovalRequest $request, Project $project)
    {
        $data = $request->validated();
        $this->service->approveByResident($project, auth()->user(), $data['notes'] ?? null, $data['items']);

        return $this->projectResponse($project);
    }

    public function reject(ClosureReasonRequest $request, Project $project)
    {
        $this->service->reject($project, auth()->user(), $request->validated('reason'));

        return $this->projectResponse($project);
    }

    public function auditApproval(AuditApprovalRequest $request, Project $project)
    {
        $data = $request->validated();
        $this->service->approveByAudit($project, auth()->user(), $data['notes'] ?? null, $data['items'] ?? null);

        return $this->projectResponse($project);
    }

    public function requestFiniquito(ClosureNotesRequest $request, Project $project)
    {
        $this->service->requestFiniquito($project, $request->validated('notes'));

        return $this->projectResponse($project);
    }

    public function returnToAudit(ClosureReasonRequest $request, Project $project)
    {
        $this->service->returnToAudit($project, $request->validated('reason'));

        return $this->projectResponse($project);
    }

    public function resendLink(Project $project, ClosureReportLinkService $links)
    {
        return response()->json(['mailSent' => $links->resend($project)]);
    }

    public function assignResident(AssignResidentRequest $request, Project $project)
    {
        $residentId = $request->validated('residentUserId');
        $project->update(['resident_user_id' => $residentId]);
        $name = $residentId ? User::whereKey($residentId)->value('name') : 'sin asignar';
        AuditLog::record($project, auth()->user()->role, 'Asignacion de residente', "Residente: {$name}.");

        return $this->projectResponse($project);
    }

    public function residents()
    {
        return User::where('role', 'INFRAESTRUCTURA')->where('status', 'Active')->orderBy('name')->get(['id', 'name']);
    }

    private function projectResponse(Project $project): ProjectResource
    {
        return new ProjectResource($project->refresh()->load(Project::detailRelations()));
    }
}
