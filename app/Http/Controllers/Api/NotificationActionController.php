<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationActionRequest;
use App\Http\Resources\NotificationActionResource;
use App\Models\ConfigAuditLog;
use App\Models\NotificationAction;
use App\Support\NotificationCatalog;

/**
 * Administración de metadatos del catálogo de acciones notificables — sin
 * `store`: una acción nueva solo tiene efecto si el código la dispara
 * (AuditLog::record()), así que darla de alta es tarea de desarrollo, no
 * de este panel. Acá solo se edita label/agrupación/criticidad y se
 * activa/desactiva una acción ya existente en el catálogo.
 */
class NotificationActionController extends Controller
{
    public function index()
    {
        $actions = NotificationAction::orderBy('group')->orderBy('key')->get();

        return response()->json(NotificationActionResource::collection($actions));
    }

    public function update(UpdateNotificationActionRequest $request, NotificationAction $notificationAction)
    {
        $data = $request->validated();

        if (isset($data['label']))    $notificationAction->label = $data['label'] !== null ? strip_tags($data['label']) : null;
        if (isset($data['group']))    $notificationAction->group = strip_tags($data['group']);
        if (isset($data['scope']))    $notificationAction->scope = $data['scope'];
        if (isset($data['critical'])) $notificationAction->critical = $data['critical'];
        if (isset($data['isActive'])) $notificationAction->is_active = $data['isActive'];

        $notificationAction->save();
        NotificationCatalog::forget();

        $auditLog = ConfigAuditLog::recordAdminAction('notification_action', 'Modificacion de accion notificable', null, null, "Acción: {$notificationAction->key}");

        return response()->json([
            ...(new NotificationActionResource($notificationAction))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function toggleStatus(NotificationAction $notificationAction)
    {
        $notificationAction->is_active = !$notificationAction->is_active;
        $notificationAction->save();
        NotificationCatalog::forget();

        $details = "Acción: {$notificationAction->key} / Activa: " . ($notificationAction->is_active ? 'sí' : 'no');
        $auditLog = ConfigAuditLog::recordAdminAction('notification_action', 'Activacion/desactivacion de accion notificable', null, null, $details);

        return response()->json([
            'id'       => $notificationAction->id,
            'isActive' => $notificationAction->is_active,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }
}
