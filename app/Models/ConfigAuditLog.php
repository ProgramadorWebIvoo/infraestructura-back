<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de todo lo que le compete a administración (SUPERADMIN/ADMIN) —
 * deliberadamente separada de `AuditLog` (flujo regular de obra, visible
 * también para Presidencia). `entity_type` distingue entre un cambio de
 * setting de CONFIG APP ('setting') y una acción administrativa sobre otra
 * entidad ('user', 'contractor', 'material', 'ai_config',
 * 'notification_rule', ...) — una sola tabla para ambos casos, sin crear una
 * tercera tabla de auditoría.
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
        return static::create([
            'entity_type' => 'setting',
            'action' => $setting->key,
            'setting_id' => $setting->id,
            'setting_key' => $setting->key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            ...static::actorSnapshot(),
        ]);
    }

    /**
     * Acción administrativa sobre una entidad que no es un AppSetting (alta
     * de usuario, cambio de rol, activación de proveedor, configuración de
     * IA, etc.). `$oldValue`/`$newValue` son opcionales — muchas de estas
     * acciones no tienen un "antes/después" de un solo campo (ej. "Creación
     * de usuario"), en cuyo caso el detalle relevante va en `$details` y se
     * guarda en `new_value` para no perderlo.
     */
    public static function recordAdminAction(string $entityType, string $action, ?string $oldValue = null, ?string $newValue = null, ?string $details = null): self
    {
        return static::create([
            'entity_type' => $entityType,
            'action' => $action,
            'setting_id' => null,
            'setting_key' => null,
            'old_value' => $oldValue,
            'new_value' => $newValue ?? $details,
            ...static::actorSnapshot(),
        ]);
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
}
