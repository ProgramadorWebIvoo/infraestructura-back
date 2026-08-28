<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupplierInvitationController extends Controller
{
    use LogsPublicAccess;

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id'      => ['required', 'string', 'exists:projects,id'],
            'supplierName'    => ['required', 'string', 'max:180'],
            'supplierCompany' => ['nullable', 'string', 'max:180'],
            'supplierContact' => ['required', 'email', 'max:180'],
        ]);

        $project = Project::find($data['project_id']);

        $newId = Str::uuid()->toString();

        // Invalidar enlaces previos activos para el mismo proyecto + contacto
        SupplierInvitation::where('project_id', $data['project_id'])
            ->where('supplier_contact', $data['supplierContact'])
            ->whereNull('used_at')
            ->whereNull('replaced_by')
            ->update(['replaced_by' => $newId]);

        $invitation = SupplierInvitation::create([
            'id'               => $newId,
            'project_id'       => $data['project_id'],
            'supplier_name'    => $data['supplierName'],
            'supplier_company' => $data['supplierCompany'] ?? null,
            'supplier_contact' => $data['supplierContact'],
            'expires_at'       => now()->addDays((int) SettingsService::get('invitacion_proveedor_vigencia_dias', SupplierInvitation::DEFAULT_VALIDITY_DAYS)),
        ]);

        AuditLog::record(
            $project,
            auth()->user()->role,
            'Envio de invitacion a proveedor',
            "Proveedor: {$invitation->supplier_name} / Contacto: {$invitation->supplier_contact}",
        );

        return response()->json([
            'token'          => $invitation->id,
            'projectTitle'   => $project->title,
            'supplierName'   => $invitation->supplier_name,
            'supplierContact'=> $invitation->supplier_contact,
            'createdAt'      => $invitation->created_at?->format('Y-m-d H:i'),
            'expiresAt'      => $invitation->expires_at?->format('Y-m-d H:i'),
        ], 201);
    }

    /**
     * Invitación más reciente para un proveedor+obra, viva o no —
     * consultada al abrir/reabrir InviteModal para mostrar el enlace ya
     * generado en vez de forzar a regenerar uno nuevo cada vez, Y para
     * detectar que un enlace que el modal tenía en memoria como "vigente"
     * dejó de estarlo mientras el modal seguía abierto (ej. el proveedor
     * lo usó recién). `status` distingue POR QUÉ dejó de ser válido —
     * used/expired/replaced — para que el frontend muestre el motivo real
     * en vez de un genérico "ya no está disponible".
     */
    public function latest(Request $request)
    {
        $data = $request->validate([
            'project_id'      => ['required', 'string', 'exists:projects,id'],
            'supplierContact' => ['required', 'email', 'max:180'],
        ]);

        // "Más reciente" = la punta de la cadena de reemplazos (la fila que
        // AÚN NO fue reemplazada por otra, replaced_by IS NULL) — más
        // confiable que ordenar solo por created_at: timestamp sin
        // microsegundos, dos invitaciones creadas en el mismo segundo (ej.
        // clic doble en "Regenerar") empatarían y el orden no sería
        // determinista. Una invitación con replaced_by seteado siempre es
        // la vieja de un par, nunca la nueva.
        $invitation = SupplierInvitation::with('project')
            ->where('project_id', $data['project_id'])
            ->where('supplier_contact', $data['supplierContact'])
            ->whereNull('replaced_by')
            ->latest('created_at')
            ->first();

        // Si no hay ninguna "punta" (caso raro: la cadena entera quedó con
        // replaced_by apuntando a una fila que nunca se creó, o no hay
        // ninguna invitación en absoluto), cae a la más reciente sin
        // filtrar — sigue siendo mejor mostrar algo con status correcto
        // que un null que el frontend no puede explicar.
        $invitation ??= SupplierInvitation::with('project')
            ->where('project_id', $data['project_id'])
            ->where('supplier_contact', $data['supplierContact'])
            ->latest('created_at')
            ->first();

        if (!$invitation) {
            return response()->json(['data' => null]);
        }

        $status = match (true) {
            $invitation->used_at !== null => 'used',
            $invitation->replaced_by !== null => 'replaced',
            $invitation->expires_at !== null && $invitation->expires_at->isPast() => 'expired',
            default => 'active',
        };

        return response()->json(['data' => [
            'token'        => $invitation->id,
            'projectTitle' => $invitation->project->title,
            'supplierName' => $invitation->supplier_name,
            'supplierContact' => $invitation->supplier_contact,
            'status'       => $status,
            'createdAt'    => $invitation->created_at?->format('Y-m-d H:i'),
            'expiresAt'    => $invitation->expires_at?->format('Y-m-d H:i'),
        ]]);
    }

    public function publicInfo(Request $request, string $token)
    {
        $invitation = SupplierInvitation::with('project.materials')->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $project = $invitation->project;

        $this->logPublicAccess($request, 'invitation.view', "Invitación: {$token} / Proveedor: {$invitation->supplier_name}", $project);

        return response()->json([
            'supplierName'    => $invitation->supplier_name,
            'supplierCompany' => $invitation->supplier_company,
            'supplierContact' => $invitation->supplier_contact,
            'project'         => [
                'id'          => $project->id,
                'title'       => $project->title,
                'location'    => $project->location,
                'type'        => $project->type,
                'description' => $project->description,
                'materials'   => $project->materials->map(fn ($m) => [
                    'id'                 => $m->id,
                    'name'               => $m->name,
                    'quantity'           => $m->quantity,
                    'unit'               => $m->unit,
                    'estimatedUnitPrice' => $m->estimated_unit_price,
                ]),
            ],
        ]);
    }
}
