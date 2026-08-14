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
        'proyecto_estancado_umbral_dias' => [
            'label' => 'Obra estancada — umbral (días)',
            'description' => 'Días sin actividad tras los cuales una obra no cerrada aparece como estancada en el dashboard ejecutivo.',
        ],
        'documento_tamano_maximo_mb' => [
            'label' => 'Tamaño máximo por archivo (MB)',
            'description' => 'Peso máximo de cada archivo adjunto en el cierre de obra. Acotado a 40 MB porque es el límite físico del servidor PHP (upload_max_filesize/post_max_size); subirlo por encima requiere cambiar php.ini primero.',
        ],
        'documento_cantidad_maxima_archivos' => [
            'label' => 'Cantidad máxima de archivos por carga',
            'description' => 'Número máximo de archivos que se pueden adjuntar en una sola carga de documentación.',
        ],
        'invitacion_proveedor_vigencia_dias' => [
            'label' => 'Vigencia de invitación a proveedor (días)',
            'description' => 'Días que un enlace público de invitación a proveedor permanece válido. Es un token accesible sin autenticación: a mayor vigencia, mayor ventana de exposición. Rango acotado a 1-30 días.',
        ],
        'sesion_inactividad_minutos' => [
            'label' => 'Cierre de sesión por inactividad (minutos)',
            'description' => 'Minutos de inactividad tras los cuales la aplicación cierra la sesión en el navegador. Es un control del cliente: no revoca el token en el servidor, cuya expiración se define por SANCTUM_EXPIRATION en el entorno.',
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

    /**
     * Todas las keys documentadas en el catálogo — usado por
     * AppSettingController::index() para detectar filas de app_settings
     * ausentes en BD (ej. una migración de seed que no corrió) y exponerlo
     * como `missing` en la respuesta, en vez de que el campo simplemente
     * no aparezca en el panel sin ningún rastro.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    /**
     * Keys documentadas en el catálogo sin fila en app_settings — la
     * reconciliación catálogo-vs-BD es responsabilidad de este catálogo,
     * no de cada controller que necesite el dato (ver
     * AppSettingController::index()).
     *
     * @param array<int, string> $seededKeys keys actualmente en app_settings
     * @return array<int, string>
     */
    public static function missingFrom(array $seededKeys): array
    {
        return array_values(array_diff(self::keys(), $seededKeys));
    }
}
