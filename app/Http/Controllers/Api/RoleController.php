<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\ConfigAuditLog;
use App\Models\Role;
use App\Support\Roles;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::orderBy('sort_order')->orderBy('label')->get();

        return response()->json(RoleResource::collection($roles));
    }

    public function store(StoreRoleRequest $request)
    {
        $data = $request->validated();

        $role = Role::create([
            'key'        => strtoupper(strip_tags($data['key'])),
            'label'      => strip_tags($data['label']),
            'is_active'  => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ]);
        Roles::forget();

        $auditLog = ConfigAuditLog::recordAdminAction('role', 'Alta de rol', null, null, "Rol: {$role->label} ({$role->key})");

        return response()->json([
            ...(new RoleResource($role))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $data = $request->validated();

        if (isset($data['label']))     $role->label = strip_tags($data['label']);
        if (isset($data['isActive']))  $role->is_active = $data['isActive'];
        if (isset($data['sortOrder'])) $role->sort_order = $data['sortOrder'];

        $role->save();
        Roles::forget();

        $auditLog = ConfigAuditLog::recordAdminAction('role', 'Modificacion de rol', null, null, "Rol: {$role->label} ({$role->key})");

        return response()->json([
            ...(new RoleResource($role))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function toggleStatus(Role $role)
    {
        $role->is_active = !$role->is_active;
        $role->save();
        Roles::forget();

        $details = "Rol: {$role->label} ({$role->key}) / Activo: " . ($role->is_active ? 'sí' : 'no');
        $auditLog = ConfigAuditLog::recordAdminAction('role', 'Activacion/desactivacion de rol', null, null, $details);

        return response()->json([
            'id'       => $role->id,
            'isActive' => $role->is_active,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }
}
