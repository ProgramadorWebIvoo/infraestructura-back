<?php

namespace App\Support;

use App\Models\NotificationAction;
use Illuminate\Support\Facades\Cache;

/**
 * Catálogo único de acciones auditables/notificables — fuente de verdad
 * tanto para el selector de tags de CONFIG APP (`acciones_con_correo` /
 * `acciones_con_notificacion_app`, vía GET /settings/notification-actions)
 * como para la matriz configurable rol×acción×canal.
 *
 * El metadato (label/group/scope/critical/is_active) vive en la tabla
 * `notification_actions` — lee con cache (mismo patrón que SettingsService/
 * Roles), invalidado al escribir desde el panel de administración. El
 * DISPARO real de cada acción (dónde en el código se llama
 * AuditLog::record()) sigue siendo código: este catálogo no crea acciones
 * nuevas por sí solo, solo administra los metadatos de las que ya existen —
 * por eso no hay alta desde el panel, solo edición y activo/inactivo.
 *
 * `keys()`/`toOptions()`/`toDetailedOptions()` solo devuelven acciones
 * activas (para no ofrecer acciones deprecadas en selectores nuevos);
 * `exists()`/`label()`/`group()`/`scope()`/`isCritical()`/`type()` resuelven
 * cualquier acción del catálogo (activa o no), porque el histórico de
 * auditoría puede tener entradas ya desactivadas que igual hay que mostrar.
 */
class NotificationCatalog
{
    private const CACHE_KEY = 'notification_actions.all';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Acciones cuyo NotificationType no se deriva del default binario de
     * `critical` (ver type()) — "Rechazo de cuadro comparativo" exige que
     * alguien corrija algo (accion_requerida), mientras que el resto de
     * acciones `critical` son eventos ya consumados que solo requieren
     * atención (prioritario), y "Solicitud de restablecimiento de
     * contrasena" es un flujo de sistema esperado, no una alerta (informacion).
     */
    private const TYPE_OVERRIDES = [
        'Rechazo de cuadro comparativo' => NotificationType::ACCION_REQUERIDA,
        'Rechazo de adjudicacion por Presidencia' => NotificationType::ACCION_REQUERIDA,
        'Aprobacion de adjudicacion por Presidencia' => NotificationType::EXITO,
        'Rechazo de petición de obra' => NotificationType::ACCION_REQUERIDA,
        'Rechazo de informe de cierre por residente' => NotificationType::ACCION_REQUERIDA,
        'Rechazo de informe de cierre por Auditoria' => NotificationType::ACCION_REQUERIDA,
        'Devolucion de finiquito a Auditoria' => NotificationType::ACCION_REQUERIDA,
        'Solicitud de pago de finiquito' => NotificationType::ACCION_REQUERIDA,
        'Solicitud de reevaluación a Auditoría' => NotificationType::ACCION_REQUERIDA,
        'Rechazo de propuesta de marketing' => NotificationType::ACCION_REQUERIDA,
        'Solicitud de restablecimiento de contrasena' => NotificationType::INFORMACION,
        'Alta de proveedor' => NotificationType::EXITO,
        'Alta de material' => NotificationType::EXITO,
        'Creacion de usuario' => NotificationType::EXITO,
        'Confirmacion de contratacion' => NotificationType::EXITO,
        'Obra sin actividad reciente' => NotificationType::ADVERTENCIA,
        'Sobre-ejecucion de presupuesto' => NotificationType::ADVERTENCIA,
        'Invitacion a proveedor proxima a vencer' => NotificationType::ADVERTENCIA,
        'Racha de rechazos detectada' => NotificationType::ADVERTENCIA,
    ];

    /** @return array<string, array{label: ?string, group: string, scope: string, critical: bool, is_active: bool}> keyed por action */
    private static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return NotificationAction::all()
                ->mapWithKeys(fn (NotificationAction $a) => [$a->key => [
                    'label' => $a->label,
                    'group' => $a->group,
                    'scope' => $a->scope,
                    'critical' => $a->critical,
                    'is_active' => $a->is_active,
                ]])
                ->toArray();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return string[] keys de las acciones activas */
    public static function keys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $a) => $a['is_active']));
    }

    public static function label(string $action): string
    {
        return self::all()[$action]['label'] ?? $action;
    }

    public static function group(string $action): ?string
    {
        return self::all()[$action]['group'] ?? null;
    }

    public static function scope(string $action): ?string
    {
        return self::all()[$action]['scope'] ?? null;
    }

    public static function isCritical(string $action): bool
    {
        return self::all()[$action]['critical'] ?? false;
    }

    /**
     * Tipo de notificación (taxonomía de 6 valores, ver NotificationType) a
     * usar por defecto para esta acción. `TYPE_OVERRIDES` cubre los casos
     * donde el binario `critical` no basta para elegir el tipo correcto;
     * el resto se deriva: critical=true -> prioritario, critical=false ->
     * informacion.
     */
    public static function type(string $action): string
    {
        return self::TYPE_OVERRIDES[$action]
            ?? (self::isCritical($action) ? NotificationType::PRIORITARIO : NotificationType::INFORMACION);
    }

    public static function exists(string $action): bool
    {
        return array_key_exists($action, self::all());
    }

    /** @return array{value: string, label: string}[] */
    public static function toOptions(): array
    {
        return array_map(
            fn (string $action) => ['value' => $action, 'label' => self::label($action)],
            self::keys(),
        );
    }

    /**
     * Versión extendida para la matriz de notificaciones — agrega `group` y
     * `critical`, que `toOptions()` no expone (usado también por el
     * selector de tags de acciones_con_correo/acciones_con_notificacion_app,
     * que no necesita esos campos).
     */
    public static function toDetailedOptions(): array
    {
        return array_map(
            fn (string $action) => [
                'value' => $action,
                'label' => self::label($action),
                'group' => self::group($action),
                'critical' => self::isCritical($action),
            ],
            self::keys(),
        );
    }
}
