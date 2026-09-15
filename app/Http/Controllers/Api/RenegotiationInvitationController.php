<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenegotiateProposalRequest;
use App\Http\Resources\ProjectResource;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\RenegotiationInvitation;
use App\Notifications\SupplierRenegotiationInvitation;
use App\Services\ProposalRenegotiationService;
use App\Services\SettingsService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Enlace público de RENEGOCIACIÓN — mismo patrón que SupplierInvitationController
 * (invitación de cotización de materiales), pero apunta a una propuesta
 * concreta que se está renegociando y usa exactamente los mismos campos que
 * el formulario manual (RenegotiateProposalModal / RenegotiateProposalRequest).
 */
class RenegotiationInvitationController extends Controller
{
    use LogsPublicAccess;

    /**
     * Crea el enlace de renegociación Y envía el correo al proveedor en un
     * solo paso (a diferencia de SupplierInvitationController::store, que
     * solo genera el enlace para copiar/enviar manualmente) — el proveedor
     * de esta oferta ya está determinado por la propuesta, no hace falta
     * pedir el contacto a mano.
     */
    public function store(Request $request, Project $project, ProjectProposal $proposal)
    {
        abort_unless($proposal->project_id === $project->id, 422, 'La propuesta no pertenece al proyecto.');
        abort_if($proposal->replaced_by_id !== null, 422, 'Esta propuesta ya fue renegociada anteriormente.');
        abort_if($project->selected_proposal_id === $proposal->id, 422, 'No se puede renegociar una propuesta ya adjudicada.');

        $contractor = Contractor::where('code', $proposal->contractor_code)->first();
        abort_if(!$contractor || !$contractor->email, 422, 'El proveedor no tiene un correo registrado.');

        $newId = Str::uuid()->toString();

        // Invalidar enlaces previos activos para la misma propuesta.
        RenegotiationInvitation::where('proposal_id', $proposal->id)
            ->whereNull('used_at')
            ->whereNull('replaced_by')
            ->update(['replaced_by' => $newId]);

        $invitation = RenegotiationInvitation::create([
            'id' => $newId,
            'project_id' => $project->id,
            'proposal_id' => $proposal->id,
            'contractor_code' => $proposal->contractor_code,
            'contractor_email' => $contractor->email,
            'expires_at' => now()->addDays((int) SettingsService::get('invitacion_renegociacion_vigencia_dias', RenegotiationInvitation::DEFAULT_VALIDITY_DAYS)),
        ]);

        // El envío es "mejora, no requisito" — mismo criterio que
        // NotificationDispatcher::notify(): un SMTP sin configurar/caído no
        // debe impedir que el enlace se genere. El token ya quedó persistido
        // arriba, así que el analista puede copiarlo/compartirlo manualmente
        // si el correo falla (`mailSent: false` en la respuesta).
        $mailSent = true;
        try {
            Notification::route('mail', $contractor->email)
                ->notify(new SupplierRenegotiationInvitation($project->title, $proposal->contractor_name_snapshot, $invitation->id));
        } catch (\Throwable $e) {
            report($e);
            $mailSent = false;
        }

        AuditLog::record(
            $project,
            auth()->user()->role,
            'Envio de enlace publico de renegociacion',
            "Propuesta: {$proposal->id} / Proveedor: {$proposal->contractor_name_snapshot} / Contacto: {$contractor->email}" . ($mailSent ? '' : ' [correo no enviado — ver logs]'),
        );

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return response()->json([
            'token' => $invitation->id,
            'url' => "{$frontendUrl}/renegociacion-publica/{$invitation->id}",
            'contractorEmail' => $invitation->contractor_email,
            'mailSent' => $mailSent,
            'createdAt' => $invitation->created_at?->format('Y-m-d H:i'),
            'expiresAt' => $invitation->expires_at?->format('Y-m-d H:i'),
        ], 201);
    }

    public function publicInfo(Request $request, string $token)
    {
        $invitation = RenegotiationInvitation::with(['project.materials', 'proposal'])->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $project = $invitation->project;
        $proposal = $invitation->proposal;
        if (!$proposal || $proposal->replaced_by_id !== null) {
            return response()->json(['message' => 'Esta oferta ya fue renegociada.'], 404);
        }

        $this->logPublicAccess($request, 'renegotiation.view', "Invitacion: {$token} / Propuesta: {$proposal->id}", $project);

        return response()->json([
            'contractorName' => $proposal->contractor_name_snapshot,
            'maxAdvancePercent' => (float) SettingsService::get('anticipo_maximo_porcentaje', 100),
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'location' => $project->location,
            ],
            'proposal' => [
                'id' => $proposal->id,
                'materialItems' => $proposal->material_items,
                'materials' => $project->materials->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'quantity' => $m->quantity,
                    'unit' => $m->unit,
                ]),
                'laborCost' => (float) $proposal->labor_cost,
                'totalCost' => (float) $proposal->total_cost,
                'totalCostOriginal' => $proposal->total_cost_original !== null ? (float) $proposal->total_cost_original : (float) $proposal->total_cost,
                'deliveryWeeks' => $proposal->delivery_weeks,
                'durationValue' => $proposal->duration_value,
                'durationUnit' => $proposal->duration_unit,
                'negotiatedAdvancePercent' => (float) $proposal->negotiated_advance_percent,
                'description' => $proposal->description,
                'quoteCurrency' => $proposal->quote_currency ?? 'USD',
                'fechaOferta' => $proposal->fecha_oferta?->toDateString(),
            ],
        ]);
    }

    /**
     * Envío del proveedor vía enlace público — reutiliza exactamente las
     * mismas reglas de validación y el mismo flujo transaccional que la
     * renegociación manual (ProjectController::renegotiateProposal), ver
     * RenegotiateProposalRequest::baseRules() y ProposalRenegotiationService.
     */
    public function submit(Request $request, string $token, ProposalRenegotiationService $renegotiationService)
    {
        $invitation = RenegotiationInvitation::with(['project', 'proposal'])->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $project = $invitation->project;
        $proposal = $invitation->proposal;
        if (!$proposal || $proposal->replaced_by_id !== null) {
            return response()->json(['message' => 'Esta oferta ya fue renegociada.'], 404);
        }
        // Re-chequeado acá (no solo en store()): el enlace pudo haberse
        // enviado antes de la adjudicación y el proveedor completarlo
        // después de que Procura ya seleccionó esta propuesta como ganadora
        // — en ese punto ya no es renegociable, igual que en el flujo manual
        // autenticado (ver ProjectController::renegotiateProposal).
        if ($project->selected_proposal_id === $proposal->id) {
            return response()->json(['message' => 'Esta oferta ya fue adjudicada y no puede renegociarse.'], 422);
        }

        $validator = Validator::make(
            $request->all(),
            RenegotiateProposalRequest::baseRules($proposal->fecha_oferta?->toDateString())
        );
        $validator->after(function (ValidatorContract $validator) use ($project, $request) {
            RenegotiateProposalRequest::applyAfterValidation($validator, $project, $request->all());
        });
        $data = $validator->validate();

        $precioAnterior = (float) $proposal->total_cost;
        $precioNuevo = (float) $data['totalCost'];

        $renegotiated = $renegotiationService->apply($project, $proposal, $data);

        $invitation->update(['used_at' => now()]);

        $auditDetails = "Propuesta {$proposal->id} ({$proposal->contractor_name_snapshot}) renegociada via enlace publico como {$renegotiated->id}. " .
            "Precio anterior: {$precioAnterior}. Precio nuevo: {$precioNuevo}. Diferencia: " . ($precioNuevo - $precioAnterior) . ". " .
            "Motivo: {$renegotiated->motivo}";
        if ($renegotiated->motivo_anticipo_excedido) {
            $auditDetails .= " Motivo exceso de anticipo: {$renegotiated->motivo_anticipo_excedido}";
        }
        AuditLog::record($project, 'PROVEEDOR', 'Renegociación de propuesta (enlace público)', $auditDetails);
        $this->logPublicAccess($request, 'renegotiation.submit', "Invitacion: {$token} / Propuesta nueva: {$renegotiated->id}", $project);

        return response()->json(['id' => $renegotiated->id], 201);
    }
}
