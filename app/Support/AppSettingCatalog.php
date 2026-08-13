<?php

namespace App\Support;

/**
 * Fuente de verdad de `label`/`description` para cada AppSetting — antes
 * vivían como columnas mutables en `app_settings`, sembradas por migraciones
 * y sin ningún otro punto de edición. Un texto que describe el
 * comportamiento de la app (qué hace cada parámetro) es documentación
 * versionada, no un dato de negocio: pertenece al código, revisable en PR,
 * igual que ya se hizo con `NotificationCatalog` y `Roles`.
 *
 * `AppSetting` expone `label`/`description` como accessors que leen de acá
 * (ver `App\Models\AppSetting::getLabelAttribute()`) — la tabla solo guarda
 * el dato realmente configurable (`key`, `value`, `type`, `min_value`,
 * `max_value`). Si una key no está en este catálogo, el accessor cae a la
 * propia `key` como label y `null` como description, para no romper si
 * algún día queda una fila huérfana.
 */
class AppSettingCatalog
{
    /** @var array<string, array{label: string, description: ?string}> */
    private const ENTRIES = [
        'moneda_base' => [
            'label' => 'Moneda base',
            'description' => 'Moneda en la que se registran los montos por defecto.',
        ],
        'anticipo_maximo_porcentaje' => [
            'label' => 'Anticipo máximo (%)',
            'description' => 'Porcentaje máximo de anticipo permitido en una oferta/propuesta.',
        ],
        'semaforo_umbral_verde' => [
            'label' => 'Semáforo — hasta (%) verde',
            'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra verde.',
        ],
        'semaforo_umbral_amarillo' => [
            'label' => 'Semáforo — hasta (%) amarillo',
            'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra amarillo (por encima del umbral verde).',
        ],
        'semaforo_umbral_naranja' => [
            'label' => 'Semáforo — hasta (%) naranja',
            'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra naranja (por encima del umbral amarillo); superado esto pasa a rojo.',
        ],
        'rating_minimo_proveedor' => [
            'label' => 'Rating mínimo — proveedor',
            'description' => 'Escala mínima de calificación para proveedores.',
        ],
        'rating_maximo_proveedor' => [
            'label' => 'Rating máximo — proveedor',
            'description' => 'Escala máxima de calificación para proveedores.',
        ],
        'razon_social' => [
            'label' => 'Razón social',
            'description' => 'Razón social de la empresa, usada en comprobantes de pago.',
        ],
        'rif' => [
            'label' => 'RIF / número fiscal',
            'description' => 'Número de identificación fiscal de la empresa.',
        ],
        'direccion_fiscal' => [
            'label' => 'Dirección fiscal',
            'description' => 'Dirección fiscal registrada de la empresa.',
        ],
        'alerta_precio_umbral_porcentaje' => [
            'label' => 'Alerta de precio — umbral (%)',
            'description' => 'Porcentaje sobre el promedio histórico a partir del cual un precio se marca como fuera de rango.',
        ],
        'inflacion_referencia_anual_porcentaje' => [
            'label' => 'Inflación de referencia anual (%)',
            'description' => 'Tasa de inflación anual de referencia usada en el análisis de precios.',
        ],
        'cambios_bloqueados' => [
            'label' => 'Bloquear cambios de aplicación',
            'description' => 'Cuando está activo, evita despliegues/cambios no planificados (uso administrativo).',
        ],
        'acciones_con_correo' => [
            'label' => 'Acciones que envían correo',
            'description' => 'Lista de acciones auditadas que además de push y bandeja interna disparan un correo (para no generar spam con cada acción). El detalle de qué rol recibe cada acción vive en la matriz de notificaciones por rol, más abajo.',
        ],
        'acciones_con_notificacion_app' => [
            'label' => 'Acciones que envían notificación (app)',
            'description' => 'Lista de acciones auditadas que generan notificación push y bandeja interna. Por defecto, todas — quite las que no ameriten aviso para no generar ruido. El detalle de qué rol recibe cada acción vive en la matriz de notificaciones por rol, más abajo.',
        ],
        'retencion_notificaciones_dias' => [
            'label' => 'Retención de notificaciones (días)',
            'description' => 'Días que se conservan las notificaciones en la bandeja interna antes de purgarse automáticamente. Purgado destructivo, sin posibilidad de recuperación — rango acotado a 1-7 días.',
        ],
        'polling_notificaciones_segundos' => [
            'label' => 'Frecuencia de consulta de notificaciones (segundos)',
            'description' => 'Cada cuánto la app consulta el servidor por notificaciones nuevas.',
        ],
        'polling_dashboard_segundos' => [
            'label' => 'Frecuencia de actualización del dashboard (segundos)',
            'description' => 'Cada cuánto se refresca el resumen ejecutivo del dashboard de Presidencia.',
        ],
    ];

    public static function label(string $key): string
    {
        return self::ENTRIES[$key]['label'] ?? $key;
    }

    public static function description(string $key): ?string
    {
        return self::ENTRIES[$key]['description'] ?? null;
    }
}
