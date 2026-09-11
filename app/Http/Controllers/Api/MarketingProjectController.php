<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectMarketingProjectRequest;
use App\Http\Requests\StoreMarketingProjectRequest;
use App\Http\Requests\UpdateMarketingProjectRequest;
use App\Http\Resources\MarketingProjectResource;
use App\Models\ConfigAuditLog;
use App\Models\MarketingProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD + flujo de aprobación de piezas de Marketing (impresiones, viniles,
 * pendones, y a futuro cualquier otro material publicitario/corporativo).
 * Flujo aparte del de `projects` (obra/infraestructura) — sin relación con
 * ProjectStateMachine, las transiciones acá son simples (4 estados, sin
 * ramificaciones) y se resuelven directo en el controller.
 *
 * Auditoría vía ConfigAuditLog (entity_type='marketing_project') en vez de
 * AuditLog: AuditLog::record() exige un Project asociado (ver su firma),
 * y este flujo no tiene uno — mismo patrón que usan los módulos
 * administrativos sin proyecto (usuarios, proveedores, materiales).
 */
class MarketingProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketingProject::query()->with(['requestedBy', 'reviewedBy']);

        if ($request->boolean('mine')) {
            $query->where('requested_by', auth()->id());
        }

        $projects = $query
            ->status($request->input('status'))
            ->type($request->input('type'))
            ->orderByDesc('created_at')
            ->paginate(15);

        return MarketingProjectResource::collection($projects);
    }

    public function store(StoreMarketingProjectRequest $request)
    {
        $data = $request->validated();

        $project = DB::transaction(function () use ($data) {
            $project = MarketingProject::create([
                'id' => MarketingProject::nextId(),
                'title' => $data['title'],
                'type' => $data['type'],
                'description' => $data['description'],
                'location' => $data['location'],
                'start_date' => $data['startDate'] ?? null,
                'end_date' => $data['endDate'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'estimated_cost' => $data['estimatedCost'] ?? null,
                'priority' => $data['priority'] ?? 'MEDIA',
                'status' => MarketingProject::STATUSES['BORRADOR'],
                'requested_by' => auth()->id(),
            ]);

            ConfigAuditLog::recordAdminAction(
                'marketing_project',
                'Creacion de propuesta de marketing',
                null,
                null,
                "\"{$project->title}\" ({$project->id})"
            );

            return $project;
        });

        return (new MarketingProjectResource($project->load(['requestedBy', 'reviewedBy', 'attachments'])))
            ->response()->setStatusCode(201);
    }

    public function show(MarketingProject $marketingProject)
    {
        return new MarketingProjectResource($marketingProject->load(['requestedBy', 'reviewedBy', 'attachments']));
    }

    /**
     * Solo el solicitante (o administración) puede editar, y solo mientras
     * la pieza está en un estado editable — una vez en EN_REVISION/APROBADO
     * el contenido queda congelado para lo que ya fue evaluado.
     */
    public function update(UpdateMarketingProjectRequest $request, MarketingProject $marketingProject)
    {
        $this->assertOwnerOrAdmin($marketingProject);
        $this->assertEditable($marketingProject);

        // "sometimes" en el Form Request: solo los campos presentes en $data
        // deben tocarse — un update() con todo el mapeo pisaría a null los
        // campos que el cliente no envió en este PATCH parcial.
        $fieldMap = [
            'title' => 'title',
            'type' => 'type',
            'description' => 'description',
            'location' => 'location',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'quantity' => 'quantity',
            'estimatedCost' => 'estimated_cost',
            'priority' => 'priority',
        ];

        $data = $request->validated();
        $updates = [];
        foreach ($fieldMap as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $updates[$snake] = $data[$camel];
            }
        }

        $marketingProject->update($updates);

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Modificacion de propuesta de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id})"
        );

        return new MarketingProjectResource($marketingProject->fresh(['requestedBy', 'reviewedBy', 'attachments']));
    }

    public function destroy(MarketingProject $marketingProject)
    {
        $this->assertOwnerOrAdmin($marketingProject);
        abort_unless($marketingProject->status === MarketingProject::STATUSES['BORRADOR'], 403, 'Solo se puede eliminar una propuesta en borrador.');

        $marketingProject->delete();

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Eliminacion de propuesta de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id})"
        );

        return response()->json(['message' => 'Propuesta eliminada correctamente.']);
    }

    /** BORRADOR o RECHAZADO (corrigiendo y reenviando) → EN_REVISION. */
    public function submit(MarketingProject $marketingProject)
    {
        $this->assertOwnerOrAdmin($marketingProject);
        abort_unless(
            in_array($marketingProject->status, [MarketingProject::STATUSES['BORRADOR'], MarketingProject::STATUSES['RECHAZADO']], true),
            403,
            'Solo se puede enviar a revision una propuesta en borrador o rechazada.'
        );

        $marketingProject->update(['status' => MarketingProject::STATUSES['EN_REVISION'], 'rejection_reason' => null]);

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Envio a revision de propuesta de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id})"
        );

        return new MarketingProjectResource($marketingProject->fresh(['requestedBy', 'reviewedBy', 'attachments']));
    }

    public function approve(MarketingProject $marketingProject)
    {
        abort_unless($marketingProject->status === MarketingProject::STATUSES['EN_REVISION'], 403, 'Solo se puede aprobar una propuesta en revision.');

        $marketingProject->update([
            'status' => MarketingProject::STATUSES['APROBADO'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Aprobacion de propuesta de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id})"
        );

        return new MarketingProjectResource($marketingProject->fresh(['requestedBy', 'reviewedBy', 'attachments']));
    }

    public function reject(RejectMarketingProjectRequest $request, MarketingProject $marketingProject)
    {
        abort_unless($marketingProject->status === MarketingProject::STATUSES['EN_REVISION'], 403, 'Solo se puede rechazar una propuesta en revision.');

        $marketingProject->update([
            'status' => MarketingProject::STATUSES['RECHAZADO'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => $request->validated()['reason'],
        ]);

        ConfigAuditLog::recordAdminAction(
            'marketing_project',
            'Rechazo de propuesta de marketing',
            null,
            null,
            "\"{$marketingProject->title}\" ({$marketingProject->id}): {$request->validated()['reason']}"
        );

        return new MarketingProjectResource($marketingProject->fresh(['requestedBy', 'reviewedBy', 'attachments']));
    }

    private function assertOwnerOrAdmin(MarketingProject $marketingProject): void
    {
        $user = auth()->user();
        $isAdmin = in_array($user->role, ['ADMIN', 'SUPERADMIN'], true);
        abort_unless($isAdmin || $marketingProject->requested_by === $user->id, 403, 'No tiene permiso sobre esta propuesta.');
    }

    private function assertEditable(MarketingProject $marketingProject): void
    {
        abort_unless(
            in_array($marketingProject->status, [MarketingProject::STATUSES['BORRADOR'], MarketingProject::STATUSES['RECHAZADO']], true),
            403,
            'Solo se puede editar una propuesta en borrador o rechazada.'
        );
    }
}
