<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectProposalRequest;
use App\Http\Requests\ApproveInvestmentRequest;
use App\Http\Requests\PayProjectRequest;
use App\Http\Requests\RejectProjectRequest;
use App\Http\Requests\RejectProposalsRequest;
use App\Http\Requests\ResubmitProjectRequest;
use App\Http\Requests\ReviewProjectRequest;
use App\Http\Requests\SelectContractorRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\VerifyCompletionRequest;
use App\Http\Resources\ProjectResource;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Services\RejectionService;
use App\Services\SupplierProposalImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    private const STATUSES = [
        'CREADO'                => 'CREADO',
        'REVISADO_CIERRE'       => 'REVISADO_CIERRE',
        'RECHAZADO_CIERRE'      => 'RECHAZADO_CIERRE',
        'CONFIRMADO_PROCURA'    => 'CONFIRMADO_PROCURA',
        'COMPARATIVA_ENVIADA'   => 'COMPARATIVA_ENVIADA',
        'CONTRATADO'            => 'CONTRATADO',
        'EN_EJECUCION'          => 'EN_EJECUCION',
        'VERIFICANDO_FINALIZACION' => 'VERIFICANDO_FINALIZACION',
        'LISTO_PAGO_FINAL'      => 'LISTO_PAGO_FINAL',
        'COMPLETADO_PAGADO'     => 'COMPLETADO_PAGADO',
    ];

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $query = Project::with(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()])->latest('created_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return ProjectResource::collection($query->paginate($perPage));
    }

    public function show(Project $project)
    {
        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();

        $project = DB::transaction(function () use ($data) {
            $project = Project::create([
                'id' => Project::nextId(),
                'title' => $data['title'],
                'type' => $data['type'],
                'description' => $data['description'],
                'location' => $data['location'],
                'created_date' => now()->toDateString(),
                'status' => self::STATUSES['CREADO'],
                'estimated_total' => $data['estimatedTotal'] ?? $this->materialsTotal($data['materials']),
            ]);

            foreach ($data['materials'] as $index => $item) {
                $project->materials()->create([
                    'id' => $item['id'] ?? $project->id . '-MAT-' . ($index + 1),
                    'material_catalog_id' => $item['materialCatalogId'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'estimated_unit_price' => $item['estimatedUnitPrice'],
                    'condition' => $item['condition'],
                    'warranty_value' => $item['warrantyValue'] ?? null,
                    'warranty_unit' => $item['warrantyUnit'] ?? null,
                    'brand' => $item['brand'] ?? null,
                    'model' => $item['model'] ?? null,
                    'specifications' => $item['specifications'] ?? null,
                    'observations' => $item['observations'] ?? null,
                ]);
            }

            AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'Peticion registrada desde el modulo de infraestructura.');

            return $project;
        });

        return (new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()])))->response()->setStatusCode(201);
    }

    public function review(ReviewProjectRequest $request, Project $project)
    {
        $data = $request->validated();

        $project->update([
            'status' => self::STATUSES['REVISADO_CIERRE'],
            'cierre_obra_notes' => $data['notes'],
            'blueprints_count' => $data['blueprintsCount'],
            'calculations_added' => $data['calculationsAdded'],
        ]);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    /**
     * Rechaza la petición inicial (antes de llegar a revisión de planos/cálculos,
     * que es un flujo separado — ver RevisedDocumentsSection). No confundir con
     * rejectProposals(), que rechaza el cuadro comparativo de Procura.
     */
    public function rejectProject(RejectProjectRequest $request, Project $project)
    {
        $project = RejectionService::reject(
            $project,
            self::STATUSES['CREADO'],
            self::STATUSES['RECHAZADO_CIERRE'],
            'CIERRE_DE_OBRA',
            'Rechazo de petición de obra',
            $request->validated(),
            function (Project $project, array $payload) {}
        );

        return new ProjectResource($project);
    }

    /**
     * Infraestructura edita y reenvía una petición rechazada — mismo Project.id,
     * no crea uno nuevo. Reemplaza materiales (borrar+recrear, igual que store())
     * y vuelve el status a CREADO para que Cierre de Obra la reevalúe.
     */
    public function resubmitProject(ResubmitProjectRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['RECHAZADO_CIERRE'], 422, 'Solo se puede reenviar una petición rechazada.');

        $data = $request->validated();

        $project = DB::transaction(function () use ($data, $project) {
            $project->update([
                'title' => $data['title'],
                'description' => $data['description'],
                'location' => $data['location'],
                'status' => self::STATUSES['CREADO'],
                'estimated_total' => $data['estimatedTotal'] ?? $this->materialsTotal($data['materials']),
            ]);

            $project->materials()->delete();
            foreach ($data['materials'] as $index => $item) {
                $project->materials()->create([
                    'id' => $item['id'] ?? $project->id . '-MAT-' . ($index + 1),
                    'material_catalog_id' => $item['materialCatalogId'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'estimated_unit_price' => $item['estimatedUnitPrice'],
                    'condition' => $item['condition'],
                    'warranty_value' => $item['warrantyValue'] ?? null,
                    'warranty_unit' => $item['warrantyUnit'] ?? null,
                    'brand' => $item['brand'] ?? null,
                    'model' => $item['model'] ?? null,
                    'specifications' => $item['specifications'] ?? null,
                    'observations' => $item['observations'] ?? null,
                ]);
            }

            AuditLog::record($project, 'INFRAESTRUCTURA', 'Reenvío de petición corregida', 'Petición editada y reenviada a Cierre de Obra tras rechazo.');

            return $project;
        });

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function approveInvestment(ApproveInvestmentRequest $request, Project $project)
    {
        $data = $request->validated();

        $project->update([
            'status' => self::STATUSES['CONFIRMADO_PROCURA'],
            'procura_review_notes' => $data['notes'],
            'approved_investment_amount' => $data['approvedInvestmentAmount'],
        ]);

        AuditLog::record($project, 'PROCURA', 'Confirmacion de presupuesto y envio a licitacion', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function addProposal(AddProjectProposalRequest $request, Project $project)
    {
        $data = $request->validated();

        $contractor = Contractor::findOrFail($data['contractorCode']);

        $proposal = $project->proposals()->create([
            'id' => ProjectProposal::nextId(),
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => $data['materialCost'],
            'labor_cost' => $data['laborCost'],
            'total_cost' => $data['totalCost'],
            'delivery_weeks' => $data['deliveryWeeks'],
            'negotiated_advance_percent' => $data['negotiatedAdvancePercent'],
            'description' => $data['description'],
        ]);

        AuditLog::record($project, 'ANALISTA', 'Carga de propuesta', "Oferta {$proposal->id} cargada por {$contractor->name}.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function submitComparative(Project $project)
    {
        abort_if($project->proposals()->count() === 0, 422, 'El proyecto no tiene propuestas cargadas.');

        $project->update(['status' => self::STATUSES['COMPARATIVA_ENVIADA']]);
        AuditLog::record($project, 'ANALISTA', 'Carga de cuadro comparativo', 'Comparativa enviada a Procura para adjudicacion.');

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function importSupplierProposals(Project $project, SupplierProposalImportService $importService)
    {
        $result = $importService->import($project);
        $imported = $result['imported'];
        $skipped = $result['skipped'];
        $errors = $result['errors'];

        if ($imported === 0 && $skipped === 0 && empty($errors)) {
            return response()->json([
                'message' => 'No hay propuestas de materiales recibidas de proveedores para este proyecto.',
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'project' => new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()])),
            ]);
        }

        AuditLog::record($project, 'ANALISTA', 'Importación automática de propuestas de proveedores',
            "{$imported} propuesta(s) importada(s) desde el portal de proveedores" . ($skipped > 0 ? ", {$skipped} omitida(s)." : "."));

        return response()->json([
            'message' => "Se importaron {$imported} propuesta(s)" . ($skipped > 0 ? " ({$skipped} omitida(s))." : "."),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'project' => new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()])),
        ]);
    }

    public function removeProposal(Project $project, ProjectProposal $proposal)
    {
        abort_unless($proposal->project_id === $project->id, 422, 'La propuesta no pertenece al proyecto.');
        abort_if($project->selected_proposal_id === $proposal->id, 422, 'No se puede eliminar una propuesta adjudicada.');

        $proposal->delete();
        AuditLog::record($project, 'ANALISTA', 'Eliminacion de propuesta', "Propuesta {$proposal->id} retirada del cuadro comparativo.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function rejectProposals(RejectProposalsRequest $request, Project $project)
    {
        $project = RejectionService::reject(
            $project,
            self::STATUSES['COMPARATIVA_ENVIADA'],
            self::STATUSES['CONFIRMADO_PROCURA'],
            'PROCURA',
            'Rechazo de cuadro comparativo',
            $request->validated(),
            function (Project $project, array $payload) {
                $project->proposals()->delete();
                $project->selected_contractor_code = null;
                $project->selected_proposal_id = null;
            }
        );

        return new ProjectResource($project);
    }

    public function selectContractor(SelectContractorRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['COMPARATIVA_ENVIADA'], 422, 'Solo se puede adjudicar un contratista con el cuadro comparativo enviado (COMPARATIVA_ENVIADA).');

        $data = $request->validated();

        abort_unless($project->proposals()->whereKey($data['proposalId'])->exists(), 422, 'La propuesta no pertenece al proyecto.');

        $project->update([
            'status' => self::STATUSES['CONTRATADO'],
            'selected_contractor_code' => $data['contractorCode'],
            'selected_proposal_id' => $data['proposalId'],
        ]);

        AuditLog::record($project, 'PROCURA', 'Confirmacion de contratacion', "Contratista {$data['contractorCode']} adjudicado.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function pay(PayProjectRequest $request, Project $project)
    {
        $data = $request->validated();

        // El anticipo solo procede recién adjudicado el contratista; el pago
        // final solo tras verificar calidad — sin esto, FINANZAS podía pagar
        // un proyecto en cualquier estado, incluyendo reabrir uno ya cerrado.
        if ($data['paymentType'] === 'ADVANCE') {
            abort_unless($project->status === self::STATUSES['CONTRATADO'], 422, 'El anticipo solo se puede liberar con el contratista recién adjudicado (CONTRATADO).');
        } else {
            abort_unless($project->status === self::STATUSES['LISTO_PAGO_FINAL'], 422, 'El pago final solo se puede liberar tras la verificación de calidad (LISTO_PAGO_FINAL).');
        }

        ProjectPayment::updateOrCreate(
            ['project_id' => $project->id, 'payment_type' => $data['paymentType']],
            [
                'proposal_id' => $project->selected_proposal_id,
                'amount' => $data['amount'],
                'paid_date' => $data['paidDate'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]
        );

        $project->update(['status' => $data['paymentType'] === 'ADVANCE' ? self::STATUSES['EN_EJECUCION'] : self::STATUSES['COMPLETADO_PAGADO']]);
        AuditLog::record($project, 'FINANZAS', $data['paymentType'] === 'ADVANCE' ? 'Liberacion de anticipo' : 'Liberacion total de fondos', $data['notes'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function reportFinished(Project $project)
    {
        abort_unless($project->status === self::STATUSES['EN_EJECUCION'], 422, 'Solo se puede reportar como finalizada una obra en ejecución (EN_EJECUCION).');

        $project->update(['status' => self::STATUSES['VERIFICANDO_FINALIZACION']]);
        AuditLog::record($project, 'SISTEMA', 'Reporte de obra finalizada', 'La obra fue marcada como finalizada y pendiente de certificacion.');

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    public function verifyCompletion(VerifyCompletionRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['VERIFICANDO_FINALIZACION'], 422, 'Solo se puede verificar la finalización de una obra reportada como terminada (VERIFICANDO_FINALIZACION).');

        $data = $request->validated();

        $project->update([
            'status' => $data['qualityVerified'] ? self::STATUSES['LISTO_PAGO_FINAL'] : self::STATUSES['EN_EJECUCION'],
            'quality_verified' => $data['qualityVerified'],
            'completion_verified_date' => $data['completionVerifiedDate'] ?? now()->toDateString(),
        ]);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra', $data['details'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()]));
    }

    private function materialsTotal(array $materials): float
    {
        return collect($materials)->sum(fn ($item) => $item['quantity'] * $item['estimatedUnitPrice']);
    }

}
