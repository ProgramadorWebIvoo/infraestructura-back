<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectTypeRequest;
use App\Http\Requests\UpdateProjectTypeRequest;
use App\Http\Resources\ProjectTypeResource;
use App\Models\ConfigAuditLog;
use App\Models\ProjectType;

class ProjectTypeController extends Controller
{
    public function index()
    {
        $types = ProjectType::orderBy('sort_order')->orderBy('label')->get();

        return response()->json(ProjectTypeResource::collection($types));
    }

    public function store(StoreProjectTypeRequest $request)
    {
        $data = $request->validated();

        $type = ProjectType::create([
            'key'        => strtoupper(strip_tags($data['key'])),
            'label'      => strip_tags($data['label']),
            'is_active'  => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ]);

        $auditLog = ConfigAuditLog::recordAdminAction('project_type', 'Alta de tipo de proyecto', null, null, "Tipo: {$type->label} ({$type->key})");

        return response()->json([
            ...(new ProjectTypeResource($type))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(UpdateProjectTypeRequest $request, ProjectType $projectType)
    {
        $data = $request->validated();

        if (isset($data['label']))     $projectType->label = strip_tags($data['label']);
        if (isset($data['isActive']))  $projectType->is_active = $data['isActive'];
        if (isset($data['sortOrder'])) $projectType->sort_order = $data['sortOrder'];

        $projectType->save();

        $auditLog = ConfigAuditLog::recordAdminAction('project_type', 'Modificacion de tipo de proyecto', null, null, "Tipo: {$projectType->label} ({$projectType->key})");

        return response()->json([
            ...(new ProjectTypeResource($projectType))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function toggleStatus(ProjectType $projectType)
    {
        $projectType->is_active = !$projectType->is_active;
        $projectType->save();

        $details = "Tipo: {$projectType->label} ({$projectType->key}) / Activo: " . ($projectType->is_active ? 'sí' : 'no');
        $auditLog = ConfigAuditLog::recordAdminAction('project_type', 'Activacion/desactivacion de tipo de proyecto', null, null, $details);

        return response()->json([
            'id'       => $projectType->id,
            'isActive' => $projectType->is_active,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /**
     * GET /api/project-types (catálogo) — tipos activos, shape resumido,
     * consumido por el formulario de alta de proyecto.
     */
    public function activeList()
    {
        return ProjectType::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn ($item) => [
                'key'   => $item->key,
                'label' => $item->label,
            ]);
    }
}
