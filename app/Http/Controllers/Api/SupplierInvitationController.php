<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SupplierInvitation;
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
            'expires_at'       => now()->addDays(SupplierInvitation::DEFAULT_VALIDITY_DAYS),
        ]);

        return response()->json([
            'token'          => $invitation->id,
            'projectTitle'   => $project->title,
            'supplierName'   => $invitation->supplier_name,
            'supplierContact'=> $invitation->supplier_contact,
            'createdAt'      => $invitation->created_at?->format('Y-m-d H:i'),
            'expiresAt'      => $invitation->expires_at?->format('Y-m-d H:i'),
        ], 201);
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
