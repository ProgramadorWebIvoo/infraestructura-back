<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierProposalImageRequest;
use App\Http\Requests\SubmitClosureReportRequest;
use App\Http\Resources\ClosureReportResource;
use App\Models\ProjectClosurePhoto;
use App\Models\ProjectClosureReport;
use App\Services\ClosurePhotoService;
use App\Services\ProjectClosureService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Enlace público del contratista (token = id del informe, sin caducidad) para su informe de cierre. */
class PublicClosureReportController extends Controller
{
    use LogsPublicAccess;

    public function show(Request $request, string $token)
    {
        $report = $this->findReport($token);
        $this->logPublicAccess($request, 'closure.view', "Informe: {$token}", $report->project);

        return $this->resource($request, $report)->additional([
            'project' => ['id' => $report->project->id, 'title' => $report->project->title, 'location' => $report->project->location],
            'editable' => $this->isEditable($report),
        ]);
    }

    public function uploadPhoto(StoreSupplierProposalImageRequest $request, string $token, ClosurePhotoService $photos)
    {
        $report = $this->findEditableReport($token);
        $photo = $photos->store($report, $request->file('image'), ProjectClosurePhoto::BY_CONTRACTOR, null, $request->integer('itemId') ?: null);

        return response()->json(['id' => $photo->id], 201);
    }

    public function deletePhoto(string $token, ProjectClosurePhoto $photo, ClosurePhotoService $photos)
    {
        $report = $this->findEditableReport($token);
        abort_unless($photo->report_id === $report->id && $photo->uploaded_by_type === ProjectClosurePhoto::BY_CONTRACTOR, 404);
        $photos->delete($photo);

        return response()->noContent();
    }

    public function photo(string $token, ProjectClosurePhoto $photo, ClosurePhotoService $photos): StreamedResponse
    {
        $report = $this->findReport($token);
        abort_unless($photo->report_id === $report->id, 404);

        return $photos->stream($photo);
    }

    public function submit(SubmitClosureReportRequest $request, string $token, ProjectClosureService $service)
    {
        $report = $service->submit($this->findReport($token), $request->validated());
        $this->logPublicAccess($request, 'closure.submit', "Informe: {$token}", $report->project);

        return $this->resource($request, $report);
    }

    private function findReport(string $token): ProjectClosureReport
    {
        $report = ProjectClosureReport::with(['project', 'items', 'photos'])->find($token);
        abort_unless($report, 404, 'Enlace no válido.');

        return $report;
    }

    private function findEditableReport(string $token): ProjectClosureReport
    {
        $report = $this->findReport($token);
        abort_unless($this->isEditable($report), 422, 'El informe ya fue enviado y está en revisión.');

        return $report;
    }

    private function isEditable(ProjectClosureReport $report): bool
    {
        return $report->isEditableByContractor() && $report->project->status === 'EN_EJECUCION';
    }

    private function resource(Request $request, ProjectClosureReport $report): ClosureReportResource
    {
        $request->attributes->set('closure_public', true);

        return new ClosureReportResource($report->loadMissing(['items', 'photos']));
    }
}
