<?php

namespace App\Models;

use App\Services\NotificationDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de todo lo que le compete a administración (SUPERADMIN/ADMIN) —
 * deliberadamente separada de `AuditLog` (flujo regular de obra, visible
 * también para Presidencia). `entity_type` distingue entre un cambio de
 * setting de CONFIG APP ('setting') y una acción administrativa sobre otra
 * entidad ('user', 'contractor', 'material', 'ai_config',
 * 'notification_rule', ...) — una sola tabla para ambos casos, sin crear una
 * tercera tabla de auditoría.
 *
 * `recordAdminAction()`/`recordSettingChange()` disparan
 * NotificationDispatcher::notify() igual que AuditLog::record() hace para
 * el flujo de proyectos (mismo patrón "auditar y notificar son un solo
 * paso") — antes cada controller debía acordarse de llamar notify() aparte,
 * y 3 de 7 no lo hacían (Hallazgo 1, auditoría Fase 0-1): AppSettingController,
 * CurrencyController, NotificationRuleController auditaban en silencio sin
 * notificar a nadie. Moverlo aquí hace que ningún controller futuro pueda
 * volver a olvidarlo.
 */
class ConfigAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'action',
        'setting_id',
        'setting_key',
        'old_value',
        'new_value',
        'user_id',
        'user_name_snapshot',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /** Cambio de valor de un AppSetting — comportamiento original, sin cambios de firma. */
    public static function recordSettingChange(AppSetting $setting, ?string $oldValue, ?string $newValue): self
    {
        $log = static::create([
            'entity_type' => 'setting',
            'action' => $setting->key,
            'setting_id' => $setting->id,
            'setting_key' => $setting->key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            ...static::actorSnapshot(),
        ]);

        NotificationDispatcher::notify(null, 'SISTEMA', 'Modificacion de configuracion', "Configuración \"{$setting->key}\" modificada.");

        return $log;
    }

    /**
     * Acción administrativa sobre una entidad que no es un AppSetting (alta
     * de usuario, cambio de rol, activación de proveedor, configuración de
     * IA, etc.). `$oldValue`/`$newValue` son opcionales — muchas de estas
     * acciones no tienen un "antes/después" de un solo campo (ej. "Creación
     * de usuario"), en cuyo caso el detalle relevante va en `$details` y se
     * guarda en `new_value` para no perderlo.
     *
     * `$notifyAction` es opcional y por defecto igual a `$action` — existe
     * porque algunos llamadores auditan con una clave más específica que la
     * acción real del catálogo de notificaciones (ej.
     * NotificationRuleController audita "notification_rules.{accion}" por
     * cada fila de la matriz, pero todas notifican como una sola acción del
     * catálogo, "Modificacion de reglas de notificacion").
     */
    public static function recordAdminAction(string $entityType, string $action, ?string $oldValue = null, ?string $newValue = null, ?string $details = null, ?string $notifyAction = null): self
    {
        $log = static::create([
            'entity_type' => $entityType,
            'action' => $action,
            'setting_id' => null,
            'setting_key' => null,
            'old_value' => $oldValue,
            'new_value' => $newValue ?? $details,
            ...static::actorSnapshot(),
        ]);

        NotificationDispatcher::notify(null, 'SISTEMA', $notifyAction ?? $action, $details);

        return $log;
    }

    private static function actorSnapshot(): array
    {
        $user = auth()->user();

        return [
            'user_id' => $user?->id,
            'user_name_snapshot' => $user?->name,
            'changed_at' => now(),
        ];
    }

    /**
     * Shape que consumen los endpoints que insertan una entrada recién
     * creada directo en el panel de auditoría del frontend sin re-consultar
     * /config-audit-logs (AppSettingController::update, CurrencyController).
     * Única fuente de este mapeo — antes duplicado en cada controller.
     */
    public function toApiPayload(): array
    {
        return [
            'id' => $this->id,
            'entityType' => $this->entity_type,
            'action' => $this->action,
            'settingKey' => $this->setting_key,
            'oldValue' => $this->old_value,
            'newValue' => $this->new_value,
            'userName' => $this->user_name_snapshot,
            'changedAt' => $this->changed_at->format('Y-m-d H:i'),
        ];
    }
}
