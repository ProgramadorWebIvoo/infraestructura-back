<?php

namespace App\Support;

/**
 * Catálogo único de acciones auditables/notificables — fuente de verdad
 * tanto para el selector de tags de CONFIG APP (`acciones_con_correo` /
 * `acciones_con_notificacion_app`, vía GET /settings/notification-actions)
 * como para la futura matriz configurable rol×acción×canal. Antes vivía
 * como `NotificationDispatcher::AUDITABLE_ACTIONS`/`ACTION_LABELS` — se
 * extrae aquí para separar "qué acciones existen" (dato) de "cómo se
 * despachan las notificaciones" (comportamiento), y para que un flujo nuevo
 * (Sprint 4/5/6) se integre agregando una entrada acá sin tocar el
 * dispatcher.
 *
 * Por entrada:
 *   label     texto legible para la UI (si coincide con `key`, se omite de ACTIONS y se muestra tal cual)
 *   group     agrupamiento visual: proyectos|documentos|usuarios|catalogos|proveedores|sistema
 *   scope     'project' (requiere Project asociado) | 'global' (no tiene proyecto)
 *   critical  si es true, la matriz de notificaciones no admite dejarla sin destinatarios en canal app
 */
class NotificationCatalog
{
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
        'Rechazo de petición de obra' => NotificationType::ACCION_REQUERIDA,
        'Solicitud de restablecimiento de contrasena' => NotificationType::INFORMACION,
        'Alta de proveedor' => NotificationType::EXITO,
        'Alta de material' => NotificationType::EXITO,
        'Creacion de usuario' => NotificationType::EXITO,
        'Confirmacion de contratacion' => NotificationType::EXITO,
    ];

    /**
     * @var array<string, array{label: ?string, group: string, scope: string, critical: bool}>
     */
    private const ACTIONS = [
        // Flujo regular de proyectos (AuditLog / visible para Presidencia)
        'Creacion de peticion de obra' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Revision tecnica de calculos y planos' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Confirmacion de presupuesto y envio a licitacion' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Carga de propuesta' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Carga de cuadro comparativo' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Importación automática de propuestas de proveedores' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Eliminacion de propuesta' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Rechazo de cuadro comparativo' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
        'Confirmacion de contratacion' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
        'Liberacion de anticipo' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
        'Liberacion total de fondos' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
        'Reporte de obra finalizada' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Verificacion de finalizacion y calidad de obra' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Evaluacion inteligente de propuestas' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Envio de invitacion a proveedor' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
        'Rechazo de petición de obra' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
        'Reenvío de petición corregida' => ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],

        // Documentos de proyecto
        'Carga de hojas de calculo/cubicaciones' => ['label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
        'Carga de planos de ingenieria' => ['label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
        'Carga de correcciones de peticion rechazada' => ['label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
        'Eliminacion de documento adjunto' => ['label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],

        // Accesos públicos (proveedor, sin autenticar)
        'contractor.register' => ['label' => 'Registro público de proveedor', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],
        'invitation.view' => ['label' => 'Visualización de invitación (proveedor)', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],
        'proposal.submit' => ['label' => 'Envío de propuesta pública (proveedor)', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],

        // Sistema / cuenta
        'Solicitud de restablecimiento de contrasena' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => false],

        // Administración: usuarios
        'Creacion de usuario' => ['label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],
        'Modificacion de usuario' => ['label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],
        'Cambio de rol de usuario' => ['label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => true],
        'Activacion/desactivacion de usuario' => ['label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],

        // Administración: proveedores (panel admin, distinto del registro público)
        'Alta de proveedor' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Modificacion de proveedor' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Activacion/desactivacion de proveedor' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Calificacion de proveedor' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

        // Administración: materiales
        'Alta de material' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Modificacion de material' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Activacion/desactivacion de material' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

        // Administración: configuración de IA (credenciales)
        'Alta de configuracion de IA' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],
        'Modificacion de configuracion de IA' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],
        'Eliminacion de configuracion de IA' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],

        // Administración: CONFIG APP (settings genéricos)
        'Modificacion de configuracion' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => false],

        // Administración: monedas
        'Alta de moneda' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Modificación de moneda' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
        'Cambio de moneda base' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => true],
        'Eliminación de moneda' => ['label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

        // Administración: matriz de notificaciones
        'Modificacion de reglas de notificacion' => ['label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ACTIONS);
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action]['label'] ?? $action;
    }

    public static function group(string $action): ?string
    {
        return self::ACTIONS[$action]['group'] ?? null;
    }

    public static function scope(string $action): ?string
    {
        return self::ACTIONS[$action]['scope'] ?? null;
    }

    public static function isCritical(string $action): bool
    {
        return self::ACTIONS[$action]['critical'] ?? false;
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
        return array_key_exists($action, self::ACTIONS);
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
