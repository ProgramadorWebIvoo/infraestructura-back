<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClosureNotesRequest;
use App\Http\Requests\ClosureReasonRequest;
use App\Http\Requests\StoreProjectModificationRequest;
use App\Http\Resources\ProjectModificationRequestResource;
use App\Models\Project;
use App\Models\ProjectModificationRequest;
use App\Services\ModificationAccess;
use App\Services\ProjectModificationService;
use Illuminate\Http\Request;

/** Modificaciones de obra (F3). Los roles que solicitan/aprueban son configurables: se validan en el servicio. */
class ProjectModificationController extends Controller
{
    public function __construct(private ProjectModificationService $service, private ModificationAccess $access)
    {
    }

    /** Bandeja transversal (p. ej. Auditoría): solicitudes de las obras visibles para el usuario. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->canRequest($user) || $this->access->canReview($user), 403, 'Su rol no gestiona modificaciones de obra.');
        $status = $request->query('status');

        $requests = ProjectModificationRequest::query()
            ->with(['items.material', 'requester', 'reviewer', 'project:id,title'])
            ->whereHas('project', fn ($q) => $q->visibleTo($user))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(200)
            ->get();

        return ProjectModificationRequestResource::collection($requests);
    }

    public function forProject(Project $project)
    {
        $requests = $project->modificationRequests()->with(['items.material', 'requester', 'reviewer'])->latest()->get();

        // Todo bajo `data`: el cliente desenvuelve `response.data` y perdería las claves hermanas.
        return response()->json(['data' => [
            'requests' => ProjectModificationRequestResource::collection($requests)->resolve(),
            'effectiveQuantities' => $this->service->effectiveQuantities($project),
            'hasPending' => $requests->contains('status', ProjectModificationRequest::STATUS_PENDING),
            'canRequest' => $this->access->canRequest(auth()->user()),
            'canReview' => $this->access->canReview(auth()->user()),
        ]]);
    }

    public function store(StoreProjectModificationRequest $request, Project $project)
    {
        $modification = $this->service->create($project, $request->user(), $request->validated('reason'), $request->validated('items'));

        return (new ProjectModificationRequestResource($modification->load(['requester', 'reviewer'])))->response()->setStatusCode(201);
    }

    public function update(StoreProjectModificationRequest $request, Project $project, ProjectModificationRequest $modification)
    {
        $this->assertBelongs($project, $modification);
        $modification = $this->service->update($modification, $request->user(), $request->validated('reason'), $request->validated('items'));

        return new ProjectModificationRequestResource($modification->load(['requester', 'reviewer']));
    }

    public function approve(ClosureNotesRequest $request, Project $project, ProjectModificationRequest $modification)
    {
        $this->assertBelongs($project, $modification);
        $modification = $this->service->approve($modification, $request->user(), $request->validated('notes'));

        return new ProjectModificationRequestResource($modification->load(['requester', 'reviewer']));
    }

    public function reject(ClosureReasonRequest $request, Project $project, ProjectModificationRequest $modification)
    {
        $this->assertBelongs($project, $modification);
        $modification = $this->service->reject($modification, $request->user(), $request->validated('reason'));

        return new ProjectModificationRequestResource($modification->load(['requester', 'reviewer']));
    }

    private function assertBelongs(Project $project, ProjectModificationRequest $modification): void
    {
        abort_unless($modification->project_id === $project->id, 404);
    }
}
